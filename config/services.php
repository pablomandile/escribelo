<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'groq' => [
        'key' => env('GROQ_APIKEY'),
        'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
        'model' => env('GROQ_MODEL', 'llama-3.1-8b-instant'),
        'free_tier' => [
            'requests_per_day' => (int) env('GROQ_FREE_RPD', 14400),
            'tokens_per_day' => (int) env('GROQ_FREE_TPD', 500000),
        ],
    ],

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'summary_model' => env('OLLAMA_SUMMARY_MODEL', 'gemma3:12b'),
    ],

    'remote_worker' => [
        'base_url' => env('REMOTE_WORKER_URL'),
        'token' => env('REMOTE_WORKER_TOKEN'),
        'timeout' => (int) env('REMOTE_WORKER_TIMEOUT', 14400),
        'health_timeout' => (int) env('REMOTE_WORKER_HEALTH_TIMEOUT', 5),

        // Local management — when this host runs the worker process, the admin
        // panel can start/stop/restart it. Disable on a hosting environment
        // that only consumes a remote URL.
        'manage_locally' => filter_var(env('REMOTE_WORKER_MANAGE_LOCALLY', true), FILTER_VALIDATE_BOOLEAN),
        'host' => env('REMOTE_WORKER_BIND_HOST', '127.0.0.1'),
        'port' => (int) env('REMOTE_WORKER_BIND_PORT', 8765),
        'python' => env('REMOTE_WORKER_PYTHON'),  // null → falls back to PATH 'python'
    ],

    'google' => [
        // Aceptamos tanto los nombres estándar (GOOGLE_*) como los que pusiste
        // en español (ID_CLIENTE, SECRETO_CLIENTE) para que funcione sin que
        // tengas que renombrar nada. Recomendado: usar GOOGLE_* (convención).
        'client_id' => env('GOOGLE_CLIENT_ID', env('ID_CLIENTE')),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', env('SECRETO_CLIENTE')),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('URI_REDIRECCION', 'http://localhost:8000/auth/google/callback')),
    ],

    'cloudflared' => [
        // Path al binario cloudflared. Vacío → busca 'cloudflared' en el PATH.
        'binary' => env('CLOUDFLARED_BIN'),
        // Nombre o UUID del tunnel. Vacío → usa la config por defecto en ~/.cloudflared/config.yml
        'tunnel' => env('CLOUDFLARED_TUNNEL'),
        // Igual que el worker: en hosting (sin cloudflared local) poné false para ocultar el panel.
        'manage_locally' => filter_var(env('CLOUDFLARED_MANAGE_LOCALLY', true), FILTER_VALIDATE_BOOLEAN),
    ],

];
