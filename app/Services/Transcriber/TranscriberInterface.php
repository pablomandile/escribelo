<?php

namespace App\Services\Transcriber;

use App\Models\TranscriptionFile;

interface TranscriberInterface
{
    /**
     * Transcribe an audio file. Writes the JSON payload (matching the schema produced
     * by whisper_worker/transcribe.py) to $outputJsonPath. If $cleanedAudioOutputPath
     * is set and the file requires denoise, also writes the cleaned MP3 there.
     *
     * The $onEvent callback receives decoded events as associative arrays:
     *   - ['progress' => int 0..100]
     *   - ['phase'    => string, ...extra]
     *
     * @throws RemoteWorkerOfflineException if the remote worker is not reachable.
     * @throws \RuntimeException for any other transcription failure.
     */
    public function transcribe(
        TranscriptionFile $file,
        string $outputJsonPath,
        ?string $cleanedAudioOutputPath,
        callable $onEvent,
    ): void;
}
