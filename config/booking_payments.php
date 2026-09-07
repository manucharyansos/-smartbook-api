<?php

$isNonProduction = in_array((string) env('APP_ENV', 'production'), ['local', 'testing'], true);

return [
    'enabled' => filter_var(env('BOOKING_PAYMENTS_ENABLED', $isNonProduction), FILTER_VALIDATE_BOOL),
    'provider' => env('BOOKING_PAYMENT_PROVIDER', $isNonProduction ? 'idbank_mock' : 'idbank'),
    'deposit_percent' => (int) env('BOOKING_DEPOSIT_PERCENT', 20),
    'session_minutes' => (int) env('BOOKING_PAYMENT_SESSION_MINUTES', 30),
];
