<?php

namespace App\Services\Summarizer;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaSummarizer implements SummarizerInterface
{
    /** Ollama corre local: sin rate limits, podemos mandar chunks grandes.
     *  gemma3:12b soporta ~128k tokens. 50k chars (~12k tokens) deja margen para system prompt y respuesta. */
    private const MAX_CHARS_PER_CHUNK = 50000;

    private string $baseUrl;
    private string $model;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.ollama.base_url', 'http://localhost:11434'), '/');
        $this->model = (string) config('services.ollama.summary_model', 'gemma3:12b');
    }

    public function summarize(string $text, ?string $language = null): array
    {
        if (mb_strlen($text) <= self::MAX_CHARS_PER_CHUNK) {
            return $this->callOnce($text, $language, mode: 'single');
        }

        return $this->summarizeWithChunking($text, $language);
    }

    private function summarizeWithChunking(string $text, ?string $language): array
    {
        $chunks = $this->chunkText($text, self::MAX_CHARS_PER_CHUNK);
        Log::info('Ollama chunked summarize', [
            'chunks' => count($chunks),
            'total_chars' => mb_strlen($text),
            'model' => $this->model,
        ]);

        $partialSummaries = [];
        $tokensTotal = 0;

        foreach ($chunks as $i => $chunk) {
            $partial = $this->callOnce($chunk, $language, mode: 'partial', chunkIndex: $i + 1, chunkTotal: count($chunks));
            $partialSummaries[] = $partial['summary'];
            if (! empty($partial['key_points'])) {
                $partialSummaries[] = '• '.implode("\n• ", $partial['key_points']);
            }
            $tokensTotal += (int) $partial['tokens_used'];
        }

        $combined = implode("\n\n---\n\n", $partialSummaries);

        if (mb_strlen($combined) > self::MAX_CHARS_PER_CHUNK) {
            return $this->summarizeWithChunking($combined, $language);
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
            'stream' => false,
            'format' => 'json',
            'options' => [
                'temperature' => 0.3,
                'num_predict' => 1024,
            ],
        ];

        Log::info('Ollama request', [
            'mode' => $mode,
            'chunk' => "{$chunkIndex}/{$chunkTotal}",
            'text_chars' => mb_strlen($text),
            'model' => $this->model,
        ]);

        try {
            $response = Http::timeout(600)
                ->acceptJson()
                ->post($this->baseUrl.'/api/chat', $payload);
        } catch (\Throwable $e) {
            Log::error('Ollama HTTP error', ['error' => $e->getMessage()]);
            throw new SummarizerException('No se pudo contactar a Ollama: '.$e->getMessage().' (¿Está corriendo en '.$this->baseUrl.'?)', previous: $e);
        }

        if (! $response->successful()) {
            $body = $response->body();
            Log::error('Ollama API failure', ['status' => $response->status(), 'body' => substr($body, 0, 500)]);
            throw new SummarizerException('Ollama devolvió un error ('.$response->status().').');
        }

        $data = $response->json();
        $content = $data['message']['content'] ?? '';
        $promptTokens = (int) ($data['prompt_eval_count'] ?? 0);
        $completionTokens = (int) ($data['eval_count'] ?? 0);
        $tokensUsed = $promptTokens + $completionTokens;

        $parsed = json_decode($content, true);
        if (! is_array($parsed) || ! isset($parsed['summary'])) {
            Log::warning('Ollama response not valid JSON', ['content' => substr($content, 0, 500)]);
            throw new SummarizerException('Ollama devolvió una respuesta inválida.');
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
