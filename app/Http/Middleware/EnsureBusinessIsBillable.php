<?php
// app/Http/Middleware/EnsureBusinessIsBillable.php

namespace App\Http\Middleware;

use App\Models\Subscription;
use Closure;
use Illuminate\Http\Request;

class EnsureBusinessIsBillable
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) return $next($request);

        // Super admin bypass for business app users (not Admin panel)
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $next($request);
        }

        $business = $user->business;
        if (!$business) return $next($request);

        // Hard stops
        if ($business->status === 'suspended') {
            return response()->json([
                'message' => 'Business is suspended. Please contact support.',
                'code' => 'business_suspended',
                'support_whatsapp' => config('app.support_whatsapp'),
            ], 402);
        }

        if ($business->billing_status === 'suspended') {
            return response()->json([
                'message' => 'Billing is suspended. Please contact support.',
                'code' => 'billing_suspended',
                'support_whatsapp' => config('app.support_whatsapp'),
            ], 402);
        }

        $sub = $business->subscription;
        if (!$sub) {
            return response()->json([
                'message' => 'No subscription found for this business.',
                'code' => 'no_subscription',
                'support_whatsapp' => config('app.support_whatsapp'),
            ], 402);
        }

        $computed = $sub->computedStatus();
        $trialEndsAt = $sub->trial_ends_at ? $sub->trial_ends_at->toISOString() : null;
        $daysLeft = $sub->trial_ends_at ? max(0, now()->diffInDays($sub->trial_ends_at, false)) : null;

        $payload = [
            'code' => 'subscription_inactive',
            'status' => $computed,
            'trial_ends_at' => $trialEndsAt,
            'days_left' => $daysLeft,
            'business_type' => $business->business_type,
            'plans_url' => '/api/plans?business_type=' . ($business->business_type ?: 'services'),
            'support_whatsapp' => config('app.support_whatsapp'),
        ];

        if (in_array($computed, [Subscription::STATUS_EXPIRED, Subscription::STATUS_SUSPENDED, Subscription::STATUS_CANCELED], true)) {
            return response()->json([
                'message' => 'Subscription inactive (expired/suspended/canceled).',
                ...$payload,
            ], 402);
        }

        if (!$sub->isActive()) {
            return response()->json([
                'message' => 'Subscription inactive or trial expired.',
                ...$payload,
            ], 402);
        }

        return $next($request);
    }
}
