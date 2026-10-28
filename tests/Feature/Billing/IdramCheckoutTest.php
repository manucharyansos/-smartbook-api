<?php

use App\Models\Business;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function idramCheckoutContext(): array
{
    config()->set([
        'billing.providers.default' => 'idram', 'billing.providers.mode' => 'live',
        'billing.providers.idram.live_enabled' => true, 'billing.providers.idram.rec_account' => '111222333',
        'billing.providers.idram.secret_key' => 'idram-test-secret-only',
        'billing.frontend_base_url' => 'https://vizit.am',
    ]);
    $business = Business::factory()->onboardingCompleted()->create(['billing_status' => 'suspended']);
    $owner = User::factory()->owner($business->id)->create();
    $plan = Plan::create(['name' => 'Idram QA', 'code' => 'idram-'.Str::lower(Str::random(8)), 'version' => 1,
        'allowed_business_types' => ['beauty', 'dental'], 'price' => 10, 'monthly_price' => 10,
        'currency' => 'AMD', 'seats' => 5, 'staff_limit' => 5, 'duration_days' => 30,
        'features' => ['analytics' => true], 'is_active' => true, 'is_visible' => true, 'sort_order' => 1]);
    Sanctum::actingAs($owner);
    $upgrade = test()->postJson('/api/billing/upgrade-request', ['plan_code' => $plan->code, 'payment_method' => 'wallet'])
        ->assertCreated()->assertJsonPath('provider.default', 'idram')->assertJsonPath('provider.checkout_available', true);
    $invoice = Invoice::findOrFail($upgrade->json('data.id'));
    $checkout = test()->postJson('/api/billing/checkout-session', ['invoice_id' => $invoice->id, 'payment_method' => 'wallet'])
        ->assertCreated()->assertJsonPath('provider.name', 'idram');
    $transaction = PaymentTransaction::findOrFail($checkout->json('data.id'));

    return compact('business', 'owner', 'plan', 'invoice', 'transaction', 'checkout');
}

function idramReceipt(array $context, array $changes = []): array
{
    $data = array_replace(['EDP_REC_ACCOUNT' => '111222333', 'EDP_BILL_NO' => $context['transaction']->provider_transaction_id,
        'EDP_AMOUNT' => '10.00', 'EDP_PAYER_ACCOUNT' => '123456789', 'EDP_TRANS_ID' => '20261028000001',
        'EDP_TRANS_DATE' => '28/10/2026'], $changes);
    $data['EDP_CHECKSUM'] = md5(implode(':', [$data['EDP_REC_ACCOUNT'], $data['EDP_AMOUNT'],
        'idram-test-secret-only', $data['EDP_BILL_NO'], $data['EDP_PAYER_ACCOUNT'], $data['EDP_TRANS_ID'], $data['EDP_TRANS_DATE']]));

    return $data;
}

it('opens an expiring signed form with the stored invoice amount and never exposes the secret', function () {
    $c = idramCheckoutContext();
    expect($c['checkout']->getContent())->not->toContain('idram-test-secret-only');
    $page = $this->get($c['transaction']->checkout_url)->assertOk()->assertSee('https://banking.idram.am/Payment/GetPayment', false)
        ->assertSee('value="111222333"', false)->assertSee('value="10.00"', false)
        ->assertDontSee('idram-test-secret-only')->assertHeader('Referrer-Policy', 'no-referrer');
    expect($page->headers->get('Content-Security-Policy'))->toContain('form-action https://banking.idram.am', "script-src 'nonce-");
    expect($page->headers->get('Cache-Control'))->toContain('no-store');
    $this->get($c['transaction']->checkout_url.'&amount=1')->assertForbidden();
    $this->travel(31)->minutes();
    $this->get($c['transaction']->checkout_url)->assertForbidden();
});

it('refreshes signed links while reusing the same pending payment attempt', function () {
    $c = idramCheckoutContext();
    $this->travel(31)->minutes();
    $r = $this->postJson('/api/billing/checkout-session', ['invoice_id' => $c['invoice']->id, 'payment_method' => 'wallet'])
        ->assertOk()->assertJsonPath('data.id', $c['transaction']->id);
    $this->get($r->json('data.checkout_url'))->assertOk();
    expect(PaymentTransaction::count())->toBe(1);
});

it('prechecks a real stored bill without marking it paid and activates it only once after confirmation', function () {
    $c = idramCheckoutContext();
    $base = ['EDP_PRECHECK' => 'YES', 'EDP_REC_ACCOUNT' => '111222333',
        'EDP_BILL_NO' => $c['transaction']->provider_transaction_id, 'EDP_AMOUNT' => '10.00'];
    $this->post('/api/payments/idram/result', $base)->assertOk()->assertContent('OK')->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    expect($c['invoice']->fresh()->status)->toBe('pending');
    expect($c['transaction']->fresh()->status)->toBe('pending');
    $receipt = idramReceipt($c);
    $this->post('/api/payments/idram/result', $receipt)->assertOk()->assertContent('OK');
    $end = Subscription::where('business_id', $c['business']->id)->firstOrFail()->current_period_ends_at->toISOString();
    expect($c['invoice']->fresh()->status)->toBe('approved');
    expect($c['invoice']->fresh()->payment_method)->toBe('wallet');
    expect($c['business']->fresh()->billing_status)->toBe('active');
    expect($c['transaction']->fresh()->provider_payment_id)->toBe($receipt['EDP_TRANS_ID']);
    $this->travel(2)->days();
    $this->post('/api/payments/idram/result', $receipt)->assertOk()->assertContent('OK');
    expect(Subscription::where('business_id', $c['business']->id)->firstOrFail()->current_period_ends_at->toISOString())->toBe($end);
    expect(PaymentWebhookEvent::where('provider', 'idram')->count())->toBe(1);
    $this->getJson('/api/billing/invoices/'.$c['invoice']->id.'/payment-status')->assertOk()
        ->assertJsonPath('data.invoice.status', 'approved')->assertJsonPath('data.transaction.requires_manual_review', false);
    $this->travelBack();
    $this->get($c['transaction']->checkout_url)->assertStatus(409);
});

it('checks decimal amounts without floating point and signs the exact original amount string', function (string $amount) {
    $c = idramCheckoutContext();
    $this->post('/api/payments/idram/result', idramReceipt($c, ['EDP_AMOUNT' => $amount]))->assertOk()->assertContent('OK');
    expect($c['invoice']->fresh()->status)->toBe('approved');
})->with(['10', '10.0', '10.00', '00010.00']);

it('rejects a wrong signature without activating a subscription', function () {
    $c = idramCheckoutContext();
    $data = idramReceipt($c);
    $data['EDP_CHECKSUM'] = str_repeat('0', 32);
    $this->post('/api/payments/idram/result', $data)->assertUnauthorized()->assertContent('REJECTED');
    expect($c['invoice']->fresh()->status)->toBe('pending');
    expect(PaymentWebhookEvent::count())->toBe(0);
});

it('rejects invalid or mismatched callback data', function (array $changes) {
    $c = idramCheckoutContext();
    $this->post('/api/payments/idram/result', idramReceipt($c, $changes))->assertStatus(400)->assertContent('REJECTED');
    expect($c['invoice']->fresh()->status)->toBe('pending');
    expect($c['transaction']->fresh()->status)->toBe('pending');
})->with([
    'merchant' => [['EDP_REC_ACCOUNT' => '999999999']], 'underpayment' => [['EDP_AMOUNT' => '9.99']],
    'overpayment' => [['EDP_AMOUNT' => '10.01']], 'zero' => [['EDP_AMOUNT' => '0']],
    'exponent' => [['EDP_AMOUNT' => '1e1']], 'comma' => [['EDP_AMOUNT' => '10,00']],
    'extra precision' => [['EDP_AMOUNT' => '10.001']], 'invalid date' => [['EDP_TRANS_DATE' => '31/02/2026']],
    'short receipt' => [['EDP_TRANS_ID' => '123']], 'payer' => [['EDP_PAYER_ACCOUNT' => 'invalid']],
]);

it('returns no OK for an unknown or cancelled precheck', function () {
    $c = idramCheckoutContext();
    $payload = ['EDP_PRECHECK' => 'YES', 'EDP_REC_ACCOUNT' => '111222333', 'EDP_AMOUNT' => '10', 'EDP_BILL_NO' => 'not-a-bill'];
    $this->post('/api/payments/idram/result', $payload)->assertNotFound()->assertContent('REJECTED');
    $payload['EDP_BILL_NO'] = $c['transaction']->provider_transaction_id;
    $payload['EDP_PRECHECK'] = 'NO';
    $this->post('/api/payments/idram/result', $payload)->assertStatus(400);
    $payload['EDP_PRECHECK'] = 'YES';
    $c['invoice']->update(['status' => 'cancelled']);
    $this->post('/api/payments/idram/result', $payload)->assertStatus(409);
});

it('never treats a forged success or fail browser return as payment confirmation', function () {
    $c = idramCheckoutContext();
    foreach (['success', 'fail'] as $path) {
        $r = $this->post('/api/payments/idram/'.$path, ['EDP_BILL_NO' => $c['transaction']->provider_transaction_id,
            'EDP_AMOUNT' => '10', 'EDP_CHECKSUM' => 'forged'])->assertStatus(303);
        expect($r->headers->get('Location'))->toStartWith('https://vizit.am/payment-return?provider=idram');
        expect($c['invoice']->fresh()->status)->toBe('pending');
        expect($c['transaction']->fresh()->status)->toBe('pending');
    }
    expect(PaymentWebhookEvent::count())->toBe(0);
});

it('records a late real payment for review without reactivating a cancelled invoice', function () {
    $c = idramCheckoutContext();
    $this->postJson('/api/billing/invoices/'.$c['invoice']->id.'/cancel')->assertOk();
    $this->post('/api/payments/idram/result', idramReceipt($c))->assertOk()->assertContent('OK');
    expect($c['invoice']->fresh()->status)->toBe('cancelled');
    expect($c['transaction']->fresh()->status)->toBe('paid');
    expect($c['transaction']->fresh()->requires_manual_review)->toBeTrue();
    expect($c['business']->fresh()->billing_status)->toBe('suspended');
    expect(PaymentWebhookEvent::firstOrFail()->status)->toBe('review_required');
});

it('records a second bank receipt on the same bill for review without extending twice', function () {
    $c = idramCheckoutContext();
    $this->post('/api/payments/idram/result', idramReceipt($c))->assertOk();
    $end = Subscription::where('business_id', $c['business']->id)->firstOrFail()->current_period_ends_at->toISOString();
    $this->post('/api/payments/idram/result', idramReceipt($c, ['EDP_TRANS_ID' => '20261028000002']))->assertOk()->assertContent('OK');
    expect($c['transaction']->fresh()->requires_manual_review)->toBeTrue();
    expect($c['transaction']->fresh()->provider_payment_id)->toBe('20261028000001');
    expect(PaymentWebhookEvent::count())->toBe(2);
    expect(Subscription::where('business_id', $c['business']->id)->firstOrFail()->current_period_ends_at->toISOString())->toBe($end);
});

it('cannot apply the same Idram receipt to another invoice or company', function () {
    $a = idramCheckoutContext();
    $this->post('/api/payments/idram/result', idramReceipt($a))->assertOk();
    $b = idramCheckoutContext();
    $this->post('/api/payments/idram/result', idramReceipt($b))->assertStatus(409)->assertContent('REJECTED');
    expect($b['invoice']->fresh()->status)->toBe('pending');
    expect($b['transaction']->fresh()->status)->toBe('pending');
    expect(PaymentWebhookEvent::count())->toBe(1);
    $this->postJson('/api/billing/checkout-session', ['invoice_id' => $a['invoice']->id, 'payment_method' => 'wallet'])->assertNotFound();
});

it('keeps real checkout unavailable until the merchant account and secret are configured', function () {
    $c = idramCheckoutContext();
    config()->set('billing.providers.idram.secret_key', '');
    $this->getJson('/api/billing/me')->assertOk()->assertJsonPath('data.payment_provider.default', 'idram')
        ->assertJsonPath('data.payment_provider.checkout_available', false);
    $this->postJson('/api/billing/checkout-session', ['invoice_id' => $c['invoice']->id, 'payment_method' => 'wallet'])
        ->assertStatus(503)->assertJsonPath('code', 'live_payments_disabled');
    $this->get($c['transaction']->checkout_url)->assertStatus(503);
    $this->post('/api/payments/idram/result', idramReceipt($c))->assertStatus(503)->assertContent('UNAVAILABLE');
});

it('publishes the three bank registration URLs before merchant credentials exist', function () {
    config()->set('billing.providers.idram.secret_key', '');
    $this->get('/api/payments/idram/result')->assertOk()->assertJsonPath('callback_method', 'POST');
    foreach (['success', 'fail'] as $path) {
        $this->get('/api/payments/idram/'.$path)->assertStatus(303);
    }
    $this->post('/api/payments/idram/result')->assertStatus(503)->assertContent('UNAVAILABLE');
    expect(PaymentTransaction::count())->toBe(0);
});

it('continues accepting a verified pending receipt after new checkouts are disabled', function () {
    $c = idramCheckoutContext();
    config()->set('billing.providers.idram.live_enabled', false);
    $this->get($c['transaction']->checkout_url)->assertStatus(503);
    $this->post('/api/payments/idram/result', idramReceipt($c))->assertOk()->assertContent('OK');
    expect($c['invoice']->fresh()->status)->toBe('approved');
});

it('returns a payer who started checkout in the mobile app to the app without confirming payment', function () {
    $c = idramCheckoutContext();
    $this->postJson('/api/billing/checkout-session', ['invoice_id' => $c['invoice']->id, 'payment_method' => 'wallet', 'return_to' => 'app'])
        ->assertOk()->assertJsonPath('data.id', $c['transaction']->id);
    $r = $this->get('/api/payments/idram/success?EDP_BILL_NO='.$c['transaction']->provider_transaction_id)->assertStatus(303);
    expect($r->headers->get('Location'))->toStartWith('vizit://billing?provider=idram&return=success');
    expect($c['invoice']->fresh()->status)->toBe('pending');
    $this->postJson('/api/billing/checkout-session', ['invoice_id' => $c['invoice']->id, 'payment_method' => 'wallet'])->assertOk();
    $r = $this->get('/api/payments/idram/fail?EDP_BILL_NO='.$c['transaction']->provider_transaction_id)->assertStatus(303);
    expect($r->headers->get('Location'))->toStartWith('https://vizit.am/payment-return?provider=idram&return=fail');
});

it('accepts the signed checkout link when TLS ends at a proxy in front of PHP', function () {
    config()->set('app.url', 'https://api.vizit.am');
    $c = idramCheckoutContext();
    expect($c['transaction']->checkout_url)->toStartWith('https://api.vizit.am/api/payments/idram/checkout/');
    $path = substr($c['transaction']->checkout_url, strlen('https://api.vizit.am'));
    $this->get('http://api.vizit.am'.$path)->assertOk();
});

it('does not let the Apache config add a second policy that blocks the Idram checkout form', function () {
    $htaccess = file_get_contents(public_path('.htaccess'));
    preg_match_all('/^\s*Header\s+always\s+set\s+Content-Security-Policy\s.*$/mi', $htaccess, $lines);
    expect($lines[0])->not->toBeEmpty();
    foreach ($lines[0] as $line) {
        expect($line)->toContain('/api/payments/idram/checkout/');
    }
});
