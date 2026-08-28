<?php

use App\Models\Business;
use App\Models\Plan;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function createPlanForSelection(array $overrides = []): Plan
{
    return Plan::query()->create(array_merge([
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
    ], $overrides));
}

it('publishes the sales-assisted plan without presenting it as self-serve', function () {
    createPlanForSelection([
        'name' => 'Custom',
        'code' => 'custom',
        'price' => 0,
        'monthly_price' => 0,
        'yearly_price' => 0,
        'seats' => 999,
        'staff_limit' => 999,
        'features' => [
            'staff_limit' => 999,
            'services_limit' => 999,
            'custom_pricing' => true,
        ],
    ]);

    $this->getJson('/api/plans')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'custom')
        ->assertJsonPath('data.0.is_custom', true)
        ->assertJsonPath('data.0.self_serve', false)
        ->assertJsonPath('data.0.yearly_offer.enabled', false);
});

it('publishes only the notification channels that are enabled by default', function () {
    createPlanForSelection();

    $this->getJson('/api/plans')
        ->assertOk()
        ->assertJsonPath('data.0.features.email_notifications', true)
        ->assertJsonPath('data.0.features.telegram_notifications', true)
        ->assertJsonPath('data.0.features.sms_reminders', false)
        ->assertJsonPath('data.0.features.whatsapp_notifications', false);
});

it('does not activate a custom plan without an active priced offer', function () {
    $business = Business::factory()->onboardingCompleted()->create();
    $owner = User::factory()->owner($business->id)->create();

    createPlanForSelection([
        'name' => 'Custom',
        'code' => 'custom',
        'price' => 0,
        'monthly_price' => 0,
        'yearly_price' => 0,
        'seats' => 999,
        'staff_limit' => 999,
        'features' => [
            'staff_limit' => 999,
            'services_limit' => 999,
            'custom_pricing' => true,
        ],
    ]);

    Sanctum::actingAs($owner);

    $this->postJson('/api/billing/upgrade-request', [
        'plan_code' => 'custom',
        'billing_cycle' => 'monthly',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'custom_offer_required');
});

it('blocks a downgrade when current usage exceeds the selected plan', function () {
    $business = Business::factory()->onboardingCompleted()->create();
    $owner = User::factory()->owner($business->id)->create();
    User::factory()->count(2)->staff($business->id)->create();

    createPlanForSelection();

    Sanctum::actingAs($owner);

    $this->postJson('/api/billing/upgrade-request', [
        'plan_code' => 'start',
        'billing_cycle' => 'monthly',
    ])
        ->assertConflict()
        ->assertJsonPath('code', 'plan_limits_exceeded')
        ->assertJsonPath('data.exceeded.0', 'staff')
        ->assertJsonPath('data.usage.active_staff', 2);
});

it('cancels an older pending invoice when the owner selects another plan', function () {
    $business = Business::factory()->onboardingCompleted()->create();
    $owner = User::factory()->owner($business->id)->create();
    createPlanForSelection();
    createPlanForSelection([
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

    Sanctum::actingAs($owner);

    $firstInvoiceId = $this->postJson('/api/billing/upgrade-request', [
        'plan_code' => 'start',
        'billing_cycle' => 'monthly',
    ])->assertCreated()->json('data.id');

    $secondInvoiceId = $this->postJson('/api/billing/upgrade-request', [
        'plan_code' => 'studio',
        'billing_cycle' => 'monthly',
    ])->assertCreated()->json('data.id');

    $this->assertDatabaseHas('invoices', ['id' => $firstInvoiceId, 'status' => 'cancelled']);
    $this->assertDatabaseHas('invoices', ['id' => $secondInvoiceId, 'status' => 'pending']);
});
