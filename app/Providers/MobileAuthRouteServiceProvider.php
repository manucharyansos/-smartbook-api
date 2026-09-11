<?php

namespace App\Providers;

use App\Http\Controllers\Api\MobileUnifiedAuthController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class MobileAuthRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/mobile/auth')
            ->group(function () {
                Route::post('/login', [MobileUnifiedAuthController::class, 'login'])
                    ->middleware('throttle:10,1');
            });
    }
}
