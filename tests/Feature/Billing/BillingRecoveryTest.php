<?php

use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function createBillingRecoveryPlan(): Plan
{
    return Plan::query()->create([
        'name' => 'Start',
        'code' => 'start',
        'version' => 1,
        'allowed_business_types' => ['beauty', 'dental'],
        'price' => 7900,
        'monthly_price' => 7900,
        'yearly_price' => 79000,
        'currency' => 'AMD',
        'seats' => 1,
        'staff_limit' => 1,
        'duration_days' => 30,
        'locations' => 1,
        'features' => ['staff_limit' => 1, 'services_limit' => 10],
        'is_active' => true,
        'is_visible' => true,
        'sort_order' => 1,
    ]);
}

it('lets an expired owner open billing and renew while keeping the main app locked', function () {
    $business = Business::factory()->beauty()->onboardingCompleted()->create();
    $owner = User::factory()->owner($business->id)->create();
    $plan = createBillingRecoveryPlan();

    Subscription::query()->create([
        'business_id' => $business->id,
        'plan_id' => $plan->id,
        'plan_version' => 1,
        'seats_limit_snapshot' => 1,
        'features_snapshot' => ['staff_limit' => 1, 'services_limit' => 10],
        'status' => Subscription::STATUS_EXPIRED,
        'billing_cycle' => 'monthly',
        'current_period_starts_at' => now()->subDays(31),
        'current_period_ends_at' => now()->subDay(),
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/features')
        ->assertOk()
        ->assertJsonPath('data.is_billable', false)
        ->assertJsonPath('data.reason', 'subscription_inactive')
        ->assertJsonPath('data.subscription.status', 'expired')
        ->assertJsonPath('data.features.tasks', true)
        ->assertJsonPath('data.features.rooms', true);

    $this->getJson('/api/bookings')
        ->assertStatus(402)
        ->assertJsonPath('code', 'subscription_inactive');

    $this->getJson('/api/billing/me')
        ->assertOk()
        ->assertJsonPath('data.is_billable', false)
        ->assertJsonPath('data.next_action', 'create_invoice')
        ->assertJsonPath('data.subscription.status', 'expired');

    $this->postJson('/api/billing/upgrade-request', [
        'plan_code' => 'start',
        'billing_cycle' => 'monthly',
        'payment_method' => 'card',
    ])
        ->assertCreated()
        ->assertJsonPath('mode', 'invoice')
        ->assertJsonPath('data.amount', 7900);
});

it('keeps billing recovery owner-only', function () {
    $business = Business::factory()->onboardingCompleted()->create();
    $staff = User::factory()->staff($business->id)->create();

    Sanctum::actingAs($staff);

    $this->getJson('/api/billing/me')->assertForbidden();
});
