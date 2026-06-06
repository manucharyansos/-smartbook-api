<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\EnsureBusinessIsBillable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'ensure.billable' => EnsureBusinessIsBillable::class,
            'ensure.seat' => App\Http\Middleware\EnsureSeatAvailable::class,
            'ensure.feature' => App\Http\Middleware\EnsureFeatureEnabled::class,
            // Strict SaaS mode
            'ensure.business' => App\Http\Middleware\EnsureBusinessContext::class,
            'ensure.onboarded' => App\Http\Middleware\EnsureOnboardingCompleted::class,
            'role' => App\Http\Middleware\EnsureRole::class,
            'superadmin' => App\Http\Middleware\EnsureSuperAdmin::class,

            // legacy / optional
            'admin' => AdminMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
