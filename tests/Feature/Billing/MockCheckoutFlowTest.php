<?php

use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('creates an invoice, opens a mock checkout session, and activates the subscription after mock payment', function () {
    config()->set('billing.providers.default', 'idbank_mock');
    config()->set('billing.providers.mode', 'mock');

    $business = Business::factory()->onboardingCompleted()->create([
        'billing_status' => 'suspended',
    ]);

    $owner = User::factory()->owner($business->id)->create();

    $plan = Plan::query()->create([
        'name' => 'Pro',
        'code' => 'pro',
        'version' => 1,
        'allowed_business_types' => ['beauty', 'dental'],
        'price' => 15000,
        'currency' => 'AMD',
        'seats' => 5,
        'staff_limit' => 5,
        'duration_days' => 30,
        'features' => ['analytics' => true],
        'is_active' => true,
        'is_visible' => true,
        'sort_order' => 1,
    ]);

    Sanctum::actingAs($owner);

    $upgrade = $this->postJson('/api/billing/upgrade-request', [
        'plan_code' => 'pro',
        'payment_method' => 'card',
    ])->assertCreated();

    $invoiceId = $upgrade->json('data.id');

    $checkout = $this->postJson('/api/billing/checkout-session', [
        'invoice_id' => $invoiceId,
        'provider' => 'idbank_mock',
        'payment_method' => 'card',
    ])->assertCreated();

    $transactionId = $checkout->json('data.id');

    $this->postJson("/api/billing/transactions/{$transactionId}/mock-success")
        ->assertOk()
        ->assertJsonPath('data.invoice.status', 'approved');

    $business->refresh();
    $sub = Subscription::query()->where('business_id', $business->id)->first();

    expect($business->billing_status)->toBe('active');
    expect($sub)->not->toBeNull();
    expect($sub->status)->toBe('active');
    expect($sub->plan_id)->toBe($plan->id);
});
