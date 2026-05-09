<?php

namespace App\Services\Transcriber;

use App\Models\AppSetting;
use App\Models\TranscriptionFile;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Http;

class RemoteApiTranscriber implements TranscriberInterface
{
    public function transcribe(
        TranscriptionFile $file,
        string $outputJsonPath,
        ?string $cleanedAudioOutputPath,
        callable $onEvent,
    ): void {
        $baseUrl = rtrim((string) config('services.remote_worker.base_url'), '/');
        $token = (string) config('services.remote_worker.token');
        $timeout = AppSetting::whisperTimeout();
        $healthTimeout = (int) config('services.remote_worker.health_timeout', 5);

        if ($baseUrl === '' || $token === '') {
            throw new RemoteWorkerOfflineException('REMOTE_WORKER_URL/TOKEN no están configurados.');
        }

        try {
            $health = Http::withToken($token)
                ->timeout($healthTimeout)
                ->get($baseUrl.'/health');

            if (! $health->successful()) {
                throw new RemoteWorkerOfflineException('Worker remoto respondió '.$health->status().' al health check.');
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new RemoteWorkerOfflineException('Worker remoto no responde: '.$e->getMessage());
        }

        $audioPath = $file->absolutePath();
        $audioStream = fopen($audioPath, 'r');
        if ($audioStream === false) {
            throw new \RuntimeException('No se pudo abrir el audio local: '.$audioPath);
        }

        try {
            $client = new GuzzleClient([
                'timeout' => $timeout,
                'connect_timeout' => $healthTimeout,
                'stream' => true,
            ]);

            $multipart = [
                ['name' => 'file', 'contents' => $audioStream, 'filename' => $file->original_name],
                ['name' => 'model', 'contents' => $file->model ?: config('transcription.model', 'small')],
                ['name' => 'clean_audio', 'contents' => $file->clean_audio ? '1' : '0'],
            ];
            if ($file->language) {
                $multipart[] = ['name' => 'language', 'contents' => $file->language];
            }

            try {
                $response = $client->request('POST', $baseUrl.'/transcribe', [
                    'headers' => ['Authorization' => 'Bearer '.$token],
                    'multipart' => $multipart,
                ]);
            } catch (ConnectException $e) {
                throw new RemoteWorkerOfflineException('Conexión al worker remoto cortada: '.$e->getMessage());
            } catch (RequestException $e) {
                $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();
                throw new \RuntimeException('Worker remoto devolvió error: '.$body);
            }

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('Worker remoto status '.$response->getStatusCode().': '.(string) $response->getBody());
            }

            $body = $response->getBody();
            $buffer = '';
            $finalResult = null;
            $cleanedB64 = null;

            while (! $body->eof()) {
                $chunk = $body->read(8192);
                if ($chunk === '') {
                    continue;
                }
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if ($line === '' || $line[0] !== '{') {
                        continue;
                    }
                    $payload = json_decode($line, true);
                    if (! is_array($payload)) {
                        continue;
                    }
                    if (isset($payload['result'])) {
                        $finalResult = $payload['result'];
                        $cleanedB64 = $payload['cleaned_audio_b64'] ?? null;
                        continue;
                    }
                    $onEvent($payload);
                }
            }

            if ($finalResult === null) {
                throw new \RuntimeException('Worker remoto no devolvió payload final.');
            }

            file_put_contents(
                $outputJsonPath,
                json_encode($finalResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            );

            if ($cleanedB64 && $cleanedAudioOutputPath) {
                $bytes = base64_decode($cleanedB64, true);
                if ($bytes !== false) {
                    @mkdir(dirname($cleanedAudioOutputPath), 0775, true);
                    file_put_contents($cleanedAudioOutputPath, $bytes);
                }
            }
        } finally {
            if (is_resource($audioStream)) {
                fclose($audioStream);
            }
        }
    }
}
