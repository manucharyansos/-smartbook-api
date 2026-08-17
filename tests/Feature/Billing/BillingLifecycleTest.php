<?php

use App\Models\Business;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\BillingLifecycleService;
use Illuminate\Support\Carbon;

it('extends an active subscription and remains idempotent for the same invoice', function () {
    $now = Carbon::parse('2026-08-17 12:00:00');
    $this->travelTo($now);

    $business = Business::factory()->beauty()->create();
    $plan = Plan::query()->create([
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
    ]);

    $subscription = Subscription::query()->create([
        'business_id' => $business->id,
        'plan_id' => $plan->id,
        'status' => Subscription::STATUS_ACTIVE,
        'billing_cycle' => 'monthly',
        'current_period_starts_at' => $now->copy()->subDays(20),
        'current_period_ends_at' => $now->copy()->addDays(10),
    ]);

    $invoice = Invoice::query()->create([
        'business_id' => $business->id,
        'plan_id' => $plan->id,
        'amount' => 7900,
        'currency' => 'AMD',
        'billing_cycle' => 'monthly',
        'status' => 'pending',
        'meta' => ['billing_cycle' => 'monthly', 'period_days' => 30],
    ]);

    $service = app(BillingLifecycleService::class);
    $service->approveInvoice($invoice, ['provider' => 'idbank_mock']);

    $expectedEnd = $now->copy()->addDays(40)->toDateTimeString();
    expect($subscription->refresh()->current_period_ends_at->toDateTimeString())->toBe($expectedEnd)
        ->and($invoice->refresh()->status)->toBe('approved');

    $service->approveInvoice($invoice->fresh(), ['provider' => 'idbank_mock']);

    expect($subscription->refresh()->current_period_ends_at->toDateTimeString())->toBe($expectedEnd);

    $this->travelBack();
});
