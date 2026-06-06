<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureOnboardingCompleted
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $business = $user->business;
        if (!$business) {
            return response()->json([
                'message' => 'Business context required.',
                'code' => 'business_required',
            ], 403);
        }

        if ($this->allowsPreOnboardingRequest($request)) {
            return $next($request);
        }

        if (!($business->is_onboarding_completed ?? false)) {
            return response()->json([
                'message' => 'Onboarding is not completed.',
                'code' => 'onboarding_required',
            ], 403);
        }

        return $next($request);
    }

    private function allowsPreOnboardingRequest(Request $request): bool
    {
        $path = trim($request->path(), '/');
        $method = strtoupper($request->method());

        if ($path === 'api/business/settings' && in_array($method, ['GET', 'PATCH'], true)) {
            return true;
        }

        if ($path === 'api/business/onboarding-status' && $method === 'GET') {
            return true;
        }

        if ($path === 'api/business/complete-onboarding' && $method === 'POST') {
            return true;
        }

        if ($path === 'api/services' && $method === 'POST') {
            return true;
        }

        return $path === 'api/staff' && $method === 'POST';
    }
}
