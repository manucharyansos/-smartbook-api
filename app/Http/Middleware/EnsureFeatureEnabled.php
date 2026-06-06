<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $featureKey)
    {
        $user = $request->user();
        if (!$user) return $next($request);

        // super admin bypass
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $next($request);
        }

        $business = $user->business;
        $sub = $business?->subscription;

        if (!$sub) {
            return response()->json([
                'message' => 'Subscription required.',
                'code' => 'no_subscription',
            ], 402);
        }

        if (!$sub->hasFeature($featureKey)) {
            return response()->json([
                'message' => 'This feature is not available on your plan.',
                'code' => 'feature_not_allowed',
                'feature' => $featureKey,
            ], 403);
        }

        return $next($request);
    }
}
