<?php

namespace App\Services\Transcriber;

use App\Models\AppSetting;
use App\Models\TranscriptionFile;
use Symfony\Component\Process\Process;

class LocalProcessTranscriber implements TranscriberInterface
{
    public function transcribe(
        TranscriptionFile $file,
        string $outputJsonPath,
        ?string $cleanedAudioOutputPath,
        callable $onEvent,
    ): void {
        $audioPath = $file->absolutePath();

        $process = new Process([
            config('transcription.python', 'python'),
            base_path('whisper_worker/transcribe.py'),
            '--file',
            $audioPath,
            '--output',
            $outputJsonPath,
            '--model',
            $file->model ?: config('transcription.model', 'small'),
            ...($file->language ? ['--language', $file->language] : []),
            ...($file->clean_audio && $cleanedAudioOutputPath
                ? ['--clean-audio', '--cleaned-output', $cleanedAudioOutputPath]
                : []),
        ], base_path(), [
            'PYTHONIOENCODING' => 'utf-8',
        ], null, (float) AppSetting::whisperTimeout());

        $stdoutBuffer = '';
        $process->start();

        // Notify the caller of the PID so they can store it for cancellation.
        $onEvent(['pid' => $process->getPid()]);

        $process->wait(function ($type, $buffer) use (&$stdoutBuffer, $onEvent): void {
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
                if (is_array($payload)) {
                    $onEvent($payload);
                }
            }
        });

        if (! $process->isSuccessful()) {
            // En Windows con CUDA, el intérprete de Python a veces falla en el cleanup
            // del contexto de la GPU al cerrarse, devolviendo exit code != 0 aunque la
            // transcripción ya haya escrito el JSON de salida. Si el archivo existe y
            // parsea, lo damos por bueno y solo logueamos el incidente.
            if (is_file($outputJsonPath) && filesize($outputJsonPath) > 0) {
                $contents = file_get_contents($outputJsonPath);
                $decoded = json_decode($contents, true);
                if (is_array($decoded) && isset($decoded['segments'])) {
                    \Illuminate\Support\Facades\Log::warning('Whisper exited non-zero after writing output (likely CUDA cleanup); treating as success', [
                        'transcription_file_id' => $file->id,
                        'exit_code' => $process->getExitCode(),
                        'stderr_tail' => mb_substr(trim($process->getErrorOutput()), -500),
                    ]);
                    return;
                }
            }

            $errorOutput = trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'Whisper failed.';

            $friendly = match (true) {
                str_contains($errorOutput, 'RNNoise model not found')
                    => 'No se encontró el modelo cb.rnnn de RNNoise en whisper_worker/models/. Bajalo de https://github.com/GregorR/rnnoise-models.',
                str_contains($errorOutput, 'arnndn') && (str_contains($errorOutput, 'Unable to parse') || str_contains($errorOutput, 'Undefined constant'))
                    => 'Error en el filtro de reducción de ruido (parsing del path). Mirá el log para detalle.',
                str_contains($errorOutput, "No module named 'whisper'") || str_contains($errorOutput, "No module named 'faster_whisper'")
                    => 'Whisper no está instalado. Corré "pip install openai-whisper" o "pip install faster-whisper".',
                str_contains($errorOutput, 'CUDA out of memory')
                    => 'La GPU se quedó sin memoria. Probá con un modelo más chico o desactivá la GPU.',
                str_contains($errorOutput, 'ffmpeg')
                    => 'ffmpeg no pudo procesar el archivo. Verificá que el audio no esté corrupto.',
                default => $errorOutput,
            };

            throw new \RuntimeException($friendly);
        }
    }
}
