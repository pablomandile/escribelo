<?php

namespace App\Jobs;

use App\Models\AppSetting;
use App\Models\GroqUsage;
use App\Models\Transcription;
use App\Services\Summarizer\GroqSummarizer;
use App\Services\Summarizer\OllamaSummarizer;
use App\Services\Summarizer\RemoteSummarizer;
use App\Services\Summarizer\SummarizerException;
use App\Services\Summarizer\SummarizerInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SummarizeTranscription implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(public Transcription $transcription)
    {
    }

    public function handle(): void
    {
        $transcription = $this->transcription->fresh();
        if (! $transcription) {
            return;
        }

        // Si el usuario canceló mientras el job estaba en cola, abortamos sin tocar nada.
        if ($transcription->summary_status === 'failed') {
            Log::info('Summary job aborted (cancelled by user)', [
                'transcription_id' => $transcription->id,
            ]);
            return;
        }

        $file = $transcription->file;
        if (! $file) {
            return;
        }

        $provider = $this->resolveProvider();
        $summarizer = $this->makeSummarizer($provider);

        $transcription->update([
            'summary_status' => 'processing',
            'summary_metadata' => array_merge($transcription->summary_metadata ?? [], [
                'provider' => $provider,
            ]),
        ]);

        $startedAt = microtime(true);

        try {
            $result = $summarizer->summarize(
                $transcription->effectiveText(),
                $file->language,
                function (array $progress) use ($transcription) {
                    $transcription->forceFill([
                        'summary_metadata' => array_merge($transcription->summary_metadata ?? [], [
                            'progress' => $progress,
                            'last_heartbeat' => now()->toIso8601String(),
                        ]),
                    ])->saveQuietly();
                },
            );

            // Si el usuario canceló mientras llamábamos al LLM, descartamos el resultado.
            if ($transcription->fresh()?->summary_status === 'failed') {
                Log::info('Summary discarded (cancelled mid-flight)', [
                    'transcription_id' => $transcription->id,
                ]);
                return;
            }

            $transcription->update([
                'summary' => $result['summary'],
                'summary_metadata' => [
                    'key_points' => $result['key_points'],
                    'model' => $result['model'],
                    'tokens_used' => $result['tokens_used'],
                    'provider' => $provider,
                    'elapsed_seconds' => (int) round(microtime(true) - $startedAt),
                ],
                'summary_status' => 'completed',
                'summary_generated_at' => now(),
            ]);

            if ($provider === 'groq') {
                GroqUsage::recordCall((int) $file->user_id, (int) $result['tokens_used']);
            }
        } catch (SummarizerException $e) {
            Log::error('Summarizer failed', [
                'transcription_id' => $transcription->id,
                'provider' => $provider,
                'message' => $e->getMessage(),
            ]);
            $transcription->update([
                'summary_status' => 'failed',
                'summary_metadata' => array_merge($transcription->summary_metadata ?? [], [
                    'error' => $e->getMessage(),
                    'provider' => $provider,
                ]),
            ]);
        }
    }

    private function resolveProvider(): string
    {
        // Global app mode decides where summaries are computed.
        // Local → Ollama on the same machine.
        // Host  → Remote worker (Ollama via Cloudflare Tunnel).
        $mode = AppSetting::get('mode', 'local');
        return $mode === 'host' ? 'remote' : 'ollama';
    }

    private function makeSummarizer(string $provider): SummarizerInterface
    {
        return match ($provider) {
            'remote' => app(RemoteSummarizer::class),
            'groq'   => app(GroqSummarizer::class),
            default  => app(OllamaSummarizer::class),
        };
    }

    public function failed(?Throwable $exception): void
    {
        $this->transcription->fresh()?->update([
            'summary_status' => 'failed',
            'summary_metadata' => array_merge($this->transcription->summary_metadata ?? [], [
                'error' => $exception?->getMessage(),
            ]),
        ]);
    }
}
