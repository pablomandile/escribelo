<?php

namespace App\Console\Commands;

use App\Services\Summarizer\OllamaSummarizer;
use App\Services\Translator\OllamaTranslator;
use App\Services\Translator\TranslationPrompt;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Console\Command;

/**
 * Arma database/demo/fixture.json pasando los audios de la demo por el pipeline
 * real: Whisper en el worker de la PC (GPU), resumen y traducción con Gemma 4 en
 * el Ollama local. Se corre UNA vez en la PC, cuando cambian los audios o los
 * prompts; el reset nocturno de la demo solo carga el fixture y no llama a
 * ninguna IA (así una noche con la PC apagada no deja la demo vacía).
 *
 * No toca la base de datos. Leé el fixture antes de commitearlo: es lo que ve
 * cualquiera que entre a la demo.
 */
class DemoArmar extends Command
{
    protected $signature = 'escribelo:demo-armar
        {--worker=http://127.0.0.1:8765 : URL del worker de Whisper}
        {--token= : Token del worker (por defecto REMOTE_WORKER_TOKEN)}
        {--model=gemma4:e4b : Modelo de Ollama para resumir y traducir}
        {--only= : Rehacer solo este slug y conservar el resto del fixture}
        {--solo-resumen : Rehacer solo el resumen (reusa la transcripción y las traducciones del fixture)}';

    protected $description = 'Genera el contenido pregrabado de la demo (database/demo/fixture.json) con el pipeline real';

    public function handle(): int
    {
        $dir = database_path('demo');
        $guion = json_decode(file_get_contents($dir.'/guion.json'), true, flags: JSON_THROW_ON_ERROR);
        $fixturePath = $dir.'/fixture.json';
        $existing = is_file($fixturePath)
            ? collect(json_decode(file_get_contents($fixturePath), true)['files'] ?? [])->keyBy('slug')
            : collect();

        config([
            'services.ollama.summary_model' => $this->option('model'),
            'services.ollama.translate_model' => $this->option('model'),
        ]);

        $only = $this->option('only');
        $files = [];

        foreach ($guion['audios'] as $audio) {
            if ($only && $audio['slug'] !== $only) {
                if ($existing->has($audio['slug'])) {
                    $files[] = $existing[$audio['slug']];
                }

                continue;
            }

            $this->info("== {$audio['slug']}");
            $files[] = $this->option('solo-resumen') && $existing->has($audio['slug'])
                ? $this->rebuildSummary($existing[$audio['slug']], $audio)
                : $this->build($dir, $audio);
        }

        file_put_contents($fixturePath, json_encode([
            '_comentario' => 'Generado por `php artisan escribelo:demo-armar`. No editar a mano: cambiá guion.json y volvé a generarlo.',
            'generated_at' => now()->toIso8601String(),
            'files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        $this->info('Listo: '.$fixturePath);

        return self::SUCCESS;
    }

    private function build(string $dir, array $audio): array
    {
        $audioPath = $dir.'/audio/'.$audio['slug'].'.mp3';
        if (! is_file($audioPath)) {
            throw new \RuntimeException("Falta {$audioPath}: corré database/demo/generar-audios.ps1");
        }

        // 1) Transcripción con Whisper en el worker.
        $startedAt = microtime(true);
        $result = $this->transcribe($audioPath, $audio);
        $processingSeconds = (int) max(1, round(microtime(true) - $startedAt));
        $text = trim((string) ($result['text'] ?? ''));
        $segments = collect($result['segments'] ?? [])
            ->map(fn ($s) => [round((float) $s['start'], 3), round((float) $s['end'], 3), trim((string) $s['text'])])
            ->filter(fn ($s) => $s[2] !== '')
            ->values()
            ->all();
        $this->line("   transcripción: {$processingSeconds} s, ".count($segments).' segmentos, '.mb_strlen($text).' caracteres');

        // 2) Resumen con Gemma (mismo summarizer que el modo local de la app).
        $startedAt = microtime(true);
        $summary = app(OllamaSummarizer::class)->summarize($text, $audio['language']);
        $summaryElapsed = (int) round(microtime(true) - $startedAt);
        $this->line("   resumen: {$summaryElapsed} s");

        // 3) Traducciones, de a trozos como el job.
        $translations = [];
        foreach ($audio['translate'] as $target) {
            $startedAt = microtime(true);
            $parts = [];
            $tokens = 0;
            $model = null;
            foreach (TranslationPrompt::chunk($text) as $chunk) {
                $r = app(OllamaTranslator::class)->translateChunk($chunk, $target, $audio['language']);
                $parts[] = $r['text'];
                $tokens += $r['tokens_used'];
                $model = $r['model'];
            }
            $elapsed = (int) round(microtime(true) - $startedAt);
            $translations[] = [
                'language' => $target,
                'text' => implode("\n\n", $parts),
                'metadata' => ['provider' => 'remote', 'model' => $model, 'tokens_used' => $tokens, 'elapsed_seconds' => $elapsed],
            ];
            $this->line("   traducción {$target}: {$elapsed} s");
        }

        return [
            'slug' => $audio['slug'],
            'audio' => 'audio/'.$audio['slug'].'.mp3',
            'original_name' => $audio['original_name'],
            'folder' => $audio['folder'],
            'language' => $result['language'] ?? $audio['language'],
            'model' => $audio['model'],
            'days_ago' => $audio['days_ago'],
            'duration_seconds' => $result['duration'] ?? (count($segments) ? end($segments)[1] : null),
            'processing_seconds' => $processingSeconds,
            'transcription' => [
                'text' => $text,
                'metadata' => ['engine' => $result['engine'] ?? null, 'model' => $result['model'] ?? $audio['model']],
                'segments' => $segments,
            ],
            'summary' => [
                'text' => $summary['summary'],
                'metadata' => [
                    'key_points' => $summary['key_points'],
                    'model' => $summary['model'],
                    'tokens_used' => $summary['tokens_used'],
                    'provider' => 'remote',
                    'elapsed_seconds' => $summaryElapsed,
                ],
            ],
            'translations' => $translations,
        ];
    }

    private function rebuildSummary(array $file, array $audio): array
    {
        $startedAt = microtime(true);
        $summary = app(OllamaSummarizer::class)->summarize($file['transcription']['text'], $audio['language']);
        $elapsed = (int) round(microtime(true) - $startedAt);
        $this->line("   resumen: {$elapsed} s");

        $file['summary'] = [
            'text' => $summary['summary'],
            'metadata' => [
                'key_points' => $summary['key_points'],
                'model' => $summary['model'],
                'tokens_used' => $summary['tokens_used'],
                'provider' => 'remote',
                'elapsed_seconds' => $elapsed,
            ],
        ];

        return $file;
    }

    /** POST /transcribe al worker, igual que RemoteApiTranscriber: NDJSON y la última línea con "result". */
    private function transcribe(string $audioPath, array $audio): array
    {
        $token = $this->option('token') ?: (string) config('services.remote_worker.token');
        $client = new GuzzleClient(['timeout' => 1800, 'stream' => true]);
        $stream = fopen($audioPath, 'r');

        try {
            $response = $client->request('POST', rtrim($this->option('worker'), '/').'/transcribe', [
                'headers' => ['Authorization' => 'Bearer '.$token],
                'multipart' => [
                    ['name' => 'file', 'contents' => $stream, 'filename' => $audio['slug'].'.mp3'],
                    ['name' => 'model', 'contents' => $audio['model']],
                    ['name' => 'language', 'contents' => $audio['language']],
                    ['name' => 'clean_audio', 'contents' => '0'],
                ],
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $result = null;
        foreach (preg_split('/\r?\n/', (string) $response->getBody()) as $line) {
            $payload = json_decode(trim($line), true);
            if (! is_array($payload)) {
                continue;
            }
            if (isset($payload['error'])) {
                throw new \RuntimeException('Worker: '.$payload['error']);
            }
            if (isset($payload['result'])) {
                $result = $payload['result'];
            }
        }

        if (! is_array($result)) {
            throw new \RuntimeException('El worker no devolvió el resultado de la transcripción.');
        }

        return $result;
    }
}
