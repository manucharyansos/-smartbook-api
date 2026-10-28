<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->remove('X-Powered-By');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $policy = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";
        $nonce = $request->attributes->get('idram_csp_nonce');
        if ($request->routeIs('payments.idram.checkout') && $response->getStatusCode() === 200
            && is_string($nonce) && preg_match('/^[A-Za-z0-9+\/]{24}$/D', $nonce)) {
            $policy = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; "
                ."form-action https://banking.idram.am; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'";
        }
        $response->headers->set('Content-Security-Policy', $policy);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        $containsSensitiveAccountData = $request->bearerToken()
            || $request->hasHeader('X-Guest-Token')
            || $request->is('api/auth/*')
            || $request->is('api/client/auth/*')
            || $request->is('api/public/bookings/*');

        if ($containsSensitiveAccountData) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        if (app()->environment('production') || $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
