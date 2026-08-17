<?php

use App\Models\Business;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('creates an invoice, opens a mock checkout session, and activates the subscription after mock payment', function () {
    config()->set('billing.providers.default', 'idbank_mock');
    config()->set('billing.providers.mode', 'mock');
    config()->set('billing.allow_mock_payments', true);

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

    $this->postJson('/api/billing/checkout-session', [
        'invoice_id' => $invoiceId,
        'provider' => 'idbank',
        'payment_method' => 'card',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'payment_provider_unavailable');

    $checkout = $this->postJson('/api/billing/checkout-session', [
        'invoice_id' => $invoiceId,
        'provider' => 'idbank_mock',
        'payment_method' => 'card',
    ])->assertCreated();

    $transactionId = $checkout->json('data.id');

    $this->postJson('/api/billing/checkout-session', [
        'invoice_id' => $invoiceId,
        'provider' => 'idbank_mock',
        'payment_method' => 'card',
    ])
        ->assertOk()
        ->assertJsonPath('data.id', $transactionId);

    expect(PaymentTransaction::query()->where('invoice_id', $invoiceId)->count())->toBe(1);

    $this->postJson("/api/billing/transactions/{$transactionId}/mock-success")
        ->assertOk()
        ->assertJsonPath('data.invoice.status', 'approved');

    $business->refresh();
    $sub = Subscription::query()->where('business_id', $business->id)->first();

    expect($business->billing_status)->toBe('active');
    expect($sub)->not->toBeNull();
    expect($sub->status)->toBe('active');
    expect($sub->plan_id)->toBe($plan->id);

    $this->getJson('/api/features')
        ->assertOk()
        ->assertJsonPath('data.is_billable', true);
});

it('never activates a cancelled invoice from a late mock callback', function () {
    config()->set('billing.allow_mock_payments', true);

    $business = Business::factory()->onboardingCompleted()->create();
    $owner = User::factory()->owner($business->id)->create();
    $plan = Plan::query()->create([
        'name' => 'Start',
        'code' => 'start',
        'version' => 1,
        'allowed_business_types' => ['beauty', 'dental'],
        'price' => 7900,
        'currency' => 'AMD',
        'seats' => 1,
        'staff_limit' => 1,
        'duration_days' => 30,
        'features' => ['staff_limit' => 1, 'services_limit' => 10],
        'is_active' => true,
        'is_visible' => true,
    ]);
    $invoice = Invoice::query()->create([
        'business_id' => $business->id,
        'plan_id' => $plan->id,
        'amount' => 7900,
        'currency' => 'AMD',
        'billing_cycle' => 'monthly',
        'status' => 'cancelled',
        'cancelled_at' => now(),
        'meta' => ['period_days' => 30],
    ]);
    $transaction = PaymentTransaction::query()->create([
        'business_id' => $business->id,
        'invoice_id' => $invoice->id,
        'provider' => 'idbank_mock',
        'provider_transaction_id' => 'pay_late_callback',
        'payment_method' => 'card',
        'amount' => 7900,
        'currency' => 'AMD',
        'status' => 'pending',
    ]);

    Sanctum::actingAs($owner);

    $this->postJson("/api/billing/transactions/{$transaction->id}/mock-success")
        ->assertOk()
        ->assertJsonPath('data.invoice.status', 'cancelled');

    expect(Subscription::query()->where('business_id', $business->id)->exists())->toBeFalse()
        ->and($transaction->refresh()->status)->toBe('paid');
});
