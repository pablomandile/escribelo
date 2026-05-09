<?php

namespace App\Services\Summarizer;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqSummarizer implements SummarizerInterface
{
    /** ~2000 tokens of input per chunk (rule of thumb: 1 token ≈ 4 chars).
     *  Conservador para evitar 413 en el free tier — habíamos visto rechazos con 18k. */
    private const MAX_CHARS_PER_CHUNK = 8000;

    private string $key;
    private string $baseUrl;
    private string $model;

    public function __construct()
    {
        $this->key = (string) config('services.groq.key', '');
        $this->baseUrl = rtrim((string) config('services.groq.base_url'), '/');
        $this->model = (string) config('services.groq.model');

        if ($this->key === '') {
            throw new SummarizerException('La API key de Groq no está configurada (GROQ_APIKEY).');
        }
    }

    public function summarize(string $text, ?string $language = null, ?callable $onProgress = null): array
    {
        if (mb_strlen($text) <= self::MAX_CHARS_PER_CHUNK) {
            if ($onProgress) {
                $onProgress(['phase' => 'single', 'chunk' => 1, 'total' => 1]);
            }
            return $this->callOnce($text, $language, mode: 'single');
        }

        return $this->summarizeWithChunking($text, $language, $onProgress);
    }

    private function summarizeWithChunking(string $text, ?string $language, ?callable $onProgress = null): array
    {
        $chunks = $this->chunkText($text, self::MAX_CHARS_PER_CHUNK);
        Log::info('Groq chunked summarize', ['chunks' => count($chunks), 'total_chars' => mb_strlen($text)]);

        $partialSummaries = [];
        $tokensTotal = 0;

        foreach ($chunks as $i => $chunk) {
            if ($onProgress) {
                $onProgress(['phase' => 'partial', 'chunk' => $i + 1, 'total' => count($chunks), 'tokens_so_far' => $tokensTotal]);
            }
            $partial = $this->callOnce($chunk, $language, mode: 'partial', chunkIndex: $i + 1, chunkTotal: count($chunks));
            $partialSummaries[] = $partial['summary'];
            if (! empty($partial['key_points'])) {
                $partialSummaries[] = '• '.implode("\n• ", $partial['key_points']);
            }
            $tokensTotal += (int) $partial['tokens_used'];
            // Pequeña pausa para respetar TPM del free tier
            usleep(400_000);
        }

        $combined = implode("\n\n---\n\n", $partialSummaries);

        if (mb_strlen($combined) > self::MAX_CHARS_PER_CHUNK) {
            // Si los resúmenes parciales juntos siguen siendo gigantes, resumimos los resúmenes recursivamente
            return $this->summarizeWithChunking($combined, $language, $onProgress);
        }

        if ($onProgress) {
            $onProgress(['phase' => 'reducing', 'chunk' => count($chunks), 'total' => count($chunks), 'tokens_so_far' => $tokensTotal]);
        }

        $final = $this->callOnce($combined, $language, mode: 'final');
        $final['tokens_used'] += $tokensTotal;

        return $final;
    }

    private function chunkText(string $text, int $maxLen): array
    {
        $chunks = [];
        $remaining = $text;

        while (mb_strlen($remaining) > $maxLen) {
            $slice = mb_substr($remaining, 0, $maxLen);
            // Cortar en el último punto/salto cercano para no romper oraciones
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

    private function callOnce(string $text, ?string $language, string $mode, int $chunkIndex = 1, int $chunkTotal = 1): array
    {
        $languageHint = match ($language) {
            'es' => 'español rioplatense',
            'en' => 'English',
            'pt' => 'português',
            'fr' => 'français',
            'de' => 'Deutsch',
            'it' => 'italiano',
            default => 'el mismo idioma del texto',
        };

        $systemPrompt = match ($mode) {
            'partial' => <<<TXT
Estás procesando la PARTE {$chunkIndex} de {$chunkTotal} de una transcripción larga.
Resumí esta parte en 3-5 oraciones y extraé los puntos principales que aparecen en ESTA parte.
Respondé SIEMPRE en {$languageHint}.
Devolvé EXCLUSIVAMENTE JSON válido:
{"summary": "resumen de esta parte", "key_points": ["..."]}
TXT,
            'final' => <<<TXT
Recibís resúmenes parciales de una transcripción larga. Combinálos en UN único resumen general en 3-5 oraciones, y consolidá los puntos principales en una lista de 3-10 ítems concisos sin repeticiones.
Respondé SIEMPRE en {$languageHint}.
Devolvé EXCLUSIVAMENTE JSON válido:
{"summary": "resumen general", "key_points": ["..."]}
TXT,
            default => <<<TXT
Sos un asistente que analiza transcripciones de audio y devuelve un resumen breve y los puntos principales.
Respondé SIEMPRE en {$languageHint}.
Devolvé EXCLUSIVAMENTE JSON válido con esta forma:
{"summary": "resumen en 3-5 oraciones que capture lo central", "key_points": ["punto 1", "punto 2", ...]}
La lista key_points debe tener entre 3 y 10 ítems concisos.
No agregues comentarios fuera del JSON.
TXT,
        };

        $userContent = $mode === 'partial'
            ? "Parte {$chunkIndex} de la transcripción:\n\n".$text
            : ($mode === 'final'
                ? "Resúmenes parciales:\n\n".$text
                : "Transcripción:\n\n".$text);

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.3,
            'max_tokens' => 1024,
        ];

        $payloadBytes = strlen(json_encode($payload));
        Log::info('Groq request', [
            'mode' => $mode,
            'chunk' => "{$chunkIndex}/{$chunkTotal}",
            'text_chars' => mb_strlen($text),
            'payload_bytes' => $payloadBytes,
        ]);

        try {
            $response = Http::timeout(90)
                ->withToken($this->key)
                ->acceptJson()
                ->post($this->baseUrl.'/chat/completions', $payload);
        } catch (\Throwable $e) {
            Log::error('Groq HTTP error', ['error' => $e->getMessage()]);
            throw new SummarizerException('No se pudo contactar a Groq: '.$e->getMessage(), previous: $e);
        }

        if ($response->status() === 429) {
            throw new SummarizerException('Llegaste al límite del free tier de Groq por hoy. Intentá mañana.');
        }
        if ($response->status() === 413) {
            Log::error('Groq 413 Payload Too Large', [
                'mode' => $mode,
                'chunk' => "{$chunkIndex}/{$chunkTotal}",
                'text_chars' => mb_strlen($text),
                'payload_bytes' => $payloadBytes,
                'response_body' => substr($response->body(), 0, 500),
            ]);
            throw new SummarizerException('Una de las partes sigue siendo demasiado grande para Groq (envío '.number_format($payloadBytes).' bytes). Mirá storage/logs/laravel.log para detalle.');
        }
        if (! $response->successful()) {
            $body = $response->body();
            Log::error('Groq API failure', ['status' => $response->status(), 'body' => substr($body, 0, 500)]);
            throw new SummarizerException('Groq devolvió un error ('.$response->status().').');
        }

        $data = $response->json();
        $content = $data['choices'][0]['message']['content'] ?? '';
        $tokensUsed = (int) ($data['usage']['total_tokens'] ?? 0);

        $parsed = json_decode($content, true);
        if (! is_array($parsed) || ! isset($parsed['summary'])) {
            Log::warning('Groq response not valid JSON', ['content' => $content]);
            throw new SummarizerException('Groq devolvió una respuesta inválida.');
        }

        return [
            'summary' => (string) $parsed['summary'],
            'key_points' => array_values(array_filter(
                array_map('strval', $parsed['key_points'] ?? []),
                fn ($s) => trim($s) !== '',
            )),
            'tokens_used' => $tokensUsed,
            'model' => $this->model,
        ];
    }
}
