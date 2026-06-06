<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureBusinessContext
{
    /**
     * Strict SaaS mode:
     * - user must be linked to a business
     * - business onboarding must be completed
     * - staff users must be active
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (!$user->business_id) {
            return response()->json([
                'message' => 'Business context required.',
                'code' => 'business_required',
            ], 403);
        }

        $business = $user->business;
        if (!$business) {
            return response()->json([
                'message' => 'Business context required.',
                'code' => 'business_required',
            ], 403);
        }

        if (($user->role ?? null) === 'staff' && !($user->is_active ?? true)) {
            return response()->json([
                'message' => 'Account is inactive.',
                'code' => 'inactive_user',
            ], 403);
        }

        return $next($request);
    }
}
