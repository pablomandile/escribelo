<?php

namespace App\Services\Summarizer;

use Illuminate\Support\Facades\Http;

class RemoteSummarizer implements SummarizerInterface
{
    public function summarize(string $text, ?string $language = null, ?callable $onProgress = null): array
    {
        if ($onProgress) {
            $onProgress(['phase' => 'single', 'chunk' => 1, 'total' => 1]);
        }

        $baseUrl = rtrim((string) config('services.remote_worker.base_url'), '/');
        $token = (string) config('services.remote_worker.token');
        $timeout = (int) config('services.remote_worker.timeout', 14400);

        if ($baseUrl === '' || $token === '') {
            throw new SummarizerException('REMOTE_WORKER_URL/TOKEN no están configurados.');
        }

        try {
            $response = Http::withToken($token)
                ->timeout($timeout)
                ->acceptJson()
                ->post($baseUrl.'/summarize', [
                    'text' => $text,
                    'language' => $language,
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new SummarizerException('Worker remoto no responde: '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw new SummarizerException('Worker remoto devolvió '.$response->status().': '.$response->body());
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['summary'])) {
            throw new SummarizerException('Worker remoto devolvió un payload inválido.');
        }

        return [
            'summary' => (string) $payload['summary'],
            'key_points' => (array) ($payload['key_points'] ?? []),
            'tokens_used' => (int) ($payload['tokens_used'] ?? 0),
            'model' => (string) ($payload['model'] ?? 'remote'),
        ];
    }
}
