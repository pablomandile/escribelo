<?php

namespace App\Services\Summarizer;

interface SummarizerInterface
{
    /**
     * Summarize a transcription text. Returns:
     *   [
     *     'summary' => string,
     *     'key_points' => string[],
     *     'tokens_used' => int,
     *     'model' => string,
     *   ]
     *
     * The optional $onProgress callback receives associative arrays with keys like:
     *   - phase: 'partial'|'reducing'|'single'
     *   - chunk: int (1-based, when chunked)
     *   - total: int (total chunks)
     *   - tokens_so_far: int (cumulative)
     * Implementations should call it whenever a meaningful step starts/ends.
     *
     * @throws SummarizerException
     */
    public function summarize(string $text, ?string $language = null, ?callable $onProgress = null): array;
}
