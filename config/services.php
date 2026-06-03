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

    /*
    |--------------------------------------------------------------------------
    | N8n Chatbot Configuration
    |--------------------------------------------------------------------------
    |
    | Konfigurasi untuk integrasi dengan n8n workflow AI chatbot.
    | URL webhook dan path folder uploads diatur di file .env.
    |
    */
    'n8n' => [
        'webhook_url' => env('N8N_WEBHOOK_URL', 'http://localhost:5678/webhook/excel-chat'),
        'upload_path' => env('UPLOAD_EXCEL_PATH', 'uploads'),
    ],

    'ollama' => [
        'enabled' => env('USE_LOCAL_OLLAMA', false),
        'api_url' => env('OLLAMA_API_URL', 'http://127.0.0.1:11434'),
        'model' => env('OLLAMA_MODEL', 'llama2'),
        'temperature' => env('OLLAMA_TEMPERATURE', 0.0),
        'max_tokens' => env('OLLAMA_MAX_TOKENS', 2048),
        'use_http' => env('OLLAMA_USE_HTTP', true),
    ],
];
