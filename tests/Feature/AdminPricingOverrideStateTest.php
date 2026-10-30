<?php

use App\Models\Admin;
use App\Models\Business;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

function overridePlan(string $code, int $monthly): Plan
{
    return Plan::create([
        'name' => ucfirst($code), 'code' => $code, 'monthly_price' => $monthly, 'price' => $monthly,
        'currency' => 'AMD', 'staff_limit' => $code === 'custom' ? 999 : 5, 'locations' => 1, 'is_active' => true,
    ]);
}

it('refuses a Custom offer without its own price and explains why an offer is not active yet', function () {
    $business = Business::factory()->onboardingCompleted()->create();
    $custom = overridePlan('custom', 0);
    Sanctum::actingAs(Admin::create(['name' => 'Owner', 'email' => 'offer@vizit.test', 'password' => Hash::make('x-secure-pass-1'), 'role' => Admin::ROLE_SUPER_ADMIN, 'is_active' => true]));

    $start = now()->addDays(2)->toDateString();
    $end = now()->addDays(20)->toDateString();

    $this->postJson("/api/admin/businesses/{$business->id}/pricing-overrides", ['plan_id' => $custom->id, 'starts_at' => $start, 'ends_at' => $end])
        ->assertUnprocessable()->assertJsonValidationErrors('custom_monthly_price');

    $this->postJson("/api/admin/businesses/{$business->id}/pricing-overrides", ['plan_id' => $custom->id, 'custom_monthly_price' => 45000, 'starts_at' => $start, 'ends_at' => $end])
        ->assertCreated();

    $offer = $this->getJson("/api/admin/businesses/{$business->id}")->assertOk()->json('data.pricing_overrides.0');
    expect($offer['state'])->toBe('scheduled')
        ->and($offer['effective_monthly_price'])->toBe(45000)
        ->and($offer['ends_at'])->toContain('T23:59:59');
});

it('shows a scheduled offer to the business before it starts', function () {
    $business = Business::factory()->onboardingCompleted()->create();
    $custom = overridePlan('custom', 0);
    $business->pricingOverrides()->create([
        'plan_id' => $custom->id, 'custom_monthly_price' => 30000, 'is_active' => true,
        'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(30),
    ]);
    $owner = User::factory()->create(['business_id' => $business->id, 'role' => User::ROLE_OWNER]);
    Sanctum::actingAs($owner);

    $data = $this->getJson('/api/billing/me')->assertOk()->json('data');
    expect($data['individual_offers'])->toBe([])
        ->and($data['upcoming_offers'])->toHaveCount(1)
        ->and($data['upcoming_offers'][0]['effective_monthly_price'])->toBe(30000);
});

it('lists the newest business first with its owner and plan', function () {
    $older = Business::factory()->onboardingCompleted()->create();
    $newer = Business::factory()->onboardingCompleted()->create();
    User::factory()->create(['business_id' => $newer->id, 'role' => User::ROLE_OWNER, 'name' => 'Նոր Սեփականատեր']);
    Sanctum::actingAs(Admin::create(['name' => 'Owner', 'email' => 'list@vizit.test', 'password' => Hash::make('x-secure-pass-1'), 'role' => Admin::ROLE_SUPER_ADMIN, 'is_active' => true]));

    $rows = $this->getJson('/api/admin/businesses')->assertOk()->json('data.data');
    expect($rows[0]['id'])->toBe($newer->id)
        ->and($rows[0]['owner_summary']['name'])->toBe('Նոր Սեփականատեր')
        ->and($rows[0])->toHaveKey('subscription_summary');
});
