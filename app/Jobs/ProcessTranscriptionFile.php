<?php

namespace App\Jobs;

use App\Models\AppSetting;
use App\Models\Transcription;
use App\Models\TranscriptionFile;
use App\Services\Transcriber\LocalProcessTranscriber;
use App\Services\Transcriber\RemoteApiTranscriber;
use App\Services\Transcriber\RemoteWorkerOfflineException;
use App\Services\Transcriber\TranscriberInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessTranscriptionFile implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(public TranscriptionFile $transcriptionFile)
    {
        $this->timeout = AppSetting::whisperTimeout();
    }

    public function handle(): void
    {
        $file = $this->transcriptionFile->fresh();

        if (! $file) {
            return;
        }

        $file->update([
            'status' => $file->clean_audio ? 'enhancing' : 'processing',
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

        $transcriber = $this->resolveTranscriber();

        $loggedMilestones = [];
        $onEvent = function (array $payload) use ($file, &$loggedMilestones): void {
            if (isset($payload['pid'])) {
                $file->forceFill(['worker_pid' => (int) $payload['pid']])->saveQuietly();
                return;
            }
            if (isset($payload['progress'])) {
                $progress = max(0, min(100, (int) $payload['progress']));
                $file->forceFill(['progress' => $progress])->saveQuietly();
                // Loguear solo en hitos clave: primer evento (~inicio), 50% y 100%.
                $milestone = match (true) {
                    $progress >= 100 => 100,
                    $progress >= 50  => 50,
                    default          => 0,
                };
                if (! in_array($milestone, $loggedMilestones, true)) {
                    $loggedMilestones[] = $milestone;
                    Log::info('Whisper progress', [
                        'transcription_file_id' => $file->id,
                        'progress' => $progress,
                    ]);
                }
            }
            if (isset($payload['phase'])) {
                Log::info('Whisper phase: '.$payload['phase'], [
                    'transcription_file_id' => $file->id,
                ] + $payload);

                if ($payload['phase'] === 'denoise_done') {
                    $file->forceFill(['status' => 'processing'])->saveQuietly();
                }
            }
        };

        try {
            $transcriber->transcribe(
                $file,
                $outputPath,
                $file->clean_audio ? $cleanedAbsolutePath : null,
                $onEvent,
            );
        } catch (RemoteWorkerOfflineException $e) {
            Log::warning('Remote worker offline — scheduling retry', [
                'transcription_file_id' => $file->id,
                'message' => $e->getMessage(),
            ]);

            $file->forceFill([
                'status' => 'waiting_for_worker',
                'error_message' => null,
            ])->saveQuietly();

            // Dispatch a fresh job so we don't get killed by `--tries=1` on the worker.
            // Effectively gives infinite retries until the worker comes back online.
            self::dispatch($file)->delay(now()->addSeconds(60));
            return;
        } catch (Throwable $e) {
            if ($file->fresh() === null) {
                Log::info('Transcriber cancelled (transcription deleted)', [
                    'transcription_file_id' => $file->id,
                ]);
                return;
            }

            Log::error('Transcription failed', [
                'transcription_file_id' => $file->id,
                'audio_path' => $audioPath,
                'model' => $file->model,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        $payload = json_decode(file_get_contents($outputPath), true, flags: JSON_THROW_ON_ERROR);
        $segments = $payload['segments'] ?? [];

        DB::transaction(function () use ($file, $payload, $segments, $cleanedAbsolutePath, $cleanedRelativePath): void {
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
        $rawMessage = $exception?->getMessage();
        $exceptionClass = $exception ? get_class($exception) : null;

        $userMessage = match (true) {
            $exception instanceof \Illuminate\Queue\MaxAttemptsExceededException
                => 'El procesamiento superó el tiempo máximo permitido ('.config('transcription.timeout', 1800).'s) o el worker se reinició mientras corría. Probá de nuevo o aumentá WHISPER_TIMEOUT en .env si tu audio es muy largo.',
            $exception instanceof \Symfony\Component\Process\Exception\ProcessTimedOutException
                => 'El proceso de transcripción superó el tiempo permitido. Aumentá WHISPER_TIMEOUT o usá un modelo más rápido.',
            default => $rawMessage,
        };

        Log::error('Transcription job failed', [
            'transcription_file_id' => $this->transcriptionFile->id,
            'message' => $userMessage,
            'raw_message' => $rawMessage,
            'exception' => $exceptionClass,
            'trace' => $exception?->getTraceAsString(),
        ]);

        $this->transcriptionFile->fresh()?->update([
            'status' => 'failed',
            'error_message' => $userMessage,
        ]);
    }

    private function resolveTranscriber(): TranscriberInterface
    {
        $mode = AppSetting::get('mode', 'local');
        return $mode === 'host'
            ? app(RemoteApiTranscriber::class)
            : app(LocalProcessTranscriber::class);
    }

    private function durationFromSegments(array $segments): ?float
    {
        $lastSegment = end($segments);

        return is_array($lastSegment) && isset($lastSegment['end'])
            ? (float) $lastSegment['end']
            : null;
    }
}
