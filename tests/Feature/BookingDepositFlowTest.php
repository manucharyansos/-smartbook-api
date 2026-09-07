<?php

use App\Models\Booking;
use App\Models\BookingPayment;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config([
        'booking_payments.enabled' => true,
        'booking_payments.provider' => 'idbank_mock',
        'booking_payments.deposit_percent' => 20,
        'billing.allow_mock_payments' => true,
        'app.url' => 'https://api.vizit.am',
    ]);
    $this->guestToken = 'verified-guest-token';
    $this->booking = Booking::factory()->create([
        'booking_code' => 'PAY12345',
        'status' => 'confirmed',
        'final_price' => 10000,
        'currency' => 'AMD',
        'phone_verified_at' => now(),
        'guest_access_token_hash' => Hash::make($this->guestToken),
        'guest_access_expires_at' => now()->addDay(),
    ]);
});

it('requires the OTP-issued guest token before creating a checkout', function () {
    $this->postJson('/api/public/bookings/PAY12345/payments/idbank/session', [
        'return_url' => 'vizit://payment-return',
        'cancel_url' => 'vizit://payment-return?status=cancelled',
    ])->assertUnauthorized();
});

it('creates an idempotent server-priced mock deposit session', function () {
    $payload = ['return_url' => 'vizit://payment-return', 'cancel_url' => 'vizit://payment-return?status=cancelled'];
    $first = $this->withHeader('X-Guest-Token', $this->guestToken)
        ->postJson('/api/public/bookings/PAY12345/payments/idbank/session', $payload)
        ->assertCreated()->assertJsonPath('amount', 2000)->assertJsonPath('currency', 'AMD');

    $second = $this->withHeader('X-Guest-Token', $this->guestToken)
        ->postJson('/api/public/bookings/PAY12345/payments/idbank/session', $payload)
        ->assertOk();

    expect($second->json('reference'))->toBe($first->json('reference'))
        ->and(BookingPayment::query()->count())->toBe(1);
});

it('does not allow an arbitrary payment return host', function () {
    $this->withHeader('X-Guest-Token', $this->guestToken)
        ->postJson('/api/public/bookings/PAY12345/payments/idbank/session', [
            'return_url' => 'https://evil.example/steal',
            'cancel_url' => 'vizit://payment-return',
        ])->assertUnprocessable();
});

it('treats the server-side completion and status endpoint as authoritative', function () {
    $session = $this->withHeader('X-Guest-Token', $this->guestToken)
        ->postJson('/api/public/bookings/PAY12345/payments/idbank/session', [
            'return_url' => 'vizit://payment-return',
            'cancel_url' => 'vizit://payment-return?status=cancelled',
        ])->assertCreated();

    $reference = $session->json('reference');
    $paymentId = $session->json('invoice_id');
    $this->post('/api/public/booking-payments/mock/' . $reference . '/complete', ['status' => 'success'])
        ->assertRedirectContains('status=success');

    $this->withHeader('X-Guest-Token', $this->guestToken)
        ->getJson("/api/public/bookings/PAY12345/payments/{$paymentId}/status")
        ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.amount', 2000);
});
