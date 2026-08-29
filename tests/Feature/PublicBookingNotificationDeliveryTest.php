<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config([
        'mail.default' => 'array',
        'services.telegram.enabled' => false,
    ]);

    $this->business = Business::factory()->create(['timezone' => 'UTC']);
    $this->staff = User::factory()->staff($this->business->id)->create(['is_active' => true]);
    $this->service = Service::factory()->create(['business_id' => $this->business->id]);
    $this->client = Client::factory()->create([
        'business_id' => $this->business->id,
        'email' => 'delivery@example.com',
    ]);
    $this->booking = Booking::query()->create([
        'booking_code' => 'MAIL1234',
        'business_id' => $this->business->id,
        'service_id' => $this->service->id,
        'staff_id' => $this->staff->id,
        'client_id' => $this->client->id,
        'client_name' => 'Delivery Customer',
        'client_phone' => '+37499123456',
        'client_email' => 'delivery@example.com',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'status' => 'pending',
        'currency' => 'AMD',
    ]);
});

it('reports successful verification email delivery', function () {
    Mail::fake();

    $this->postJson('/api/public/bookings/MAIL1234/resend')
        ->assertOk()
        ->assertJsonPath('verification_delivery.email', true)
        ->assertJsonPath('verification_delivery.telegram', false);
});

it('does not claim success when no verification channel delivered the code', function () {
    Mail::shouldReceive('send')
        ->once()
        ->andThrow(new RuntimeException('SMTP unavailable'));

    $this->postJson('/api/public/bookings/MAIL1234/resend')
        ->assertStatus(503)
        ->assertJsonPath('verification_delivery.email', false)
        ->assertJsonPath('verification_delivery.telegram', false);
});

it('falls back to the local mail transport when primary smtp delivery fails', function () {
    app()->detectEnvironment(fn () => 'production');
    config([
        'mail.default' => 'smtp',
        'mail.verification_fallback' => 'sendmail',
    ]);

    $fallback = Mockery::mock();
    $fallback->shouldReceive('send')->once();

    Mail::shouldReceive('send')
        ->once()
        ->andThrow(new RuntimeException('Primary SMTP unavailable'));
    Mail::shouldReceive('mailer')
        ->once()
        ->with('sendmail')
        ->andReturn($fallback);

    try {
        $this->postJson('/api/public/bookings/MAIL1234/resend')
            ->assertOk()
            ->assertJsonPath('verification_delivery.email', true)
            ->assertJsonPath('verification_delivery.telegram', false);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('keeps the verification code but removes the internal booking code from customer email content', function () {
    $verificationHtml = view('emails.public_booking_verification', [
        'booking' => $this->booking->load(['business', 'service', 'staff', 'items.service']),
        'code' => '2468',
        'expires' => now()->addMinutes(10),
        'manageLink' => 'https://vizit.am/book/example',
    ])->render();

    $confirmedHtml = view('emails.public_booking_confirmed', [
        'booking' => $this->booking,
        'manageLink' => 'https://vizit.am/book/example',
    ])->render();

    expect($verificationHtml)
        ->toContain('2468')
        ->not->toContain('MAIL1234')
        ->not->toContain('Ամրագրման կոդ');
    expect($confirmedHtml)
        ->not->toContain('MAIL1234')
        ->not->toContain('Ամրագրման կոդ');
});
