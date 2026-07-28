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
        $lastError = '';

        // El túnel de Cloudflare da 502 transitorios ("retryable") — p.ej. cuando
        // cloudflared reusa una conexión keep-alive contra un worker recién
        // reiniciado. Reintentamos antes de dar el resumen por fallido.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($attempt > 1) {
                Log::warning('Remote summarize: reintento tras error transitorio', [
                    'attempt' => $attempt,
                    'error' => $lastError,
                ]);
                sleep($attempt === 2 ? 5 : 20);
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
                $lastError = 'Worker remoto no responde: '.$e->getMessage();

                continue;
            }

            if (in_array($response->status(), [502, 503, 504], true)) {
                $lastError = 'Worker remoto devolvió '.$response->status().': '.mb_substr($response->body(), 0, 300);

                continue;
            }

            if (! $response->successful()) {
                throw new SummarizerException('Worker remoto devolvió '.$response->status().': '.$response->body());
            }

            return $this->parseWorkerResponse($response->body());
        }

        throw new SummarizerException($lastError !== '' ? $lastError : 'Worker remoto: error transitorio persistente.');
    }

    /**
     * El worker responde NDJSON: heartbeats {"status":"working"} mientras Ollama
     * trabaja (así Cloudflare no corta por los ~100s sin respuesta) y una línea
     * final {"result": {...}} o {"error": "..."}. Aceptamos también el JSON plano
     * {"summary": ...} del contrato anterior por compatibilidad.
     */
    private function parseWorkerResponse(string $body): array
    {
        $payload = null;

        foreach (preg_split('/\r?\n/', trim($body)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                continue;
            }

            if (isset($decoded['error'])) {
                throw new SummarizerException('Worker remoto: '.$decoded['error']);
            }

            if (isset($decoded['result']) && is_array($decoded['result'])) {
                $payload = $decoded['result'];
            } elseif (isset($decoded['summary'])) {
                $payload = $decoded;
            }
        }

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
