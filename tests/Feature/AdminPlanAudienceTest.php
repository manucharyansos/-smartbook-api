<?php

use App\Models\Admin;
use App\Models\Business;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(Admin::create(['name' => 'Owner', 'email' => 'plans@vizit.test', 'password' => Hash::make('x-secure-pass-1'), 'role' => Admin::ROLE_SUPER_ADMIN, 'is_active' => true]));
});

function planPayload(array $changes = []): array
{
    return array_merge(['name' => 'Clinic Pro', 'code' => 'clinic-pro', 'business_type' => 'healthcare', 'monthly_price' => 30000,
        'yearly_price' => 300000, 'currency' => 'AMD', 'staff_limit' => 8, 'duration_days' => 30, 'locations' => 2, 'is_active' => true, 'is_visible' => true], $changes);
}

it('creates a plan with its own price for one business area only', function () {
    $this->postJson('/api/admin/plans', planPayload())->assertCreated();
    $plan = Plan::where('code', 'clinic-pro')->firstOrFail();
    expect($plan->audience())->toBe('healthcare')->and($plan->allowed_business_types)->toBe(['dental']);
    expect(collect($this->getJson('/api/plans?business_type=healthcare')->json('data'))->pluck('code'))->toContain('clinic-pro');
    expect(collect($this->getJson('/api/plans?business_type=services')->json('data'))->pluck('code'))->not->toContain('clinic-pro');
    expect(collect($this->getJson('/api/plans?business_type=rentals')->json('data'))->pluck('code'))->not->toContain('clinic-pro');
    $this->getJson('/api/admin/plans?show_hidden=1')->assertOk()->assertJsonFragment(['code' => 'clinic-pro', 'audience' => 'healthcare']);
});

it('splits a shared plan so healthcare gets its own hidden copy to price separately', function () {
    $shared = Plan::where('code', 'start')->first() ?? Plan::create(['name' => 'Start', 'code' => 'start', 'version' => 1, 'price' => 15000,
        'monthly_price' => 15000, 'currency' => 'AMD', 'seats' => 3, 'staff_limit' => 3, 'duration_days' => 30, 'features' => [],
        'allowed_business_types' => ['beauty', 'dental'], 'is_active' => true, 'is_visible' => true, 'sort_order' => 1]);
    expect($shared->audience())->toBe('all');
    $copy = $this->postJson("/api/admin/plans/{$shared->id}/copy", ['audience' => 'healthcare', 'detach_source' => true])->assertCreated()
        ->assertJsonPath('data.audience', 'healthcare')->assertJsonPath('data.is_visible', false)->json('data');
    expect($shared->fresh()->audience())->toBe('services');
    $this->putJson("/api/admin/plans/{$copy['id']}", ['monthly_price' => 19000, 'is_visible' => true])->assertOk();
    $prices = collect($this->getJson('/api/plans?business_type=healthcare')->json('data'))->pluck('monthly_price', 'code');
    expect($prices[$copy['code']])->toBe(19000)->and($prices->has('start'))->toBeFalse();
    expect(Plan::defaultCodeFor('healthcare'))->toBe($copy['code'])->and(Plan::defaultCodeFor('services'))->toBe('start');
    $this->postJson("/api/admin/plans/{$shared->id}/copy", ['audience' => 'services'])->assertStatus(422);
});

it('supports more than one rental plan and never sells it to staff-based businesses', function () {
    $this->postJson('/api/admin/plans', planPayload(['name' => 'Large hall', 'code' => 'hall-large', 'business_type' => 'rentals', 'staff_limit' => 0, 'monthly_price' => 40000, 'yearly_price' => 480000]))->assertCreated();
    $plan = Plan::where('code', 'hall-large')->firstOrFail();
    expect($plan->isRentalPlan())->toBeTrue()->and($plan->supportsBusinessType('rentals'))->toBeTrue()
        ->and($plan->supportsBusinessType('services'))->toBeFalse()->and($plan->getFeaturesList()['rentals'] ?? false)->toBeTrue();
    expect(collect($this->getJson('/api/plans?business_type=rentals')->json('data'))->pluck('code')->all())->toContain('rental', 'hall-large');

    $space = Business::factory()->onboardingCompleted()->create(['business_type' => 'rentals', 'vertical' => 'rentals']);
    Sanctum::actingAs(User::factory()->create(['business_id' => $space->id, 'role' => 'owner']));
    $this->postJson('/api/billing/upgrade-request', ['plan_code' => 'hall-large', 'billing_cycle' => 'yearly'])->assertCreated()
        ->assertJsonPath('data.amount', 480000);
    $salon = Business::factory()->onboardingCompleted()->create();
    Sanctum::actingAs(User::factory()->create(['business_id' => $salon->id, 'role' => 'owner']));
    $this->postJson('/api/billing/upgrade-request', ['plan_code' => 'hall-large'])->assertNotFound();
});
