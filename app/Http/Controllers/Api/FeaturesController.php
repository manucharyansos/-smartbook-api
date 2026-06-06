<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class FeaturesController extends Controller
{
    /**
     * GET /api/features
     *
     * Used by the frontend to:
     * - decide which screens/blocks to show
     * - display subscription status (trial days left, etc.)
     */
    public function index(Request $request)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        $business = $actor->business;
        $sub = $business?->subscription;

        $computed = $sub?->computedStatus();
        $trialEndsAt = $sub?->trial_ends_at ? $sub->trial_ends_at->toISOString() : null;
        $daysLeft = $sub?->trial_ends_at ? max(0, now()->diffInDays($sub->trial_ends_at, false)) : null;

        return response()->json([
            'data' => [
                'business_id' => $business?->id,
                'business_type' => $business?->business_type,

                // plan
                'plan_code' => $sub?->plan?->code,
                'plan_name' => $sub?->plan?->name,

                // feature flags (snapshot-first)
                'features' => $sub ? $sub->features() : [],

                // subscription status for UI
                'subscription' => [
                    'status' => $computed,
                    'trial_ends_at' => $trialEndsAt,
                    'days_left' => $daysLeft,
                    'cancel_at_period_end' => (bool)($sub?->cancel_at_period_end ?? false),
                ],
            ],
        ]);
    }
}
