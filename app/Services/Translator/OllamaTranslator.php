<?php

namespace App\Services\Translator;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Modo local: Ollama en la misma máquina que la app. */
class OllamaTranslator implements TranslatorInterface
{
    public function translateChunk(string $text, string $targetLanguage, ?string $sourceLanguage = null): array
    {
        $baseUrl = rtrim((string) config('services.ollama.base_url', 'http://localhost:11434'), '/');
        $model = (string) config('services.ollama.translate_model');

        try {
            $response = Http::timeout(600)
                ->acceptJson()
                ->post($baseUrl.'/api/chat', [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => TranslationPrompt::system($targetLanguage, $sourceLanguage)],
                        ['role' => 'user', 'content' => $text],
                    ],
                    'stream' => false,
                    'options' => TranslationPrompt::ollamaOptions(),
                    'keep_alive' => '30m',
                ]);
        } catch (\Throwable $e) {
            throw new TranslatorException('No se pudo contactar a Ollama: '.$e->getMessage().' (¿Está corriendo en '.$baseUrl.'?)', previous: $e);
        }

        if (! $response->successful()) {
            Log::error('Ollama translate failure', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);
            throw new TranslatorException('Ollama devolvió un error ('.$response->status().').');
        }

        $data = $response->json();
        $translated = TranslationPrompt::clean((string) ($data['message']['content'] ?? ''));
        if ($translated === '') {
            throw new TranslatorException('Ollama devolvió una traducción vacía.');
        }

        return [
            'text' => $translated,
            'tokens_used' => (int) ($data['prompt_eval_count'] ?? 0) + (int) ($data['eval_count'] ?? 0),
            'model' => $model,
        ];
    }
}
