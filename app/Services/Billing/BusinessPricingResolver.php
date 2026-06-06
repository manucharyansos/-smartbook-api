<?php

namespace App\Services\Billing;

use App\Models\Business;
use App\Models\BusinessPricingOverride;
use App\Models\Plan;

class BusinessPricingResolver
{
    public function activeOverride(Business $business, Plan $plan): ?BusinessPricingOverride
    {
        return BusinessPricingOverride::query()
            ->where('business_id', $business->id)
            ->where('plan_id', $plan->id)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->latest('id')
            ->first();
    }

    public function resolve(Business $business, Plan $plan, string $billingCycle = 'monthly'): array
    {
        $billingCycle = $billingCycle === 'yearly' ? 'yearly' : 'monthly';

        $baseMonthly = $plan->monthlyPrice();
        $baseYearly = $plan->yearlyPrice();
        $baseAmount = $billingCycle === 'yearly' ? $baseYearly : $baseMonthly;

        $override = $this->activeOverride($business, $plan);

        $effectiveMonthly = $baseMonthly;
        $effectiveYearly = $baseYearly;
        $discountAmount = 0;
        $discountType = null;
        $discountValue = null;

        if ($override) {
            if ($override->custom_monthly_price !== null) {
                $effectiveMonthly = (int) $override->custom_monthly_price;
            }
            if ($override->custom_yearly_price !== null) {
                $effectiveYearly = (int) $override->custom_yearly_price;
            }

            $targetAmount = $billingCycle === 'yearly' ? $effectiveYearly : $effectiveMonthly;

            if (in_array($override->discount_type, ['percent', 'fixed'], true) && $override->discount_value !== null) {
                $discountType = $override->discount_type;
                $discountValue = (float) $override->discount_value;

                if ($override->discount_type === 'percent') {
                    $discountAmount = (int) round($targetAmount * ($discountValue / 100));
                } else {
                    $discountAmount = (int) round($discountValue);
                }

                $discountAmount = max(0, min($discountAmount, $targetAmount));

                if ($billingCycle === 'yearly') {
                    $effectiveYearly = max(0, $targetAmount - $discountAmount);
                } else {
                    $effectiveMonthly = max(0, $targetAmount - $discountAmount);
                }
            }
        }

        $effectiveAmount = $billingCycle === 'yearly' ? $effectiveYearly : $effectiveMonthly;

        return [
            'billing_cycle' => $billingCycle,
            'currency' => $plan->currency ?? 'AMD',
            'base_monthly_price' => $baseMonthly,
            'base_yearly_price' => $baseYearly,
            'base_amount' => $baseAmount,
            'effective_monthly_price' => $effectiveMonthly,
            'effective_yearly_price' => $effectiveYearly,
            'effective_amount' => $effectiveAmount,
            'discount_amount' => $discountAmount,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'override' => $override,
            'has_override' => (bool) $override,
        ];
    }
}
