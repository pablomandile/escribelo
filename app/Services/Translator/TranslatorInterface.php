<?php

namespace App\Services\Translator;

interface TranslatorInterface
{
    /**
     * Traduce UN trozo de texto (el job se encarga de cortar la transcripción
     * y de ir guardando el avance). Devuelve:
     *   ['text' => string, 'tokens_used' => int, 'model' => string]
     *
     * @throws TranslatorException
     */
    public function translateChunk(string $text, string $targetLanguage, ?string $sourceLanguage = null): array;
}
