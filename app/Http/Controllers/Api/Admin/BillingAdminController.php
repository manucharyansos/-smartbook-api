<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\Request;

class BillingAdminController extends Controller
{
    private function requireSuperAdmin(Request $request): void
    {
        $user = $request->user();
        if (!$user || !$user->isSuperAdmin()) {
            abort(403);
        }
    }

    // PATCH /api/admin/businesses/{business}/suspend
    public function suspend(Request $request, Business $business)
    {
        $this->requireSuperAdmin($request);

        $business->update([
            'billing_status' => 'suspended',
            'suspended_at' => now(),
        ]);

        // Optional: mark subscription suspended too
        if ($business->subscription) {
            $business->subscription->update([
                'status' => Subscription::STATUS_SUSPENDED,
                'suspended_at' => now(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    // PATCH /api/admin/businesses/{business}/restore
    public function restore(Request $request, Business $business)
    {
        $this->requireSuperAdmin($request);

        $business->update([
            'billing_status' => 'active',
            'suspended_at' => null,
        ]);

        if ($business->subscription && $business->subscription->status === Subscription::STATUS_SUSPENDED) {
            // restore to active (or trialing if still in trial)
            $business->subscription->update([
                'status' => Subscription::STATUS_ACTIVE,
                'suspended_at' => null,
            ]);
        }

        return response()->json(['ok' => true]);
    }

    // PATCH /api/admin/businesses/{business}/plan
    // body: { "plan_code": "pro" }
    public function changePlan(Request $request, Business $business)
    {
        $this->requireSuperAdmin($request);

        $data = $request->validate([
            'plan_code' => ['required','string','exists:plans,code'],
            // later in Phase 2: apply_to_existing_subscribers (hybrid button)
        ]);

        $plan = Plan::where('code', $data['plan_code'])->firstOrFail();

        $sub = Subscription::firstOrCreate(
            ['business_id' => $business->id],
            [
                'plan_id' => $plan->id,
                'status' => Subscription::STATUS_ACTIVE,
                'current_period_starts_at' => now(),
                'current_period_ends_at' => now()->addDays((int)($plan->duration_days ?? 30)),
            ]
        );

        // Apply snapshot NOW (this is admin action, it should update current subscriber)
        $sub->applyPlanSnapshot($plan);
        if (!$sub->current_period_starts_at) {
            $sub->current_period_starts_at = now();
        }
        if (!$sub->current_period_ends_at) {
            $sub->current_period_ends_at = now()->addDays((int)($plan->duration_days ?? 30));
        }
        if ($sub->status === Subscription::STATUS_EXPIRED) {
            $sub->status = Subscription::STATUS_ACTIVE;
        }

        $sub->save();

        return response()->json(['ok' => true]);
    }

    // PATCH /api/admin/businesses/{business}/trial
    // body: { "days": 7 }
    public function extendTrial(Request $request, Business $business)
    {
        $this->requireSuperAdmin($request);

        $data = $request->validate([
            'days' => ['required','integer','min:1','max:365'],
        ]);

        $starter = Plan::where('code','start')->first();

        $sub = Subscription::firstOrCreate(
            ['business_id' => $business->id],
            [
                'plan_id' => $starter?->id ?? 1,
                'status' => Subscription::STATUS_TRIALING,
                'trial_ends_at' => now()->addDays(14),
            ]
        );

        if ($starter) {
            // For newly created subscription we want snapshot as well
            if (!$sub->plan_version || !$sub->seats_limit_snapshot) {
                $sub->applyPlanSnapshot($starter);
            }
        }

        $trialBase = $sub->trial_ends_at ?? now();
        $sub->status = Subscription::STATUS_TRIALING;
        $sub->trial_ends_at = $trialBase->addDays((int)$data['days']);
        $sub->save();

        return response()->json(['ok' => true, 'trial_ends_at' => $sub->trial_ends_at]);
    }
}
