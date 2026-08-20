<?php

use App\Models\Plan;
use Illuminate\Support\Facades\Mail;

function registrationPlan(array $overrides = []): Plan
{
    return Plan::query()->create(array_merge([
        'name' => 'Start',
        'code' => 'start',
        'version' => 3,
        'allowed_business_types' => ['beauty', 'dental', 'services', 'healthcare'],
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
    ], $overrides));
}

function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'business_name' => 'Plan Test Studio',
        'business_phone' => '+37498408879',
        'business_address' => 'Yerevan, Armenia',
        'latitude' => 40.1772,
        'longitude' => 44.5035,
        'business_type' => 'beauty',
        'name' => 'Plan Test Owner',
        'email' => 'owner@example.com',
        'password' => 'SecurePassword123!',
        'password_confirmation' => 'SecurePassword123!',
    ], $overrides);
}

beforeEach(function () {
    Mail::fake();
});

it('starts the trial with the visible self-serve plan selected on pricing', function () {
    registrationPlan();
    $studio = registrationPlan([
        'name' => 'Studio',
        'code' => 'studio',
        'price' => 14900,
        'monthly_price' => 14900,
        'yearly_price' => 149000,
        'seats' => 5,
        'staff_limit' => 5,
        'locations' => 2,
        'features' => ['staff_limit' => 5, 'services_limit' => 30],
        'sort_order' => 2,
    ]);

    $response = $this->postJson('/api/auth/register', registrationPayload([
        'plan_code' => 'studio',
    ]));

    $response->assertOk();
    $businessId = $response->json('user.business_id');

    $this->assertDatabaseHas('subscriptions', [
        'business_id' => $businessId,
        'plan_id' => $studio->id,
        'status' => 'trialing',
    ]);
    $this->assertDatabaseHas('business_locations', [
        'business_id' => $businessId,
        'latitude' => 40.1772,
        'longitude' => 44.5035,
        'is_primary' => true,
    ]);
});

it('rejects hidden or sales-assisted plans during self-service registration', function () {
    registrationPlan();
    registrationPlan([
        'name' => 'Custom',
        'code' => 'custom',
        'price' => 0,
        'monthly_price' => 0,
        'yearly_price' => 0,
        'staff_limit' => 999,
        'features' => ['staff_limit' => 999, 'custom_pricing' => true],
    ]);

    $this->postJson('/api/auth/register', registrationPayload([
        'plan_code' => 'custom',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('plan_code');

    $this->assertDatabaseCount('businesses', 0);
});

it('requires the address before registration creates a business', function () {
    registrationPlan();

    $this->postJson('/api/auth/register', registrationPayload([
        'business_address' => '',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('business_address');

    $this->assertDatabaseCount('businesses', 0);
});

it('requires map coordinates before registration creates a business', function () {
    registrationPlan();

    $this->postJson('/api/auth/register', registrationPayload([
        'latitude' => null,
        'longitude' => null,
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['latitude', 'longitude']);

    $this->assertDatabaseCount('businesses', 0);
});
