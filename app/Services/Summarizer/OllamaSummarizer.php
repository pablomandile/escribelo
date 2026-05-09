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
        Log::info('Ollama chunked summarize', [
            'chunks' => count($chunks),
            'total_chars' => mb_strlen($text),
            'model' => $this->model,
        ]);

        $partialSummaries = [];
        $tokensTotal = 0;

        foreach ($chunks as $i => $chunk) {
            if ($onProgress) {
                $onProgress([
                    'phase' => 'partial',
                    'chunk' => $i + 1,
                    'total' => count($chunks),
                    'tokens_so_far' => $tokensTotal,
                ]);
            }
            $partial = $this->callOnce($chunk, $language, mode: 'partial', chunkIndex: $i + 1, chunkTotal: count($chunks));
            $partialSummaries[] = $partial['summary'];
            if (! empty($partial['key_points'])) {
                $partialSummaries[] = '• '.implode("\n• ", $partial['key_points']);
            }
            $tokensTotal += (int) $partial['tokens_used'];
        }

        $combined = implode("\n\n---\n\n", $partialSummaries);

        if (mb_strlen($combined) > self::MAX_CHARS_PER_CHUNK) {
            return $this->summarizeWithChunking($combined, $language, $onProgress);
        }

        if ($onProgress) {
            $onProgress([
                'phase' => 'reducing',
                'chunk' => count($chunks),
                'total' => count($chunks),
                'tokens_so_far' => $tokensTotal,
            ]);
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

        $strictRules = <<<TXT
REGLAS ESTRICTAS:
- Tu respuesta debe ser ÚNICAMENTE un objeto JSON. Nada de markdown, nada de comentarios, nada antes ni después.
- Las claves del objeto DEBEN ser exactamente "summary" y "key_points". NO usar "response", "result", ni ninguna otra clave.
- Sos un transcriptor neutral. NO opines sobre el contenido. NO uses adjetivos como "hermosa", "conmovedora", "interesante". NO te dirijas al hablante.
- Resumí lo que el hablante dijo, en tercera persona, como si fueras un periodista.
TXT;

        $systemPrompt = match ($mode) {
            'partial' => <<<TXT
Procesás la PARTE {$chunkIndex} de {$chunkTotal} de una transcripción larga.
Tarea: resumí esta parte en 3-5 oraciones y extraé los puntos principales presentes en ESTA parte.
Idioma: {$languageHint}.
Formato de salida (única respuesta válida):
{"summary": "resumen de esta parte en 3-5 oraciones", "key_points": ["punto concreto 1", "punto concreto 2", "..."]}

{$strictRules}
TXT,
            'final' => <<<TXT
Recibís resúmenes parciales de una transcripción larga. Tu tarea es combinarlos en UN único resumen general en 3-5 oraciones, y consolidar los puntos principales en una lista de 3-10 ítems concisos sin repeticiones.
Idioma: {$languageHint}.
Formato de salida (única respuesta válida):
{"summary": "resumen general en 3-5 oraciones", "key_points": ["punto 1", "punto 2", "..."]}

{$strictRules}
TXT,
            default => <<<TXT
Sos un asistente que analiza transcripciones de audio y devuelve un resumen breve y los puntos principales.
Idioma: {$languageHint}.
Formato de salida (única respuesta válida):
{"summary": "resumen en 3-5 oraciones que capture lo central", "key_points": ["punto 1", "punto 2", "..."]}
La lista key_points debe tener entre 3 y 10 ítems concisos.

{$strictRules}
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
                // Default de Ollama es 4096 tokens. Nuestras chunks de 50k caracteres son
                // ~12.5k tokens, así que sin esto Ollama trunca silenciosamente o se vuelve muy lento.
                // gemma3:12b soporta hasta 128k. 32k es un buen balance entre VRAM y headroom.
                'num_ctx' => 32768,
            ],
            // Mantener el modelo cargado entre chunks para no perder ~30s recargándolo cada vez.
            'keep_alive' => '30m',
        ];

        $startedAt = microtime(true);
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

        Log::info('Ollama response', [
            'mode' => $mode,
            'chunk' => "{$chunkIndex}/{$chunkTotal}",
            'elapsed_s' => round(microtime(true) - $startedAt, 2),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
        ]);

        $parsed = $this->parseSummaryResponse($content);
        if ($parsed === null) {
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

    /**
     * Parsea la respuesta del modelo intentando rescatar el contenido aunque venga
     * con la forma equivocada. gemma3 a veces envuelve la respuesta en {"response":"..."}
     * o devuelve solo un string. Toleramos varios formatos antes de rendirnos.
     */
    private function parseSummaryResponse(string $content): ?array
    {
        $decoded = json_decode($content, true);

        // Caso 1: shape correcto {"summary":..., "key_points":[...]}
        if (is_array($decoded) && isset($decoded['summary'])) {
            return $decoded;
        }

        // Caso 2: el modelo metió la respuesta en otra clave (response/answer/result/output/text).
        if (is_array($decoded)) {
            foreach (['response', 'answer', 'result', 'output', 'text'] as $altKey) {
                if (! isset($decoded[$altKey])) {
                    continue;
                }
                $alt = $decoded[$altKey];
                // 2a. La sub-clave contiene a su vez un JSON con summary/key_points.
                if (is_string($alt)) {
                    $nested = json_decode($alt, true);
                    if (is_array($nested) && isset($nested['summary'])) {
                        return $nested;
                    }
                    // 2b. La sub-clave es texto libre — lo usamos como summary.
                    return [
                        'summary' => trim($alt),
                        'key_points' => $this->extractBulletsFromText($alt),
                    ];
                }
            }
        }

        // Caso 3: el contenido NO es JSON pero contiene un objeto JSON adentro
        // (por ejemplo, prefacio + ```json {...} ```).
        if (preg_match('/\{[\s\S]*\}/', $content, $m)) {
            $nested = json_decode($m[0], true);
            if (is_array($nested) && isset($nested['summary'])) {
                return $nested;
            }
        }

        return null;
    }

    private function extractBulletsFromText(string $text): array
    {
        $points = [];
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $line = trim($line);
            if (preg_match('/^[\*\-•·]+\s+(.+)$/u', $line, $m)) {
                $points[] = trim($m[1]);
            }
        }
        return array_slice($points, 0, 10);
    }
}
