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

    'onpe' => [
        'enabled'         => env('ONPE_ENABLED', true),
        'driver'          => env('ONPE_DRIVER', 'auto'),
        'base_url'        => env('ONPE_BASE_URL', 'https://consultaelectoral.onpe.gob.pe'),
        'timeout'         => (float) env('ONPE_TIMEOUT', 8.0),
        'connect_timeout' => (float) env('ONPE_CONNECT_TIMEOUT', 4.0),
        'retry_times'     => (int) env('ONPE_RETRY_TIMES', 2),
        'retry_sleep_ms'  => (int) env('ONPE_RETRY_SLEEP_MS', 150),
        'cache_ttl'       => (int) env('ONPE_CACHE_TTL', 86400),
        'api_token'       => env('ONPE_API_TOKEN', ''),
        'api_url'         => env('ONPE_API_URL', 'https://onpe-brige.vercel.app/api/onpe/{dni}'),
        'sync_key'        => env('ONPE_SYNC_KEY', 'onpe_bridge_secret_key_2026'),
    ],
];




