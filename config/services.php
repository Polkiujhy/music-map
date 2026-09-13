<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'platform_access' => [
        'spotify' => [
            'technical' => [
                'client_id' => env('SPOTIFY_CLIENT_ID'),
                'client_secret' => env('SPOTIFY_CLIENT_SECRET'),
                'refresh_token' => env('SPOTIFY_TECHNICAL_REFRESH_TOKEN'),
                'expected_account_id' => env('SPOTIFY_TECHNICAL_EXPECTED_ACCOUNT_ID'),
                'account_id' => env('SPOTIFY_TECHNICAL_ACCOUNT_ID'),
                'scopes' => env('SPOTIFY_TECHNICAL_SCOPES'),
            ],
        ],
        'youtube' => [
            'technical' => [
                'client_id' => env('GOOGLE_CLIENT_ID'),
                'client_secret' => env('GOOGLE_CLIENT_SECRET'),
                'refresh_token' => env('YOUTUBE_TECHNICAL_REFRESH_TOKEN'),
                'expected_account_id' => env('YOUTUBE_TECHNICAL_EXPECTED_ACCOUNT_ID'),
                'account_id' => env('YOUTUBE_TECHNICAL_ACCOUNT_ID'),
                'scopes' => env('YOUTUBE_TECHNICAL_SCOPES'),
            ],
        ],
    ],

];
