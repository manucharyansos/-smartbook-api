<?php

use App\Models\Business;
use App\Models\BusinessPricingOverride;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\Billing\BusinessPricingResolver;

function createPricingResolverPlan(): Plan
{
    return Plan::query()->create([
        'name' => 'Studio',
        'code' => 'studio',
        'version' => 1,
        'allowed_business_types' => ['beauty', 'dental'],
        'price' => 14900,
        'monthly_price' => 14900,
        'yearly_price' => 149000,
        'currency' => 'AMD',
        'seats' => 5,
        'staff_limit' => 5,
        'duration_days' => 30,
        'locations' => 2,
        'features' => ['staff_limit' => 5, 'services_limit' => 30],
        'is_active' => true,
        'is_visible' => true,
        'sort_order' => 2,
    ]);
}

it('applies an individual offer consistently to monthly and yearly prices', function () {
    $business = Business::factory()->beauty()->create();
    $plan = createPricingResolverPlan();

    $override = BusinessPricingOverride::query()->create([
        'business_id' => $business->id,
        'plan_id' => $plan->id,
        'custom_monthly_price' => 10000,
        'custom_yearly_price' => null,
        'discount_type' => 'percent',
        'discount_value' => 10,
        'is_active' => true,
    ]);

    $resolver = app(BusinessPricingResolver::class);
    $monthly = $resolver->resolve($business, $plan, 'monthly');
    $yearly = $resolver->resolve($business, $plan, 'yearly');

    expect($monthly['override']->id)->toBe($override->id)
        ->and($monthly['effective_monthly_price'])->toBe(9000)
        ->and($monthly['effective_yearly_price'])->toBe(90000)
        ->and($monthly['discount_amount'])->toBe(1000)
        ->and($yearly['effective_monthly_price'])->toBe(9000)
        ->and($yearly['effective_yearly_price'])->toBe(90000)
        ->and($yearly['discount_amount'])->toBe(10000);
});

it('expires an individual offer after its billing-cycle limit is used', function () {
    $business = Business::factory()->beauty()->create();
    $plan = createPricingResolverPlan();

    $override = BusinessPricingOverride::query()->create([
        'business_id' => $business->id,
        'plan_id' => $plan->id,
        'custom_monthly_price' => 12000,
        'billing_cycles_limit' => 1,
        'is_active' => true,
    ]);

    Invoice::query()->create([
        'business_id' => $business->id,
        'plan_id' => $plan->id,
        'amount' => 12000,
        'currency' => 'AMD',
        'billing_cycle' => 'monthly',
        'status' => 'approved',
        'paid_at' => now(),
        'meta' => ['pricing_override_id' => $override->id],
    ]);

    $override->refresh();
    $resolver = app(BusinessPricingResolver::class);

    expect($override->usedBillingCycles())->toBe(1)
        ->and($override->remainingBillingCycles())->toBe(0)
        ->and($override->isCurrentlyActive())->toBeFalse()
        ->and($resolver->activeOverride($business, $plan))->toBeNull();
});
