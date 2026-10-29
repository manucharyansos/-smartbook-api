<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\EnforceFourDigitBookingOtp;
use App\Http\Middleware\EnsureBusinessIsBillable;
use App\Http\Middleware\PreventRepeatBusinessTrial;
use App\Http\Middleware\SecurityHeaders;
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
        $middleware->append(SecurityHeaders::class);
        $middleware->appendToGroup('api', EnforceFourDigitBookingOtp::class);
        $middleware->appendToGroup('api', PreventRepeatBusinessTrial::class);

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
        // A super admin sees the real cause of a server error in the panel, so a
        // screenshot is enough to diagnose it. Everyone else gets the generic reply.
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (! $request->is('api/admin/*') || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $e instanceof \Illuminate\Validation\ValidationException || $e instanceof \Illuminate\Auth\AuthenticationException) {
                return null;
            }
            $admin = $request->user();
            if (! $admin instanceof \App\Models\Admin || $admin->role !== \App\Models\Admin::ROLE_SUPER_ADMIN) {
                return null;
            }

            return response()->json(['message' => 'Server Error', 'debug' => class_basename($e).': '
                .\Illuminate\Support\Str::limit($e->getMessage(), 400).' @ '.basename($e->getFile()).':'.$e->getLine()], 500);
        });
    })->create();
