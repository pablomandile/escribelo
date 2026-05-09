<?php

namespace App\Services\Transcription;

class SegmentReconciler
{
    /**
     * Word-level diff entre los segmentos originales (con timestamps) y un texto editado.
     * Devuelve un array de segmentos "efectivos" que conservan los timestamps del original
     * para las palabras sobrevivientes y heredan al vecino más cercano para las palabras
     * que el usuario agregó.
     *
     * @param  array<int, array{start: float, end: float, text: string, position?: int}>  $originalSegments
     * @return array<int, array{start: float, end: float, text: string, source_segments: int[]}>
     */
    public function reconcile(array $originalSegments, string $editedText): array
    {
        if (trim($editedText) === '') {
            return [];
        }

        // 1. Aplanar segmentos originales en una lista de palabras con su timestamp.
        $originalWords = $this->flattenSegments($originalSegments);
        if (empty($originalWords)) {
            return [];
        }

        // 2. Tokenizar el texto editado en palabras (preservando puntuación).
        $editedTokens = $this->tokenize($editedText);
        if (empty($editedTokens)) {
            return [];
        }

        // 3. Word-LCS: alinear palabras del editado con las del original.
        //    Cada token editado queda con un anchor (índice en $originalWords) o null.
        $alignment = $this->alignWords(
            array_map(fn ($w) => $this->normalize($w['text']), $originalWords),
            array_map(fn ($t) => $this->normalize($t), $editedTokens),
        );

        // 4. Construir segmentos efectivos agrupando tokens consecutivos por segmento de origen.
        return $this->buildSegments($originalWords, $editedTokens, $alignment);
    }

    /**
     * @return array<int, array{text: string, start: float, end: float, position: int}>
     */
    private function flattenSegments(array $originalSegments): array
    {
        $words = [];
        foreach ($originalSegments as $i => $seg) {
            $position = (int) ($seg['position'] ?? $i);
            $start = (float) $seg['start'];
            $end = (float) $seg['end'];
            $segWords = $this->tokenize($seg['text']);

            $count = max(1, count($segWords));
            $duration = max(0.0, $end - $start);

            foreach ($segWords as $j => $word) {
                // Distribuimos uniformemente los timestamps dentro del segmento — granularidad por palabra
                // (Whisper no nos da timestamps por palabra, así que esto es la mejor aproximación).
                $wStart = $start + ($duration * $j / $count);
                $wEnd = $start + ($duration * ($j + 1) / $count);
                $words[] = [
                    'text' => $word,
                    'start' => $wStart,
                    'end' => $wEnd,
                    'position' => $position,
                ];
            }
        }
        return $words;
    }

    /**
     * Tokeniza preservando la puntuación adyacente al token (ej. "hola." es un token).
     * Usa unicode-aware splitting.
     *
     * @return string[]
     */
    private function tokenize(string $text): array
    {
        $text = trim($text);
        if ($text === '') return [];
        // Split en whitespace (incluyendo \n, tabs, etc).
        $parts = preg_split('/\s+/u', $text);
        return array_values(array_filter($parts, fn ($p) => $p !== ''));
    }

    /**
     * Normaliza para la comparación: minúsculas + sin puntuación de borde + sin acentos.
     * Hace el diff más resistente a typos menores y diferencias de capitalización.
     */
    private function normalize(string $word): string
    {
        $word = mb_strtolower($word, 'UTF-8');
        // Quitar puntuación de borde (.,;:!?¡¿"()[]).
        $word = preg_replace('/^[\p{P}\p{S}]+|[\p{P}\p{S}]+$/u', '', $word) ?? $word;
        // Quitar acentos para tolerancia (à→a, ñ→n, etc.).
        $folded = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $word);
        return $folded !== false && $folded !== '' ? mb_strtolower($folded) : $word;
    }

    /**
     * Alineación palabra a palabra usando anchor-matching con diccionario.
     *
     * Algoritmo:
     *  1. Indexamos las palabras del original por valor normalizado → lista de posiciones.
     *  2. Recorremos las palabras editadas en orden. Para cada una, buscamos su
     *     primera ocurrencia en el original >= un puntero monotónico.
     *  3. Si la palabra no existe en el original O no encontramos posición >= puntero,
     *     queda sin anchor (tratada como "insertada por el usuario").
     *
     * Complejidad: O(M+N) tiempo y O(M+N) memoria (vs O(M*N) del LCS clásico que rompía OOM).
     *
     * Tolerancia a tipos de edición:
     *  - Borrar palabras/oraciones: perfecto (puntero salta los huecos).
     *  - Editar typos: como normalize() quita acentos y puntuación, "está"/"esta" matchean.
     *  - Reordenar bloques: el matching greedy puede perder algunos anchors. Para nuestro caso
     *    de uso (transcripciones — usuarios borran partes, no las reordenan), es aceptable.
     *
     * @param  string[]  $original  palabras normalizadas
     * @param  string[]  $edited    palabras normalizadas
     * @return array<int, ?int>     alineación: para cada índice editado, el índice original o null
     */
    private function alignWords(array $original, array $edited): array
    {
        // Diccionario: word_normalized → posiciones ordenadas en $original.
        $dict = [];
        foreach ($original as $i => $word) {
            $dict[$word][] = $i;
        }

        // Para cada palabra del diccionario, mantenemos un puntero al primer índice
        // todavía no consumido, así avanzamos en O(1) amortizado.
        $cursors = array_fill_keys(array_keys($dict), 0);
        $minIndex = 0;

        $alignment = array_fill(0, count($edited), null);

        foreach ($edited as $ei => $word) {
            if (! isset($dict[$word])) {
                // Palabra que el usuario tipeó pero no estaba en el audio → inserción.
                continue;
            }

            $positions = $dict[$word];
            // Avanzamos el cursor hasta la primera posición >= $minIndex.
            while ($cursors[$word] < count($positions) && $positions[$cursors[$word]] < $minIndex) {
                $cursors[$word]++;
            }
            if ($cursors[$word] >= count($positions)) {
                // Ya consumimos todas las ocurrencias de esa palabra en el original.
                continue;
            }

            $found = $positions[$cursors[$word]];
            $alignment[$ei] = $found;
            $minIndex = $found + 1;
            $cursors[$word]++;
        }

        return $alignment;
    }

    /**
     * Construye los segmentos efectivos: agrupa tokens editados consecutivos que comparten
     * el mismo segmento de origen. Tokens insertados (sin anchor) heredan del vecino.
     *
     * @param  array<int, array{text: string, start: float, end: float, position: int}>  $originalWords
     * @param  string[]  $editedTokens
     * @param  array<int, ?int>  $alignment
     * @return array<int, array{start: float, end: float, text: string, source_segments: int[]}>
     */
    private function buildSegments(array $originalWords, array $editedTokens, array $alignment): array
    {
        $segments = [];
        $current = null;

        // Para tokens sin anchor: heredan del último anchor visto, o del próximo si no hay anterior.
        // Pre-computamos para cada token editado, el segment_position más cercano.
        $resolvedPositions = $this->resolvePositions($originalWords, $alignment);

        foreach ($editedTokens as $ei => $token) {
            $anchor = $alignment[$ei] !== null ? $originalWords[$alignment[$ei]] : null;
            $position = $resolvedPositions[$ei];

            if ($current === null || $current['position'] !== $position) {
                if ($current !== null) {
                    $segments[] = $this->finalizeSegment($current);
                }
                $current = [
                    'text_parts' => [$token],
                    'start' => $anchor['start'] ?? ($current['end'] ?? 0.0),
                    'end' => $anchor['end'] ?? ($current['end'] ?? 0.0),
                    'position' => $position,
                    'source_segments' => [$position],
                ];
            } else {
                $current['text_parts'][] = $token;
                if ($anchor !== null) {
                    $current['end'] = max($current['end'], $anchor['end']);
                    if ($current['start'] === 0.0 || $anchor['start'] < $current['start']) {
                        $current['start'] = $anchor['start'];
                    }
                }
            }
        }

        if ($current !== null) {
            $segments[] = $this->finalizeSegment($current);
        }

        return $segments;
    }

    private function finalizeSegment(array $current): array
    {
        return [
            'start' => (float) $current['start'],
            'end' => (float) max($current['end'], $current['start']),
            'text' => trim(implode(' ', $current['text_parts'])),
            'source_segments' => array_values(array_unique($current['source_segments'])),
        ];
    }

    /**
     * Para cada token editado, decide a qué segmento original "pertenece":
     * - Si tiene anchor: el segmento de la palabra original.
     * - Si es insertado: hereda del anchor anterior; si no hay, del próximo; si tampoco, posición 0.
     *
     * @param  array<int, array{position: int}>  $originalWords
     * @param  array<int, ?int>  $alignment
     * @return int[]
     */
    private function resolvePositions(array $originalWords, array $alignment): array
    {
        $n = count($alignment);
        $resolved = array_fill(0, $n, 0);

        // Pasada hacia adelante: cada token toma el position del último anchor visto
        $lastPos = null;
        for ($i = 0; $i < $n; $i++) {
            if ($alignment[$i] !== null) {
                $lastPos = $originalWords[$alignment[$i]]['position'];
            }
            $resolved[$i] = $lastPos;
        }

        // Pasada hacia atrás: tokens al inicio sin anchor previo toman el próximo
        $nextPos = null;
        for ($i = $n - 1; $i >= 0; $i--) {
            if ($alignment[$i] !== null) {
                $nextPos = $originalWords[$alignment[$i]]['position'];
            }
            if ($resolved[$i] === null) {
                $resolved[$i] = $nextPos ?? 0;
            }
        }

        return $resolved;
    }
}
