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


    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/api/auth/social/google/callback'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI', '/api/auth/social/facebook/callback'),
    ],

    'social_auth' => [
        'enabled' => filter_var(env('SOCIAL_AUTH_ENABLED', false), FILTER_VALIDATE_BOOL),
        'frontend_urls' => array_values(array_filter(array_map('trim', explode(',', (string) env('SOCIAL_AUTH_FRONTEND_URLS', env('FRONTEND_APP_URL', env('APP_FRONTEND_URL', ''))))))),
        'providers' => [
            'google' => [
                'enabled' => filter_var(env('SOCIAL_AUTH_GOOGLE_ENABLED', false), FILTER_VALIDATE_BOOL),
            ],
            'facebook' => [
                'enabled' => filter_var(env('SOCIAL_AUTH_FACEBOOK_ENABLED', false), FILTER_VALIDATE_BOOL),
            ],
        ],
    ],


    'public_booking' => [
        'frontend_url' => env(
            'PUBLIC_BOOKING_FRONTEND_URL',
            env('FRONTEND_APP_URL', env('APP_FRONTEND_URL', 'https://vizit.am'))
        ),
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'from' => env('SMS_FROM'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM', env('WHATSAPP_FROM')),
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
    ],

    'telegram' => [
        'enabled' => filter_var(env('TELEGRAM_BOOKING_NOTIFICATIONS_ENABLED', false), FILTER_VALIDATE_BOOL),
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'booking_chat_id' => env('TELEGRAM_BOOKING_CHAT_ID'),
        'booking_chat_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('TELEGRAM_BOOKING_CHAT_IDS', ''))))),
    ],

];
