<?php

namespace App\Jobs;

use App\Models\GroqUsage;
use App\Models\Transcription;
use App\Services\Summarizer\GroqSummarizer;
use App\Services\Summarizer\OllamaSummarizer;
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

        $file = $transcription->file;
        if (! $file) {
            return;
        }

        $user = $file->user;
        $provider = $user?->getSetting('summary_provider') ?? 'groq';

        $summarizer = $this->makeSummarizer($provider);

        $transcription->update([
            'summary_status' => 'processing',
            'summary_metadata' => array_merge($transcription->summary_metadata ?? [], [
                'provider' => $provider,
            ]),
        ]);

        try {
            $result = $summarizer->summarize(
                (string) $transcription->text,
                $file->language,
            );

            $transcription->update([
                'summary' => $result['summary'],
                'summary_metadata' => [
                    'key_points' => $result['key_points'],
                    'model' => $result['model'],
                    'tokens_used' => $result['tokens_used'],
                    'provider' => $provider,
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

    private function makeSummarizer(string $provider): SummarizerInterface
    {
        return match ($provider) {
            'ollama' => app(OllamaSummarizer::class),
            default => app(GroqSummarizer::class),
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
