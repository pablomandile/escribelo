<?php

namespace App\Services\Summarizer;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaSummarizer implements SummarizerInterface
{
    /** Ollama corre local: sin rate limits. Chunks más chicos = output más enfocado y menos
     *  riesgo de que el modelo se ponga verboso y trunque el JSON. 30k chars (~7.5k tokens) deja
     *  margen amplio para system prompt + respuesta completa. */
    private const MAX_CHARS_PER_CHUNK = 30000;

    private string $baseUrl;
    private string $model;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.ollama.base_url', 'http://localhost:11434'), '/');
        $this->model = (string) config('services.ollama.summary_model', 'gemma4:e4b');
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
REGLAS:
- Respondé SOLO con un objeto JSON: {"summary": "...", "key_points": []}. Nada antes ni después.
- Tercera persona, neutral. Sin saludos ("Claro,"), sin adjetivos opinativos ("hermosa", "interesante").
- Idioma del audio.
- NO repitas frases ni ideas. Cada palabra cuenta — sé conciso.
TXT;

        $longFormStructure = <<<TXT
Devolvé un objeto JSON con CUATRO campos. NO omitas ninguno (technologies puede ir array vacío si no aplica).

Forma exacta:
{
  "summary": "<markdown con el contexto general>",
  "key_points": ["punto 1", "punto 2", ...],
  "main_topics": ["tema 1", "tema 2", ...],
  "technologies": ["herramienta 1", "herramienta 2", ...]
}

REGLAS DE CADA CAMPO:

"summary" → markdown que contiene:
  - Un párrafo inicial sin encabezado (2-4 oraciones): qué tipo de contenido es (clase, charla, reunión, etc.) y tema central.
  - Después un encabezado `## Resumen general` seguido de mínimo 2 párrafos con contexto, idea-fuerza y a quién va dirigido.
  - NO incluyas otras secciones acá — los puntos, temas y tecnologías van en sus campos JSON propios.

"key_points" → array de 10-20 strings. Cada string es UNA frase corta (máximo 30 palabras) que captura un punto concreto del audio.
Ejemplo: ["El docente enfatiza que el código generado por IA debe revisarse antes de commitearlo.", "Se discuten riesgos de exponer credenciales en repos públicos.", ...]

"main_topics" → array de 5-12 strings. Cada string es una frase CORTA (2-6 palabras), un eje temático general (no una oración).
Ejemplo: ["Buenas prácticas con IA", "Seguridad de credenciales", "Gestión de tokens", "Bases legacy"]

"technologies" → array de strings. Incluí SOLO herramientas, lenguajes, frameworks, servicios o productos concretos mencionados por nombre. Si NO aparece ninguno en el audio, devolvé array vacío `[]`. Formato: el nombre, opcionalmente con una nota breve entre paréntesis.
Ejemplo: ["Docker (entornos reproducibles)", "PostgreSQL", "Claude Code", "Git"]
TXT;

        $systemPrompt = match ($mode) {
            'partial' => <<<TXT
Procesás la PARTE {$chunkIndex} de {$chunkTotal} de una transcripción larga.
Tarea: extraé temas, ejemplos, herramientas y recomendaciones que aparecen en ESTA parte. Material intermedio — NO escribas el resumen final.
Idioma: {$languageHint}.

LÍMITES:
- "summary" = 3-5 oraciones cortas (máximo).
- "key_points" = 5-10 ítems, cada uno una frase corta.
- Sin encabezados markdown.

Formato (única respuesta válida):
{"summary": "...", "key_points": ["...", "..."]}

{$strictRules}
TXT,
            'final' => <<<TXT
Recibís notas parciales de una transcripción larga. Consolidalas en un resumen final estructurado en markdown.
Idioma: {$languageHint}.

{$longFormStructure}

{$strictRules}
TXT,
            default => <<<TXT
Analizá la transcripción y devolvé un resumen estructurado en markdown.
Idioma: {$languageHint}.

{$longFormStructure}

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
                // 0.3 entraba en loops de repetición ("relator-docente-relator-docente..."
                // generando hasta consumir num_predict). 0.5 + repeat_penalty saca al modelo
                // del bucle cuando se atasca en un token. top_p y top_k limitan la creatividad
                // para que el output JSON no se descarrile.
                'temperature' => 0.5,
                'repeat_penalty' => 1.5,
                'top_p' => 0.9,
                'top_k' => 40,
                // Partial: 3072 para notas + key_points sin truncar.
                // Single/final: 6144 — gemma4:e4b tiende a quedarse corto si no le damos
                // espacio; con esto entra el resumen + 10-20 puntos + temas + tecnologías.
                'num_predict' => $mode === 'partial' ? 4096 : 6144,
                // num_ctx = ventana de contexto. El KV cache crece linealmente con esto y
                // es lo que más VRAM consume. 32k para un modelo 26B necesita ~20 GB solo para
                // el cache; en una GPU de 12 GB se va a RAM y el modelo entra en loops catastróficos
                // (KV cache thrashing). Con chunks de 30k chars (~7.5k tokens) + prompt + output,
                // 12k de contexto alcanza y deja la mayor parte del cache en VRAM.
                'num_ctx' => 12288,
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

        $keyPoints = array_values(array_filter(
            array_map('strval', $parsed['key_points'] ?? []),
            fn ($s) => trim($s) !== '',
        ));

        // En single/final, el modelo devuelve summary (intro + resumen general) + arrays
        // separados para puntos / temas / tecnologías. Los reensamblamos en un markdown
        // unificado que es lo que vive en transcriptions.summary y renderiza el frontend.
        // En partial, dejamos summary tal cual (texto plano de notas) y key_points como array,
        // porque el reduce final los consume así.
        $summary = (string) $parsed['summary'];
        if ($mode !== 'partial') {
            $summary = $this->assembleFinalMarkdown(
                summary: $summary,
                keyPoints: $keyPoints,
                mainTopics: $this->normalizeStringList($parsed['main_topics'] ?? []),
                technologies: $this->normalizeStringList($parsed['technologies'] ?? []),
            );
            $keyPoints = [];
        }

        return [
            'summary' => $summary,
            'key_points' => $keyPoints,
            'tokens_used' => $tokensUsed,
            'model' => $this->model,
        ];
    }

    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        return array_values(array_filter(
            array_map(fn ($s) => trim((string) $s), $value),
            fn ($s) => $s !== '',
        ));
    }

    private function assembleFinalMarkdown(string $summary, array $keyPoints, array $mainTopics, array $technologies): string
    {
        $sections = [rtrim($summary)];

        if (! empty($keyPoints)) {
            $sections[] = "## Puntos principales\n\n" . implode("\n", array_map(
                fn ($p) => '- ' . $p,
                $keyPoints,
            ));
        }

        if (! empty($mainTopics)) {
            $sections[] = "## Temas principales\n\n" . implode("\n", array_map(
                fn ($t) => '- ' . $t,
                $mainTopics,
            ));
        }

        if (! empty($technologies)) {
            $sections[] = "## Tecnologías y herramientas mencionadas\n\n" . implode("\n", array_map(
                fn ($t) => '- ' . $t,
                $technologies,
            ));
        }

        return implode("\n\n", $sections);
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

        // Caso 4: JSON truncado o mal formado. Rescatar el campo "summary" con regex.
        // Pasa con modelos verbosos que se quedan sin num_predict o entran en loops.
        if (preg_match('/"summary"\s*:\s*"((?:[^"\\\\]|\\\\.)*)/', $content, $sm)) {
            $rawSummary = $sm[1];
            $summary = stripcslashes($rawSummary);
            $summary = $this->trimCatastrophicRepetition($summary);
            $summary = trim($summary);

            if ($summary !== '' && mb_strlen($summary) >= 50) {
                $keyPoints = [];
                if (preg_match('/"key_points"\s*:\s*\[([\s\S]*?)(\]|$)/', $content, $km)) {
                    if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $km[1], $items)) {
                        $keyPoints = array_values(array_filter(
                            array_map(fn ($s) => trim(stripcslashes($s)), $items[1]),
                            fn ($s) => $s !== '',
                        ));
                    }
                }
                Log::info('Ollama JSON truncado, rescatado vía regex', [
                    'summary_chars' => mb_strlen($summary),
                    'key_points' => count($keyPoints),
                ]);
                return [
                    'summary' => $summary,
                    'key_points' => $keyPoints,
                ];
            }
        }

        return null;
    }

    /**
     * Detecta y recorta repeticiones degeneradas tipo "de la importancia de la importancia de la importancia...".
     * Síntoma de KV cache thrashing o repeat_penalty insuficiente. Si los últimos ~300 caracteres
     * contienen una frase de 8-50 chars repitiéndose 3+ veces seguidas, cortamos en la primera aparición.
     */
    private function trimCatastrophicRepetition(string $text): string
    {
        $len = mb_strlen($text);
        if ($len < 100) {
            return $text;
        }
        // Probar fragmentos repetidos de 8 a 50 caracteres.
        for ($size = 8; $size <= 50; $size++) {
            $tail = mb_substr($text, -($size * 4));
            $sample = mb_substr($tail, 0, $size);
            $repeated = str_repeat($sample, 3);
            if (mb_strpos($tail, $repeated) !== false) {
                // Encontrar la primera ocurrencia del patrón repetido en el texto completo
                // y cortar antes de la segunda repetición.
                $firstOccurrence = mb_strpos($text, $sample);
                if ($firstOccurrence !== false) {
                    Log::warning('Ollama repetición catastrófica detectada y recortada', [
                        'pattern' => $sample,
                        'cut_at' => $firstOccurrence + $size,
                    ]);
                    return mb_substr($text, 0, $firstOccurrence + $size);
                }
            }
        }
        return $text;
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
