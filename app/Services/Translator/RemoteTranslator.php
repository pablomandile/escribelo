<?php

namespace App\Services\Translator;

use App\Models\AppSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Modo host: Ollama corre en la PC del usuario y se llega por el túnel de
 * Cloudflare (POST {worker}/translate). Mismo contrato NDJSON que /summarize:
 * latidos {"status":"working"} mientras Ollama trabaja —así Cloudflare no corta a
 * los ~100 s— y una línea final {"result": {...}} o {"error": "..."}.
 */
class RemoteTranslator implements TranslatorInterface
{
    public function translateChunk(string $text, string $targetLanguage, ?string $sourceLanguage = null): array
    {
        $baseUrl = rtrim(AppSetting::remoteWorkerUrl(), '/');
        $token = (string) config('services.remote_worker.token');
        $timeout = (int) config('services.remote_worker.timeout', 14400);

        if ($baseUrl === '' || $token === '') {
            throw new TranslatorException('REMOTE_WORKER_URL/TOKEN no están configurados.');
        }

        $payload = [
            'text' => $text,
            'system' => TranslationPrompt::system($targetLanguage, $sourceLanguage),
            'options' => TranslationPrompt::ollamaOptions(),
        ];

        $lastError = '';

        // 502/503/504 del túnel suelen ser transitorios (cloudflared reusando una
        // conexión contra un worker recién reiniciado): reintentamos, como el resumen.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($attempt > 1) {
                Log::warning('Remote translate: reintento tras error transitorio', ['attempt' => $attempt, 'error' => $lastError]);
                sleep($attempt === 2 ? 5 : 20);
            }

            try {
                $response = Http::withToken($token)
                    ->timeout($timeout)
                    ->acceptJson()
                    ->post($baseUrl.'/translate', $payload);
            } catch (ConnectionException $e) {
                $lastError = 'Worker remoto no responde: '.$e->getMessage();

                continue;
            }

            if (in_array($response->status(), [502, 503, 504], true)) {
                $lastError = 'Worker remoto devolvió '.$response->status().': '.mb_substr($response->body(), 0, 300);

                continue;
            }

            if ($response->status() === 404) {
                throw new TranslatorException('El worker de la PC no sabe traducir todavía: reinicialo para que tome la versión nueva (falta /translate).');
            }

            if (! $response->successful()) {
                throw new TranslatorException('Worker remoto devolvió '.$response->status().': '.mb_substr($response->body(), 0, 300));
            }

            return $this->parse($response->body());
        }

        throw new TranslatorException($lastError !== '' ? $lastError : 'Worker remoto: error transitorio persistente.');
    }

    private function parse(string $body): array
    {
        $result = null;

        foreach (preg_split('/\r?\n/', trim($body)) as $line) {
            $decoded = json_decode(trim($line), true);
            if (! is_array($decoded)) {
                continue;
            }
            if (isset($decoded['error'])) {
                throw new TranslatorException('Worker remoto: '.$decoded['error']);
            }
            if (isset($decoded['result']) && is_array($decoded['result'])) {
                $result = $decoded['result'];
            }
        }

        $translated = TranslationPrompt::clean((string) ($result['text'] ?? ''));
        if ($translated === '') {
            throw new TranslatorException('El worker remoto devolvió una traducción vacía.');
        }

        return [
            'text' => $translated,
            'tokens_used' => (int) ($result['tokens_used'] ?? 0),
            'model' => (string) ($result['model'] ?? 'remote'),
        ];
    }
}
