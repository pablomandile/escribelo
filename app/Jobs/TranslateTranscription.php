<?php

namespace App\Jobs;

use App\Models\AppSetting;
use App\Models\TranscriptionTranslation;
use App\Services\Translator\OllamaTranslator;
use App\Services\Translator\RemoteTranslator;
use App\Services\Translator\TranslationPrompt;
use App\Services\Translator\TranslatorException;
use App\Services\Translator\TranslatorInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Traduce la transcripción con Gemma 4 en Ollama (local o en la PC por el túnel).
 *
 * Va de a trozos chicos y guarda el texto traducido después de cada uno: la
 * pantalla lo muestra a medida que avanza, y si algo falla a la mitad queda lo
 * ya traducido. Entre trozo y trozo mira si el usuario canceló.
 *
 * Tiempo de vida del proceso: en Hostinger, hPanel mata el cron de la cola a
 * los 30 minutos (`timeout -s 9 1800`), y la traducción de un audio de dos horas
 * tarda más que eso. Con QUEUE_PROCESS_TIME_BUDGET (segundos) el job mira cuánto
 * lleva vivo el proceso de `queue:work`; si no le alcanza para otro trozo, guarda
 * el avance y se vuelve a encolar CON DEMORA. La demora importa: con
 * `--stop-when-empty` el worker ve la cola vacía, termina solo, y el cron del
 * minuto siguiente arranca un proceso nuevo que sigue desde ese trozo.
 */
class TranslateTranscription implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    /** Segundos que espera la continuación, para que el worker actual termine. */
    private const CONTINUE_DELAY = 60;

    /** Lo que se asume que tarda un trozo hasta medir el primero. */
    private const DEFAULT_CHUNK_SECONDS = 150;

    /** Solo para tests: reemplaza a LARAVEL_START como arranque del proceso. */
    public static ?float $processStartedAt = null;

    public function __construct(
        public TranscriptionTranslation $translation,
        public int $fromChunk = 0,
    ) {
    }

    public function handle(): void
    {
        $translation = $this->translation->fresh();
        if (! $translation || $translation->status === 'failed') {
            // Borrada, o cancelada mientras esperaba en la cola.
            return;
        }

        $transcription = $translation->transcription;
        $file = $transcription?->file;
        if (! $transcription || ! $file) {
            return;
        }

        $provider = AppSetting::get('mode', 'local') === 'host' ? 'remote' : 'ollama';
        $translator = $this->makeTranslator($provider);
        $chunks = TranslationPrompt::chunk($transcription->effectiveText());
        $total = count($chunks);

        if ($total === 0) {
            $this->markFailed($translation, 'La transcripción no tiene texto para traducir.');

            return;
        }

        $continuing = $this->fromChunk > 0;
        $previous = $continuing ? ($translation->metadata ?? []) : [];
        $parts = $continuing && trim((string) $translation->text) !== '' ? [$translation->text] : [];
        $tokens = (int) ($previous['tokens_used'] ?? 0);
        $workedBefore = (int) ($previous['working_seconds'] ?? 0);
        $model = $previous['model'] ?? null;

        $translation->update([
            'status' => 'processing',
            'text' => $continuing ? $translation->text : '',
            'metadata' => array_merge($previous, [
                'provider' => $provider,
                'progress' => ['chunk' => $this->fromChunk, 'total' => $total],
                'error' => null,
            ]),
        ]);

        $startedAt = microtime(true);
        $slowestChunk = self::DEFAULT_CHUNK_SECONDS;
        $measured = false;

        try {
            for ($i = $this->fromChunk; $i < $total; $i++) {
                if ($this->wasCancelled($translation)) {
                    return;
                }

                if ($this->outOfProcessTime($measured ? $slowestChunk * 1.5 : self::DEFAULT_CHUNK_SECONDS)) {
                    $this->continueLater($translation, $i, $total, $tokens, $model, $workedBefore + (int) round(microtime(true) - $startedAt));

                    return;
                }

                $translation->forceFill([
                    'metadata' => array_merge($translation->metadata ?? [], [
                        'progress' => ['chunk' => $i + 1, 'total' => $total],
                    ]),
                ])->saveQuietly();

                $chunkStartedAt = microtime(true);
                $result = $translator->translateChunk($chunks[$i], $translation->language, $file->language);
                $chunkSeconds = microtime(true) - $chunkStartedAt;
                $slowestChunk = $measured ? max($slowestChunk, $chunkSeconds) : $chunkSeconds;
                $measured = true;

                $parts[] = $result['text'];
                $tokens += $result['tokens_used'];
                $model = $result['model'];

                if ($this->wasCancelled($translation)) {
                    return;
                }

                // Avance visible: lo traducido hasta acá.
                $translation->forceFill([
                    'text' => implode("\n\n", $parts),
                    'metadata' => array_merge($translation->metadata ?? [], [
                        'tokens_used' => $tokens,
                        'model' => $model,
                    ]),
                ])->saveQuietly();
            }

            $working = $workedBefore + (int) round(microtime(true) - $startedAt);
            $translation->update([
                'text' => implode("\n\n", $parts),
                'status' => 'completed',
                'generated_at' => now(),
                'metadata' => [
                    'provider' => $provider,
                    'model' => $model,
                    'tokens_used' => $tokens,
                    'chunks' => $total,
                    'working_seconds' => $working,
                    'elapsed_seconds' => $working,
                ],
            ]);
        } catch (TranslatorException $e) {
            Log::error('Translation failed', [
                'translation_id' => $translation->id,
                'language' => $translation->language,
                'provider' => $provider,
                'message' => $e->getMessage(),
            ]);
            $this->markFailed($translation, $e->getMessage());
        }
    }

    public function failed(?Throwable $exception): void
    {
        $translation = $this->translation->fresh();
        if ($translation && $translation->status !== 'completed') {
            $this->markFailed($translation, $exception?->getMessage() ?: 'La traducción se interrumpió.');
        }
    }

    /**
     * ¿Le queda al proceso de `queue:work` menos de $needed segundos de su
     * presupuesto? LARAVEL_START lo define `artisan` al arrancar el proceso, así
     * que cuenta también lo que tardaron los trabajos anteriores del mismo cron.
     */
    private function outOfProcessTime(float $needed): bool
    {
        $budget = (int) config('queue.process_time_budget', 0);
        $processStart = self::$processStartedAt ?? (defined('LARAVEL_START') ? LARAVEL_START : null);
        if ($budget <= 0 || $processStart === null) {
            return false;
        }

        $alive = microtime(true) - $processStart;

        return $budget - $alive < $needed;
    }

    private function continueLater(TranscriptionTranslation $translation, int $nextChunk, int $total, int $tokens, ?string $model, int $working): void
    {
        $translation->forceFill([
            'status' => 'queued',
            'metadata' => array_merge($translation->metadata ?? [], [
                'progress' => ['chunk' => $nextChunk, 'total' => $total],
                'tokens_used' => $tokens,
                'model' => $model,
                'working_seconds' => $working,
            ]),
        ])->save();

        Log::info('Translation continues in a new worker process', [
            'translation_id' => $translation->id,
            'next_chunk' => $nextChunk,
            'total' => $total,
        ]);

        self::dispatch($translation, $nextChunk)->delay(now()->addSeconds(self::CONTINUE_DELAY));
    }

    private function wasCancelled(TranscriptionTranslation $translation): bool
    {
        $fresh = $translation->fresh();
        if (! $fresh || $fresh->status === 'failed') {
            Log::info('Translation aborted (cancelled or deleted)', ['translation_id' => $translation->id]);

            return true;
        }

        return false;
    }

    private function markFailed(TranscriptionTranslation $translation, string $error): void
    {
        $translation->update([
            'status' => 'failed',
            'metadata' => array_merge($translation->metadata ?? [], ['error' => $error]),
        ]);
    }

    private function makeTranslator(string $provider): TranslatorInterface
    {
        return $provider === 'remote'
            ? app(RemoteTranslator::class)
            : app(OllamaTranslator::class);
    }
}
