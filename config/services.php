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

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'fallback_model' => env('GEMINI_FALLBACK_MODEL', 'gemini-2.5-flash-lite'),
        'retries' => (int) env('GEMINI_RETRIES', 5),
        'retry_sleep_ms' => (int) env('GEMINI_RETRY_SLEEP_MS', 1500),
        'timeout' => (int) env('GEMINI_TIMEOUT', 900),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
    ],

    'ai' => [
        'provider' => env('AI_PROVIDER', 'gemini'),
        'syllabus_provider' => env('SYLLABUS_AI_PROVIDER', 'gemini'),
        'student_provider' => env('STUDENT_AI_PROVIDER', 'gemini'),
        'question_batch_size' => (int) env('AI_QUESTION_BATCH_SIZE', 5),
    ],

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
        'base_urls' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('OLLAMA_BASE_URLS', env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'))),
        ))),
        'model' => env('OLLAMA_MODEL', 'gemma3:4b'),
        'context_length' => (int) env('OLLAMA_CONTEXT_LENGTH', 4096),
        'keep_alive' => env('OLLAMA_KEEP_ALIVE', '30m'),
        'retries' => (int) env('OLLAMA_RETRIES', 2),
        'retry_sleep_ms' => (int) env('OLLAMA_RETRY_SLEEP_MS', 1000),
        'connect_timeout' => (int) env('OLLAMA_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 3600),
        'pool_wait_timeout' => (int) env('OLLAMA_POOL_WAIT_TIMEOUT', 7200),
        'pool_poll_ms' => (int) env('OLLAMA_POOL_POLL_MS', 250),
    ],

];
