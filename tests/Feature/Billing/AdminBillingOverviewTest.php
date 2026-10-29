<?php

use App\Models\Admin;
use App\Models\AdminLog;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function billingAdmin(string $role = Admin::ROLE_SUPER_ADMIN): Admin
{
    return Admin::query()->create(['name' => 'Billing Admin', 'email' => fake()->unique()->safeEmail(),
        'password' => Hash::make('A-secure-passphrase-123'), 'role' => $role, 'is_active' => true, 'email_verified_at' => now()]);
}

function billingInvoice(array $invoice = [], array $business = []): Invoice
{
    $owner = Business::factory()->onboardingCompleted()->create($business);
    $plan = Plan::create(['name' => 'Billing QA', 'code' => 'billing-'.Str::lower(Str::random(8)), 'version' => 1, 'price' => 10000,
        'monthly_price' => 10000, 'currency' => 'AMD', 'seats' => 5, 'staff_limit' => 5, 'duration_days' => 30, 'features' => [],
        'is_active' => true, 'is_visible' => true, 'sort_order' => 1]);
    Subscription::create(['business_id' => $owner->id, 'plan_id' => $plan->id, 'status' => 'trialing', 'trial_ends_at' => now()->addDays(3)]);

    return Invoice::query()->withoutGlobalScopes()->create(array_merge(['business_id' => $owner->id, 'plan_id' => $plan->id,
        'amount' => 10000, 'currency' => 'AMD', 'billing_cycle' => 'monthly', 'status' => 'pending'], $invoice));
}

it('reports Vizit subscription income separately from customer booking revenue and never exposes the Idram secret', function () {
    config()->set(['billing.providers.idram.secret_key' => 'top-secret-value', 'billing.providers.idram.rec_account' => '111222333']);
    $paid = billingInvoice(['status' => 'approved', 'paid_at' => now(), 'amount' => 25000]);
    PaymentTransaction::create(['business_id' => $paid->business_id, 'invoice_id' => $paid->id, 'provider' => 'idram',
        'provider_transaction_id' => 'pay_ONLINE', 'amount' => 25000, 'currency' => 'AMD', 'status' => 'paid', 'paid_at' => now()]);
    billingInvoice(['status' => 'approved', 'paid_at' => now(), 'amount' => 120000, 'billing_cycle' => 'yearly', 'payment_method' => 'manual']);
    billingInvoice(['amount' => 5000]);
    Subscription::query()->update(['status' => 'active']);
    Sanctum::actingAs(billingAdmin(Admin::ROLE_FINANCE));

    $response = $this->getJson('/api/admin/billing/summary')->assertOk()
        ->assertJsonPath('data.income.period', 145000)->assertJsonPath('data.income.period_online', 25000)
        ->assertJsonPath('data.income.period_manual', 120000)->assertJsonPath('data.pending.invoices', 1)
        ->assertJsonPath('data.pending.amount', 5000)->assertJsonPath('data.mrr', 35000)
        ->assertJsonPath('data.provider.idram.merchant_account', '111222333');
    expect($response->getContent())->not->toContain('top-secret-value');
    $this->getJson('/api/admin/billing/invoices?status=approved')->assertOk()->assertJsonCount(2, 'data.data');
    $this->getJson('/api/admin/billing/invoices?search=pay_ONLINE')->assertOk()->assertJsonPath('data.data.0.id', $paid->id);
});

it('lets only a super admin approve a pending invoice by hand, with a required note and audit log', function () {
    $invoice = billingInvoice();
    Sanctum::actingAs(billingAdmin(Admin::ROLE_FINANCE));
    $this->postJson("/api/admin/billing/invoices/{$invoice->id}/approve", ['note' => 'Bank transfer'])->assertForbidden();
    Sanctum::actingAs(billingAdmin());
    $this->postJson("/api/admin/billing/invoices/{$invoice->id}/approve", [])->assertUnprocessable();
    $this->postJson("/api/admin/billing/invoices/{$invoice->id}/approve", ['note' => 'Bank transfer #12'])->assertOk()
        ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.payment_method', 'manual');
    expect(Subscription::where('business_id', $invoice->business_id)->value('status'))->toBe('active');
    expect(AdminLog::where('action', 'billing_invoice_approved')->sole()->new_values['note'])->toBe('Bank transfer #12');
    $this->postJson("/api/admin/billing/invoices/{$invoice->id}/approve", ['note' => 'again'])->assertUnprocessable();
});

it('cancels a pending invoice with its open attempts and resolves a payment that needs review', function () {
    $invoice = billingInvoice();
    $attempt = PaymentTransaction::create(['business_id' => $invoice->business_id, 'invoice_id' => $invoice->id, 'provider' => 'idram',
        'provider_transaction_id' => 'pay_OPEN', 'amount' => 10000, 'currency' => 'AMD', 'status' => 'pending']);
    $late = PaymentTransaction::create(['business_id' => $invoice->business_id, 'invoice_id' => $invoice->id, 'provider' => 'idram',
        'provider_transaction_id' => 'pay_LATE', 'amount' => 10000, 'currency' => 'AMD', 'status' => 'paid',
        'provider_payload' => ['requires_manual_review' => true, 'EDP_PAYER_ACCOUNT' => '123456789']]);
    Sanctum::actingAs(billingAdmin());
    $this->postJson("/api/admin/billing/invoices/{$invoice->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect($attempt->fresh()->status)->toBe('failed');
    $this->getJson('/api/admin/billing/summary')->assertOk()->assertJsonPath('data.review_required', 1);
    $this->getJson('/api/admin/billing/transactions?review=1')->assertOk()->assertJsonPath('data.data.0.reference', 'pay_LATE')
        ->assertJsonPath('data.data.0.payer_account', '123456789');
    $this->postJson("/api/admin/billing/transactions/{$late->id}/resolve-review", ['note' => 'Refunded in Idram'])->assertOk()
        ->assertJsonPath('data.requires_manual_review', false);
    $this->getJson('/api/admin/billing/summary')->assertOk()->assertJsonPath('data.review_required', 0);
    $this->postJson("/api/admin/billing/transactions/{$attempt->id}/resolve-review", ['note' => 'nothing'])->assertUnprocessable();
});

it('rejects business users and support admins from billing data', function () {
    billingInvoice();
    Sanctum::actingAs(billingAdmin(Admin::ROLE_SUPPORT));
    $this->getJson('/api/admin/billing/summary')->assertForbidden();
    Sanctum::actingAs(\App\Models\User::factory()->create());
    $this->getJson('/api/admin/billing/invoices')->assertUnauthorized();
});
