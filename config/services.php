<?php

return [
    'hepsiburada' => [
        'endpoints' => [
            'production' => env('HEPSIBURADA_LISTING_URL', 'https://listing-external.hepsiburada.com'),
            'stage' => env('HEPSIBURADA_LISTING_STAGE_URL', 'https://listing-external-sit.hepsiburada.com'),
        ],
    ],

    'trendyol' => [
        'endpoints' => [
            'production' => 'https://apigw.trendyol.com',
            'stage' => 'https://stageapigw.trendyol.com',
        ],
        'sync_rate_limit_per_minute' => (int) env('TRENDYOL_SYNC_RATE_LIMIT_PER_MINUTE', 60),
    ],

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

];
