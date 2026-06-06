<?php

namespace App\Services\Billing;

use App\Models\Business;
use App\Models\Subscription;

class SubscriptionStateService
{
    /**
     * Synchronize persisted subscription status with the computed lifecycle state.
     *
     * We keep this lightweight so expired trials become visible immediately even
     * when the scheduled billing sweep is not configured yet.
     */
    public function sync(?Business $business): ?Subscription
    {
        if (!$business) {
            return null;
        }

        $subscription = $business->relationLoaded('subscription')
            ? $business->subscription
            : $business->subscription()->with('plan')->first();

        if (!$subscription) {
            return null;
        }

        $computed = $subscription->computedStatus();

        if ($subscription->status !== $computed) {
            $subscription->status = $computed;

            if ($computed === Subscription::STATUS_EXPIRED && $subscription->suspended_at) {
                $subscription->suspended_at = null;
            }

            $subscription->save();
            $subscription->refresh();
        }

        if (!$subscription->relationLoaded('plan')) {
            $subscription->loadMissing('plan');
        }

        return $subscription;
    }
}
