<?php

namespace App\Services\Summarizer;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RemoteSummarizer implements SummarizerInterface
{
    /**
     * El worker corre en la PC del usuario y se llega por el túnel Cloudflare,
     * que corta las respuestas que tardan demasiado (~100s → error 502). Por eso
     * troceamos: cada /summarize es sobre un chunk chico → Ollama responde rápido
     * → la request entra dentro del límite del túnel. Mismo criterio map-reduce que
     * OllamaSummarizer, pero delegando cada llamada al worker remoto.
     */
    private const MAX_CHARS_PER_CHUNK = 25000;

    public function summarize(string $text, ?string $language = null, ?callable $onProgress = null): array
    {
        $baseUrl = rtrim(AppSetting::remoteWorkerUrl(), '/');
        $token = (string) config('services.remote_worker.token');
        $timeout = (int) config('services.remote_worker.timeout', 14400);

        if ($baseUrl === '' || $token === '') {
            throw new SummarizerException('REMOTE_WORKER_URL/TOKEN no están configurados.');
        }

        // Texto corto: una sola llamada, como antes.
        if (mb_strlen($text) <= self::MAX_CHARS_PER_CHUNK) {
            if ($onProgress) {
                $onProgress(['phase' => 'single', 'chunk' => 1, 'total' => 1]);
            }

            return $this->callOnce($baseUrl, $token, $timeout, $text, $language);
        }

        return $this->summarizeWithChunking($baseUrl, $token, $timeout, $text, $language, $onProgress);
    }

    private function summarizeWithChunking(string $baseUrl, string $token, int $timeout, string $text, ?string $language, ?callable $onProgress): array
    {
        $chunks = $this->chunkText($text, self::MAX_CHARS_PER_CHUNK);
        Log::info('Remote chunked summarize', [
            'chunks' => count($chunks),
            'total_chars' => mb_strlen($text),
        ]);

        $partials = [];
        $tokensTotal = 0;

        // Map: resumir cada trozo por separado (llamadas chicas → rápidas).
        foreach ($chunks as $i => $chunk) {
            if ($onProgress) {
                $onProgress([
                    'phase' => 'partial',
                    'chunk' => $i + 1,
                    'total' => count($chunks),
                    'tokens_so_far' => $tokensTotal,
                ]);
            }

            $partial = $this->callOnce($baseUrl, $token, $timeout, $chunk, $language);
            $partials[] = $partial['summary'];
            if (! empty($partial['key_points'])) {
                $partials[] = '• '.implode("\n• ", $partial['key_points']);
            }
            $tokensTotal += (int) $partial['tokens_used'];
        }

        $combined = implode("\n\n---\n\n", $partials);

        // Si la combinación sigue siendo enorme (muchísimos trozos), reducimos de nuevo.
        if (mb_strlen($combined) > self::MAX_CHARS_PER_CHUNK) {
            return $this->summarizeWithChunking($baseUrl, $token, $timeout, $combined, $language, $onProgress);
        }

        // Reduce: resumen final a partir de los resúmenes parciales.
        if ($onProgress) {
            $onProgress([
                'phase' => 'reducing',
                'chunk' => count($chunks),
                'total' => count($chunks),
                'tokens_so_far' => $tokensTotal,
            ]);
        }

        $final = $this->callOnce($baseUrl, $token, $timeout, $combined, $language);
        $final['tokens_used'] += $tokensTotal;

        return $final;
    }

    /** Corta el texto en trozos <= $maxLen respetando límites de oración/línea. */
    private function chunkText(string $text, int $maxLen): array
    {
        $chunks = [];
        $remaining = $text;

        while (mb_strlen($remaining) > $maxLen) {
            $slice = mb_substr($remaining, 0, $maxLen);
            $cutAt = max(
                mb_strrpos($slice, '. '),
                mb_strrpos($slice, "\n"),
                mb_strrpos($slice, '? '),
                mb_strrpos($slice, '! '),
            );
            if ($cutAt === false || $cutAt < $maxLen / 2) {
                $cutAt = $maxLen;
            } else {
                $cutAt += 1;
            }
            $chunks[] = trim(mb_substr($remaining, 0, $cutAt));
            $remaining = mb_substr($remaining, $cutAt);
        }

        if (trim($remaining) !== '') {
            $chunks[] = trim($remaining);
        }

        return $chunks;
    }

    private function callOnce(string $baseUrl, string $token, int $timeout, string $text, ?string $language): array
    {
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
