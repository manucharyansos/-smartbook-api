<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\Request;

class BillingSubscriptionController extends Controller
{
    /**
     * POST /api/billing/pause
     */
    public function pause(Request $request)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        $business = $actor->business;
        $sub = $business?->subscription;
        if (!$sub) {
            return response()->json(['message' => 'No subscription found.'], 404);
        }

        // idempotent
        if ($sub->status === Subscription::STATUS_SUSPENDED) {
            return response()->json(['data' => $this->payload($sub)], 200);
        }

        $sub->status = Subscription::STATUS_SUSPENDED;
        $sub->suspended_at = now();
        $sub->save();

        return response()->json(['data' => $this->payload($sub)], 200);
    }

    /**
     * POST /api/billing/resume
     */
    public function resume(Request $request)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        $business = $actor->business;
        $sub = $business?->subscription;
        if (!$sub) {
            return response()->json(['message' => 'No subscription found.'], 404);
        }

        // If we are resuming from suspension, go back to trialing if trial is still valid.
        $now = now();
        if ($sub->trial_ends_at && $now->lt($sub->trial_ends_at)) {
            $sub->status = Subscription::STATUS_TRIALING;
        } else {
            $sub->status = Subscription::STATUS_ACTIVE;
        }

        $sub->suspended_at = null;
        $sub->save();

        return response()->json(['data' => $this->payload($sub)], 200);
    }

    /**
     * POST /api/billing/cancel-subscription
     */
    public function cancel(Request $request)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        $business = $actor->business;
        $sub = $business?->subscription;
        if (!$sub) {
            return response()->json(['message' => 'No subscription found.'], 404);
        }

        // For now: immediate cancel (simple SaaS logic without provider integration)
        $sub->status = Subscription::STATUS_CANCELED;
        $sub->canceled_at = now();
        $sub->cancel_at_period_end = false;
        $sub->save();

        return response()->json(['data' => $this->payload($sub)], 200);
    }

    private function payload(Subscription $sub): array
    {
        $computed = $sub->computedStatus();
        $trialEndsAt = $sub->trial_ends_at ? $sub->trial_ends_at->toISOString() : null;
        $daysLeft = $sub->trial_ends_at ? max(0, now()->diffInDays($sub->trial_ends_at, false)) : null;

        return [
            'status' => $computed,
            'trial_ends_at' => $trialEndsAt,
            'days_left' => $daysLeft,
            'cancel_at_period_end' => (bool)$sub->cancel_at_period_end,
            'canceled_at' => $sub->canceled_at?->toISOString(),
            'suspended_at' => $sub->suspended_at?->toISOString(),
        ];
    }
}
