<?php

namespace App\Services\Billing;

use App\Models\Business;
use App\Models\BusinessPricingOverride;
use App\Models\Plan;
use Illuminate\Support\Collection;

class BusinessPricingResolver
{
    public function activeOverride(Business $business, Plan $plan): ?BusinessPricingOverride
    {
        return $this->activeOverridesForBusiness($business)
            ->first(fn (BusinessPricingOverride $override) => (int) $override->plan_id === (int) $plan->id);
    }

    public function activeOverridesForBusiness(Business $business): Collection
    {
        return BusinessPricingOverride::query()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->with('plan')
            ->latest('id')
            ->get()
            ->filter(fn (BusinessPricingOverride $override) => $override->isCurrentlyActive())
            ->unique('plan_id')
            ->values();
    }

    /**
     * Enabled offers whose start date is still ahead, so the business can see them before they apply.
     */
    public function upcomingOverridesForBusiness(Business $business): Collection
    {
        return BusinessPricingOverride::query()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->where('starts_at', '>', now())
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->with('plan')
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (BusinessPricingOverride $override) => $override->plan)
            ->values();
    }

    public function resolve(Business $business, Plan $plan, string $billingCycle = 'monthly'): array
    {
        $billingCycle = $billingCycle === 'yearly' ? 'yearly' : 'monthly';

        $baseMonthly = $plan->monthlyPrice();
        $baseYearly = $plan->yearlyPrice();
        $baseAmount = $billingCycle === 'yearly' ? $baseYearly : $baseMonthly;

        $override = $this->activeOverride($business, $plan);

        return $this->priceWith($plan, $override, $billingCycle);
    }

    /**
     * Prices a specific offer as if it applied now (used to show scheduled offers with their real price).
     */
    public function previewOverride(Business $business, BusinessPricingOverride $override): array
    {
        $resolved = $this->priceWith($override->plan, $override, 'monthly');

        return [
            'monthly' => $resolved['effective_monthly_price'],
            'yearly' => $resolved['effective_yearly_price'],
            'discount_amount' => $resolved['discount_amount'],
        ];
    }

    private function priceWith(Plan $plan, ?BusinessPricingOverride $override, string $billingCycle): array
    {
        $baseMonthly = $plan->monthlyPrice();
        $baseYearly = $plan->yearlyPrice();
        $baseAmount = $billingCycle === 'yearly' ? $baseYearly : $baseMonthly;

        $effectiveMonthly = $baseMonthly;
        $effectiveYearly = $baseYearly;
        $monthlyDiscountAmount = 0;
        $yearlyDiscountAmount = 0;
        $discountType = null;
        $discountValue = null;

        if ($override) {
            if ($override->custom_monthly_price !== null) {
                $effectiveMonthly = (int) $override->custom_monthly_price;

                if ($override->custom_yearly_price === null) {
                    $effectiveYearly = $effectiveMonthly * 10;
                }
            }
            if ($override->custom_yearly_price !== null) {
                $effectiveYearly = (int) $override->custom_yearly_price;
            }

            if (in_array($override->discount_type, ['percent', 'fixed'], true) && $override->discount_value !== null) {
                $discountType = $override->discount_type;
                $discountValue = (float) $override->discount_value;

                if ($override->discount_type === 'percent') {
                    $monthlyDiscountAmount = (int) round($effectiveMonthly * ($discountValue / 100));
                    $yearlyDiscountAmount = (int) round($effectiveYearly * ($discountValue / 100));
                } else {
                    $monthlyDiscountAmount = (int) round($discountValue);
                    $yearlyDiscountAmount = (int) round($discountValue);
                }

                $monthlyDiscountAmount = max(0, min($monthlyDiscountAmount, $effectiveMonthly));
                $yearlyDiscountAmount = max(0, min($yearlyDiscountAmount, $effectiveYearly));
                $effectiveMonthly = max(0, $effectiveMonthly - $monthlyDiscountAmount);
                $effectiveYearly = max(0, $effectiveYearly - $yearlyDiscountAmount);
            }
        }

        $effectiveAmount = $billingCycle === 'yearly' ? $effectiveYearly : $effectiveMonthly;
        $discountAmount = $billingCycle === 'yearly' ? $yearlyDiscountAmount : $monthlyDiscountAmount;

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
            'monthly_discount_amount' => $monthlyDiscountAmount,
            'yearly_discount_amount' => $yearlyDiscountAmount,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'override' => $override,
            'has_override' => (bool) $override,
        ];
    }
}
