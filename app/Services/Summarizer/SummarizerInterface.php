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
     * @throws SummarizerException
     */
    public function summarize(string $text, ?string $language = null): array;
}
