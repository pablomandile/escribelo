<?php

namespace App\Jobs;

use App\Models\Transcription;
use App\Models\TranscriptionFile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            'progress' => 0,
            'error_message' => null,
        ]);

        $audioPath = $file->absolutePath();
        $outputRelativePath = 'transcripts/'.$file->id.'.json';
        $outputPath = Storage::disk('local')->path($outputRelativePath);

        Storage::disk('local')->makeDirectory('transcripts');

        $cleanedRelativePath = 'cleaned/'.$file->id.'.mp3';
        $cleanedAbsolutePath = Storage::disk('local')->path($cleanedRelativePath);
        if ($file->clean_audio) {
            Storage::disk('local')->makeDirectory('cleaned');
        }

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
            ...($file->clean_audio ? ['--clean-audio', '--cleaned-output', $cleanedAbsolutePath] : []),
        ], base_path(), [
            'PYTHONIOENCODING' => 'utf-8',
        ], null, (float) config('transcription.timeout', 3600));

        $stdoutBuffer = '';
        $process->start();
        $file->forceFill(['worker_pid' => $process->getPid()])->saveQuietly();
        $process->wait(function ($type, $buffer) use (&$stdoutBuffer, $file): void {
            if ($type !== Process::OUT) {
                return;
            }
            $stdoutBuffer .= $buffer;
            while (($newlinePos = strpos($stdoutBuffer, "\n")) !== false) {
                $line = trim(substr($stdoutBuffer, 0, $newlinePos));
                $stdoutBuffer = substr($stdoutBuffer, $newlinePos + 1);
                if ($line === '' || $line[0] !== '{') {
                    continue;
                }
                $payload = json_decode($line, true);
                if (! is_array($payload)) {
                    continue;
                }
                if (isset($payload['progress'])) {
                    $progress = max(0, min(100, (int) $payload['progress']));
                    $file->forceFill(['progress' => $progress])->saveQuietly();
                    Log::info('Whisper progress', [
                        'transcription_file_id' => $file->id,
                        'progress' => $progress,
                    ]);
                }
                if (isset($payload['phase'])) {
                    Log::info('Whisper phase: '.$payload['phase'], [
                        'transcription_file_id' => $file->id,
                    ] + $payload);
                }
            }
        });

        if (! $process->isSuccessful()) {
            $errorOutput = trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'Whisper failed.';

            if ($file->fresh() === null) {
                Log::info('Whisper process cancelled (transcription deleted)', [
                    'transcription_file_id' => $file->id,
                    'audio_path' => $audioPath,
                ]);
                return;
            }

            Log::error('Whisper process failed', [
                'transcription_file_id' => $file->id,
                'audio_path' => $audioPath,
                'model' => $file->model,
                'exit_code' => $process->getExitCode(),
                'error_output' => $errorOutput,
            ]);
            throw new \RuntimeException($errorOutput);
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

            $cleanedSavedPath = $file->clean_audio && file_exists($cleanedAbsolutePath)
                ? $cleanedRelativePath
                : null;

            $file->update([
                'status' => 'completed',
                'progress' => 100,
                'worker_pid' => null,
                'cleaned_audio_path' => $cleanedSavedPath,
                'language' => $payload['language'] ?? $file->language,
                'duration_seconds' => $payload['duration'] ?? $this->durationFromSegments($segments),
                'processed_at' => now(),
                'error_message' => null,
            ]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        $message = $exception?->getMessage();

        Log::error('Transcription job failed', [
            'transcription_file_id' => $this->transcriptionFile->id,
            'message' => $message,
            'exception' => $exception ? get_class($exception) : null,
            'trace' => $exception?->getTraceAsString(),
        ]);

        $this->transcriptionFile->fresh()?->update([
            'status' => 'failed',
            'error_message' => $message,
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
