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
        'authorize_url' => env('GOOGLE_AUTHORIZE_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
        'token_url' => env('GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
        'userinfo_url' => env('GOOGLE_USERINFO_URL', 'https://openidconnect.googleapis.com/v1/userinfo'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI', '/api/auth/social/facebook/callback'),
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v26.0'),
        'authorize_url' => sprintf(
            'https://www.facebook.com/%s/dialog/oauth',
            env('FACEBOOK_GRAPH_VERSION', 'v26.0')
        ),
        'token_url' => sprintf(
            'https://graph.facebook.com/%s/oauth/access_token',
            env('FACEBOOK_GRAPH_VERSION', 'v26.0')
        ),
        'userinfo_url' => sprintf(
            'https://graph.facebook.com/%s/me',
            env('FACEBOOK_GRAPH_VERSION', 'v26.0')
        ),
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
        'excluded_slugs' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'PUBLIC_EXCLUDED_BUSINESS_SLUGS',
                env('APP_ENV', 'production') === 'production' ? 'test,test-2' : ''
            ))
        ))),
        'excluded_slug_prefixes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'PUBLIC_EXCLUDED_BUSINESS_SLUG_PREFIXES',
                env('APP_ENV', 'production') === 'production' ? 'vizit-e2e-,vizit-medical-qa' : ''
            ))
        ))),
        'reschedule_cutoff_hours' => max(0, (int) env('PUBLIC_BOOKING_RESCHEDULE_CUTOFF_HOURS', 12)),
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
