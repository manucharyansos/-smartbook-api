<?php

return [
    'trial_days' => (int) env('TRIAL_DAYS', 14),

    'bank' => [
        'company_name' => env('BILLING_BANK_COMPANY_NAME', 'Vizit LLC'),
        'bank_name' => env('BILLING_BANK_NAME', 'IDBank'),
        'account_number' => env('BILLING_BANK_ACCOUNT_NUMBER', 'COMING_SOON'),
        'recipient_name' => env('BILLING_BANK_RECIPIENT_NAME', 'Vizit Billing'),
        'note_template' => env('BILLING_BANK_NOTE_TEMPLATE', 'Invoice #:id / Business #:business'),
    ],

    'idram' => [
        'wallet_id' => env('BILLING_IDRAM_WALLET_ID', 'COMING_SOON'),
        'note_template' => env('BILLING_IDRAM_NOTE_TEMPLATE', 'Invoice #:id'),
    ],

    'frontend_base_url' => rtrim(env('FRONTEND_APP_URL', env('APP_FRONTEND_URL', env('APP_URL', 'http://localhost'))), '/'),

    'providers' => [
        'default' => env('BILLING_DEFAULT_PROVIDER', 'idbank_mock'),
        'mode' => env('BILLING_PROVIDER_MODE', 'mock'),
        'idbank' => [
            'merchant_id' => env('IDBANK_MERCHANT_ID'),
            'terminal_id' => env('IDBANK_TERMINAL_ID'),
            'public_key' => env('IDBANK_PUBLIC_KEY'),
            'secret' => env('IDBANK_SECRET'),
            'webhook_secret' => env('IDBANK_WEBHOOK_SECRET'),
            'checkout_base_url' => env('IDBANK_CHECKOUT_BASE_URL', 'https://payments.idbank.am'),
            'return_url' => env('IDBANK_RETURN_URL', rtrim(env('FRONTEND_APP_URL', env('APP_FRONTEND_URL', env('APP_URL', 'http://localhost'))), '/') . '/payment-return'),
            'cancel_url' => env('IDBANK_CANCEL_URL', rtrim(env('FRONTEND_APP_URL', env('APP_FRONTEND_URL', env('APP_URL', 'http://localhost'))), '/') . '/payment-return'),
            'webhook_url' => env('IDBANK_WEBHOOK_URL', rtrim(env('APP_URL', 'http://localhost'), '/') . '/api/webhooks/payments/idbank'),
        ],
        'idbank_mock' => [
            'hosted_page_url' => env('IDBANK_MOCK_HOSTED_PAGE_URL', rtrim(env('FRONTEND_APP_URL', env('APP_FRONTEND_URL', env('APP_URL', 'http://localhost'))), '/') . '/mock-bank/idbank'),
        ],
    ],
];
