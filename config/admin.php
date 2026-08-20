<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Bootstrap super administrator
    |--------------------------------------------------------------------------
    |
    | These values are used only by AdminSeeder. Keep the credentials in the
    | server environment and never commit a real password to the repository.
    | Existing passwords are preserved unless rotation is explicitly enabled.
    |
    */
    'seed' => [
        'name' => env('ADMIN_SEED_NAME', 'Vizit Super Admin'),
        'email' => env('ADMIN_SEED_EMAIL'),
        'password' => env('ADMIN_SEED_PASSWORD'),
        'rotate_password' => filter_var(
            env('ADMIN_SEED_ROTATE_PASSWORD', false),
            FILTER_VALIDATE_BOOL,
        ),
    ],
];
