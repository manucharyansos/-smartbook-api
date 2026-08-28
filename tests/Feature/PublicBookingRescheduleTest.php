<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Notifications\BookingRescheduledNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-28 08:00:00', 'UTC'));
    config([
        'services.public_booking.reschedule_cutoff_hours' => 12,
        'services.telegram.enabled' => false,
    ]);
    Notification::fake();

    $this->guestToken = 'secure-guest-token';
    $this->business = Business::factory()->create([
        'business_type' => 'beauty',
        'timezone' => 'UTC',
        'work_start' => '09:00:00',
        'work_end' => '18:00:00',
        'slot_step_minutes' => 15,
    ]);
    $this->owner = User::factory()->owner($this->business->id)->create([
        'is_active' => true,
        'is_bookable' => false,
    ]);
    $this->staff = User::factory()->staff($this->business->id)->create([
        'is_active' => true,
        'is_bookable' => true,
        'show_in_public_team' => true,
    ]);
    $this->service = Service::factory()->create([
        'business_id' => $this->business->id,
        'duration_minutes' => 60,
        'is_active' => true,
    ]);
    $this->client = Client::factory()->create([
        'business_id' => $this->business->id,
        'email' => null,
    ]);
    $this->booking = Booking::query()->create([
        'booking_code' => 'MOVE1234',
        'business_id' => $this->business->id,
        'service_id' => $this->service->id,
        'staff_id' => $this->staff->id,
        'client_id' => $this->client->id,
        'client_name' => 'Guest Customer',
        'client_phone' => '+37499123456',
        'client_email' => null,
        'starts_at' => Carbon::parse('2026-08-31 10:00:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-08-31 11:00:00', 'UTC'),
        'status' => 'confirmed',
        'final_price' => 10000,
        'currency' => 'AMD',
        'phone_verified_at' => now(),
        'guest_access_token_hash' => Hash::make($this->guestToken),
        'guest_access_expires_at' => now()->addDays(7),
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('lets a verified guest move an eligible booking to a free slot', function () {
    $response = $this->withHeader('X-Guest-Token', $this->guestToken)
        ->postJson('/api/public/bookings/MOVE1234/reschedule', [
            'booking_id' => $this->booking->id,
            'staff_id' => $this->staff->id,
            'starts_at' => '2026-09-01 11:00',
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.booking_code', 'MOVE1234')
        ->assertJsonPath('data.can_reschedule', true)
        ->assertJsonMissingPath('data.bookings.0.guest_access_token_hash')
        ->assertJsonMissingPath('data.bookings.0.phone_verification_code_hash');

    $this->assertDatabaseHas('bookings', [
        'id' => $this->booking->id,
        'staff_id' => $this->staff->id,
        'starts_at' => '2026-09-01 11:00:00',
        'ends_at' => '2026-09-01 12:00:00',
    ]);

    Notification::assertSentTo($this->owner, BookingRescheduledNotification::class);
    Notification::assertSentTo($this->staff, BookingRescheduledNotification::class);
});

it('returns only free options and omits the unchanged current slot', function () {
    $response = $this->withHeader('X-Guest-Token', $this->guestToken)
        ->getJson('/api/public/bookings/MOVE1234/reschedule-options?' . http_build_query([
            'booking_id' => $this->booking->id,
            'date' => '2026-08-31',
        ]));

    $response
        ->assertOk()
        ->assertJsonPath('meta.can_reschedule', true)
        ->assertJsonPath('meta.reschedule_cutoff_hours', 12);

    $currentSlotExists = collect($response->json('data'))->contains(fn (array $slot) =>
        ($slot['starts_at'] ?? null) === '2026-08-31 10:00:00'
        && (int) ($slot['staff_id'] ?? 0) === $this->staff->id
    );

    expect($currentSlotExists)->toBeFalse();
});

it('rejects a move into an occupied slot', function () {
    $otherClient = Client::factory()->create(['business_id' => $this->business->id]);
    Booking::query()->create([
        'booking_code' => 'BUSY1234',
        'business_id' => $this->business->id,
        'service_id' => $this->service->id,
        'staff_id' => $this->staff->id,
        'client_id' => $otherClient->id,
        'client_name' => 'Other Customer',
        'client_phone' => '+37499111111',
        'starts_at' => Carbon::parse('2026-09-01 11:00:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-09-01 12:00:00', 'UTC'),
        'status' => 'confirmed',
        'currency' => 'AMD',
    ]);

    $this->withHeader('X-Guest-Token', $this->guestToken)
        ->postJson('/api/public/bookings/MOVE1234/reschedule', [
            'booking_id' => $this->booking->id,
            'staff_id' => $this->staff->id,
            'starts_at' => '2026-09-01 11:00',
        ])
        ->assertUnprocessable();

    $this->assertDatabaseHas('bookings', [
        'id' => $this->booking->id,
        'starts_at' => '2026-08-31 10:00:00',
    ]);
});

it('rejects rescheduling after the cutoff or with an invalid guest token', function () {
    $this->booking->update([
        'starts_at' => now()->addHours(10),
        'ends_at' => now()->addHours(11),
    ]);

    $payload = [
        'booking_id' => $this->booking->id,
        'staff_id' => $this->staff->id,
        'starts_at' => '2026-09-01 11:00',
    ];

    $this->withHeader('X-Guest-Token', $this->guestToken)
        ->postJson('/api/public/bookings/MOVE1234/reschedule', $payload)
        ->assertUnprocessable();

    $this->booking->update([
        'starts_at' => Carbon::parse('2026-08-31 10:00:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-08-31 11:00:00', 'UTC'),
    ]);

    $this->withHeader('X-Guest-Token', 'wrong-token')
        ->postJson('/api/public/bookings/MOVE1234/reschedule', $payload)
        ->assertUnauthorized();
});
