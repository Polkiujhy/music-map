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

    'streaming_accounts' => [
        'spotify' => [
            'client_id' => env('SPOTIFY_CLIENT_ID'),
            'client_secret' => env('SPOTIFY_CLIENT_SECRET'),
            'redirect_uri' => env('SPOTIFY_REDIRECT_URI'),
        ],
        'youtube' => [
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => env('YOUTUBE_REDIRECT_URI'),
        ],
    ],

    'playlist_import' => [
        'youtube' => [
            'api_key' => env('YOUTUBE_API_KEY'),
        ],
    ],

    'export_matching' => [
        'spotify' => [
            'client_id' => env('SPOTIFY_CLIENT_ID'),
            'client_secret' => env('SPOTIFY_CLIENT_SECRET'),
        ],
        'youtube' => [
            'api_key' => env('YOUTUBE_API_KEY'),
            'daily_search_limit' => (int) env('YOUTUBE_EXPORT_SEARCH_DAILY_LIMIT', 100),
        ],
    ],

    'youtube_write_admission' => [
        'daily_limit' => env('YOUTUBE_WRITE_DAILY_LIMIT', '5'),
    ],

    'managed_export' => [
        'socket' => env(
            'MUSIC_MAP_MANAGED_EXPORT_SOCKET',
            '/run/s-manager-music-map-managed-export/broker.sock',
        ),
        'providers' => [
            'spotify' => [
                'account_id' => env('MUSIC_MAP_MANAGED_EXPORT_SPOTIFY_ACCOUNT_ID'),
                'scopes' => env('MUSIC_MAP_MANAGED_EXPORT_SPOTIFY_SCOPES'),
            ],
            'youtube' => [
                'account_id' => env('MUSIC_MAP_MANAGED_EXPORT_YOUTUBE_ACCOUNT_ID'),
                'scopes' => env('MUSIC_MAP_MANAGED_EXPORT_YOUTUBE_SCOPES'),
            ],
        ],
    ],

    'platform_access' => [
        'spotify' => [
            'technical' => [
                'client_id' => env('SPOTIFY_CLIENT_ID'),
                'client_secret' => env('SPOTIFY_CLIENT_SECRET'),
                'refresh_token' => env('SPOTIFY_TECHNICAL_REFRESH_TOKEN'),
                'expected_account_id' => env('SPOTIFY_TECHNICAL_EXPECTED_ACCOUNT_ID'),
                'account_id' => env('SPOTIFY_TECHNICAL_ACCOUNT_ID'),
                'market' => env('SPOTIFY_TECHNICAL_MARKET'),
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
