<?php

$isNonProduction = in_array((string) env('APP_ENV', 'production'), ['local', 'testing'], true);

return [
    'trial_days' => (int) env('TRIAL_DAYS', 14),

    'frontend_base_url' => rtrim(env('FRONTEND_APP_URL', env('APP_FRONTEND_URL', env('APP_URL', 'http://localhost'))), '/'),

    'allow_mock_payments' => filter_var(
        env('BILLING_ALLOW_MOCK_PAYMENTS', $isNonProduction),
        FILTER_VALIDATE_BOOL
    ),

    'providers' => [
        'default' => env('BILLING_DEFAULT_PROVIDER', $isNonProduction ? 'idbank_mock' : 'idbank'),
        'mode' => env('BILLING_PROVIDER_MODE', $isNonProduction ? 'mock' : 'live'),
        'idram' => [
            'live_enabled' => filter_var(env('IDRAM_LIVE_ENABLED', false), FILTER_VALIDATE_BOOL),
            'rec_account' => env('IDRAM_REC_ACCOUNT'),
            'secret_key' => env('IDRAM_SECRET_KEY'),
            'email' => env('IDRAM_EMAIL', 'info@vizit.am'),
            'checkout_minutes' => (int) env('IDRAM_CHECKOUT_MINUTES', 30),
        ],
        'idbank' => [
            'live_enabled' => filter_var(env('IDBANK_LIVE_ENABLED', false), FILTER_VALIDATE_BOOL),
            // Keep live checkout closed until the merchant contract's exact request,
            // signature and callback field mapping has been implemented and reviewed.
            'contract_mapping_ready' => filter_var(env('IDBANK_CONTRACT_MAPPING_READY', false), FILTER_VALIDATE_BOOL),
            'merchant_id' => env('IDBANK_MERCHANT_ID'),
            'terminal_id' => env('IDBANK_TERMINAL_ID'),
            'public_key' => env('IDBANK_PUBLIC_KEY'),
            'secret' => env('IDBANK_SECRET'),
            'webhook_secret' => env('IDBANK_WEBHOOK_SECRET'),
            'signature_algorithm' => env('IDBANK_SIGNATURE_ALGORITHM', 'sha256'),
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
