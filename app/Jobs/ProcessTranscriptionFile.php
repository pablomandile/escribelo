<?php

namespace App\Jobs;

use App\Models\Transcription;
use App\Models\TranscriptionFile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

class ProcessTranscriptionFile implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(public TranscriptionFile $transcriptionFile)
    {
        $this->timeout = (int) config('transcription.timeout', 3600);
    }

    public function handle(): void
    {
        $file = $this->transcriptionFile->fresh();

        if (! $file) {
            return;
        }

        $file->update([
            'status' => 'processing',
            'error_message' => null,
        ]);

        $audioPath = $file->absolutePath();
        $outputRelativePath = 'transcripts/'.$file->id.'.json';
        $outputPath = Storage::disk('local')->path($outputRelativePath);

        Storage::disk('local')->makeDirectory('transcripts');

        $process = new Process([
            config('transcription.python', 'python'),
            base_path('whisper_worker/transcribe.py'),
            '--file',
            $audioPath,
            '--output',
            $outputPath,
            '--model',
            $file->model ?: config('transcription.model', 'small'),
            ...($file->language ? ['--language', $file->language] : []),
        ], base_path(), [
            'PYTHONIOENCODING' => 'utf-8',
        ], null, (float) config('transcription.timeout', 3600));

        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'Whisper failed.');
        }

        $payload = json_decode(file_get_contents($outputPath), true, flags: JSON_THROW_ON_ERROR);
        $segments = $payload['segments'] ?? [];

        DB::transaction(function () use ($file, $payload, $segments): void {
            $transcription = Transcription::updateOrCreate(
                ['transcription_file_id' => $file->id],
                [
                    'text' => $payload['text'] ?? '',
                    'metadata' => [
                        'engine' => $payload['engine'] ?? null,
                        'model' => $payload['model'] ?? $file->model,
                    ],
                ],
            );

            $transcription->segments()->delete();

            foreach ($segments as $index => $segment) {
                $transcription->segments()->create([
                    'position' => $index,
                    'start_seconds' => (float) ($segment['start'] ?? 0),
                    'end_seconds' => (float) ($segment['end'] ?? 0),
                    'text' => trim((string) ($segment['text'] ?? '')),
                ]);
            }

            $file->update([
                'status' => 'completed',
                'language' => $payload['language'] ?? $file->language,
                'duration_seconds' => $payload['duration'] ?? $this->durationFromSegments($segments),
                'processed_at' => now(),
                'error_message' => null,
            ]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        $this->transcriptionFile->fresh()?->update([
            'status' => 'failed',
            'error_message' => $exception?->getMessage(),
        ]);
    }

    private function durationFromSegments(array $segments): ?float
    {
        $lastSegment = end($segments);

        return is_array($lastSegment) && isset($lastSegment['end'])
            ? (float) $lastSegment['end']
            : null;
    }
}
