<?php

namespace App\Providers;

use App\Models\ClientAccount;
use App\Models\User;
use App\Policies\StaffPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(User::class, StaffPolicy::class);

        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $frontendUrl = rtrim((string) config('app.frontend_url', 'https://vizit.am'), '/');
            $params = [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ];

            if ($notifiable instanceof ClientAccount) {
                $params['audience'] = 'client';
            }

            return $frontendUrl . '/reset-password?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        });
    }
}
