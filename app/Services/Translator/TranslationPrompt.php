<?php

namespace App\Services\Translator;

/**
 * Lo que tienen en común la traducción local y la remota: el prompt, las
 * opciones de Ollama, el corte en trozos y la limpieza de la respuesta. Así el
 * worker de la PC solo ejecuta lo que le manda el server y no hay dos copias
 * del prompt para mantener en sincronía.
 */
class TranslationPrompt
{
    /**
     * Trozos chicos a propósito: la traducción sale casi tan larga como la
     * entrada, y tiene que entrar entera en num_predict. ~5000 caracteres son
     * ~1300 tokens de entrada y otro tanto de salida: con gemma4:e4b en la RTX
     * 3060 cada trozo tarda segundos, y el job puede guardar el avance entre uno
     * y otro.
     */
    public const MAX_CHARS_PER_CHUNK = 5000;

    private const NAMES = [
        'es' => 'español',
        'en' => 'inglés (English)',
        'pt' => 'portugués (português)',
        'fr' => 'francés (français)',
        'de' => 'alemán (Deutsch)',
        'it' => 'italiano',
    ];

    public static function languageName(?string $code): ?string
    {
        return $code ? (self::NAMES[$code] ?? $code) : null;
    }

    public static function system(string $targetLanguage, ?string $sourceLanguage = null): string
    {
        $target = self::languageName($targetLanguage);
        $source = self::languageName($sourceLanguage);
        $origin = $source ? " (está en {$source})" : '';

        return <<<TXT
Sos un traductor profesional. Traducí al {$target} el texto que te paso, que es un fragmento de la transcripción automática de un audio hablado{$origin}.

REGLAS:
- Devolvé SOLO la traducción. Nada antes ni después: sin "Aquí está la traducción", sin notas, sin comillas, sin encabezados y sin el texto original.
- Traducí TODO el fragmento, de principio a fin. No resumas, no acortes y no te saltees partes.
- Respetá el sentido, el tono y la persona de quien habla.
- Los nombres propios, las marcas y los términos técnicos sin traducción habitual quedan como están.
- Si alguna parte ya está en {$target}, dejala igual.
TXT;
    }

    /** Opciones de Ollama para traducir: fidelidad antes que creatividad. */
    public static function ollamaOptions(): array
    {
        return [
            'temperature' => 0.2,
            // Bajo a propósito: el 1.5 del resumen castiga repetir palabras, y una
            // traducción fiel repite las palabras que repite el original.
            'repeat_penalty' => 1.1,
            'top_p' => 0.9,
            'num_predict' => 4096,
            'num_ctx' => 8192,
        ];
    }

    /** Corta el texto en trozos <= $maxLen respetando el final de oraciones o líneas. */
    public static function chunk(string $text, int $maxLen = self::MAX_CHARS_PER_CHUNK): array
    {
        $chunks = [];
        $remaining = trim($text);

        while (mb_strlen($remaining) > $maxLen) {
            $slice = mb_substr($remaining, 0, $maxLen);
            $cutAt = max(
                (int) mb_strrpos($slice, '. '),
                (int) mb_strrpos($slice, "\n"),
                (int) mb_strrpos($slice, '? '),
                (int) mb_strrpos($slice, '! '),
            );
            if ($cutAt < $maxLen / 2) {
                // Sin un fin de oración razonable: cortamos en el último espacio.
                $space = (int) mb_strrpos($slice, ' ');
                $cutAt = $space > $maxLen / 2 ? $space : $maxLen;
            } else {
                $cutAt += 1;
            }
            $chunks[] = trim(mb_substr($remaining, 0, $cutAt));
            $remaining = trim(mb_substr($remaining, $cutAt));
        }

        if ($remaining !== '') {
            $chunks[] = $remaining;
        }

        return $chunks;
    }

    /** Saca lo que el modelo agrega a veces alrededor de la traducción. */
    public static function clean(string $content): string
    {
        $text = trim($content);
        // ```texto``` o ```markdown\ntexto```
        if (preg_match('/^```[a-zA-Z]*\s*\n?([\s\S]*?)\n?```$/', $text, $m)) {
            $text = trim($m[1]);
        }
        // "Traducción:" / "Translation:" / "Tradução:" en la primera línea.
        $text = preg_replace('/^(traducci[oó]n|translation|tradu[cç][aã]o)\s*(al|to|para)?[^:\n]{0,30}:\s*/iu', '', $text);

        return trim((string) $text);
    }
}
