<?php

use App\Models\Admin;
use App\Models\Business;
use App\Models\BusinessPricingOverride;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

// An offer's plan must belong to the business's own area. Found 2026-11-11: an offer on the
// healthcare "Start" plan for a services business was saved, shown, and its upgrade request
// answered a bare 404.

function areaPlan(string $code, ?array $allowedTypes, int $monthly = 10000): Plan
{
    return Plan::create([
        'name' => ucfirst($code), 'code' => $code, 'monthly_price' => $monthly, 'price' => $monthly,
        'currency' => 'AMD', 'staff_limit' => 5, 'locations' => 1, 'is_active' => true, 'is_visible' => true,
        'allowed_business_types' => $allowedTypes,
    ]);
}

function actAsSuperAdmin(string $email): void
{
    Sanctum::actingAs(Admin::create(['name' => 'Owner', 'email' => $email, 'password' => Hash::make('x-secure-pass-1'),
        'role' => Admin::ROLE_SUPER_ADMIN, 'is_active' => true]));
}

it('refuses an offer on another area\'s plan and accepts its own area or Custom', function () {
    $business = Business::factory()->onboardingCompleted()->create(['business_type' => 'beauty']);
    $healthcare = areaPlan('clinic-start', ['dental']);
    $services = areaPlan('salon-basic', ['beauty']);
    $custom = areaPlan('custom', null, 0);
    actAsSuperAdmin('offers-area@vizit.test');

    $this->postJson("/api/admin/businesses/{$business->id}/pricing-overrides", ['plan_id' => $healthcare->id, 'custom_monthly_price' => 200])
        ->assertUnprocessable()->assertJsonValidationErrors('plan_id');
    expect(BusinessPricingOverride::where('business_id', $business->id)->count())->toBe(0);

    $offerId = $this->postJson("/api/admin/businesses/{$business->id}/pricing-overrides", ['plan_id' => $services->id, 'custom_monthly_price' => 200])
        ->assertCreated()->json('data.id');
    $this->postJson("/api/admin/businesses/{$business->id}/pricing-overrides", ['plan_id' => $custom->id, 'custom_monthly_price' => 300])
        ->assertCreated();

    // Moving an offer to another area's plan is refused as well.
    $this->patchJson("/api/admin/businesses/{$business->id}/pricing-overrides/{$offerId}", ['plan_id' => $healthcare->id])
        ->assertUnprocessable()->assertJsonValidationErrors('plan_id');

    $details = $this->getJson("/api/admin/businesses/{$business->id}")->assertOk()->json('data');
    $fits = collect($details['plans'] ?? $details['available_plans'] ?? [])->pluck('fits_business', 'code');
    expect($fits['clinic-start'])->toBeFalse()
        ->and($fits['salon-basic'])->toBeTrue()
        ->and($fits['custom'])->toBeTrue();
});

it('lets an admin switch off an old offer whose plan belongs to another area', function () {
    $business = Business::factory()->onboardingCompleted()->create(['business_type' => 'beauty']);
    $healthcare = areaPlan('clinic-scale', ['dental']);
    $old = $business->pricingOverrides()->create(['plan_id' => $healthcare->id, 'custom_monthly_price' => 200, 'is_active' => true]);
    actAsSuperAdmin('offers-old@vizit.test');

    $offer = collect($this->getJson("/api/admin/businesses/{$business->id}")->assertOk()->json('data.pricing_overrides'))->firstWhere('id', $old->id);
    expect($offer['fits_business'])->toBeFalse();

    $this->patchJson("/api/admin/businesses/{$business->id}/pricing-overrides/{$old->id}", ['is_active' => false])->assertOk();
    expect($old->fresh()->is_active)->toBeFalse();
});

it('hides another area\'s offer from the business and explains a mismatched upgrade request', function () {
    $business = Business::factory()->onboardingCompleted()->create(['business_type' => 'beauty']);
    $healthcare = areaPlan('clinic-studio', ['dental']);
    $services = areaPlan('salon-standard', ['beauty']);
    $business->pricingOverrides()->create(['plan_id' => $healthcare->id, 'custom_monthly_price' => 200, 'is_active' => true]);
    $business->pricingOverrides()->create(['plan_id' => $services->id, 'custom_monthly_price' => 300, 'is_active' => true]);
    Sanctum::actingAs(User::factory()->create(['business_id' => $business->id, 'role' => User::ROLE_OWNER]));

    $offers = collect($this->getJson('/api/billing/me')->assertOk()->json('data.individual_offers'));
    expect($offers)->toHaveCount(1)
        ->and($offers->first()['effective_monthly_price'] ?? null)->toBe(300);

    $this->postJson('/api/billing/upgrade-request', ['plan_code' => 'clinic-studio', 'billing_cycle' => 'monthly'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'plan_not_available_for_business_type');
});
