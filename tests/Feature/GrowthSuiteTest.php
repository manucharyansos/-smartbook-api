<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\Client;
use App\Models\MarketingCampaign;
use App\Models\Service;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\AvailabilityService;
use App\Services\MarketingCampaignService;
use App\Services\WaitlistService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-28 08:00:00', 'UTC'));
    Mail::fake();
    Notification::fake();
    config(['services.telegram.enabled' => false]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('keeps a waiting-list offer reserved and lets its owner accept that exact hold', function () {
    $business = Business::factory()->create([
        'timezone' => 'UTC',
        'is_onboarding_completed' => true,
        'is_public_profile_enabled' => true,
        'work_start' => '09:00:00',
        'work_end' => '18:00:00',
    ]);
    $staff = User::factory()->staff($business->id)->create(['is_active' => true, 'is_bookable' => true]);
    $service = Service::factory()->create([
        'business_id' => $business->id,
        'duration_minutes' => 60,
        'booking_mode' => 'individual',
        'capacity' => 1,
    ]);
    $token = 'known-waitlist-token';
    $entry = WaitlistEntry::query()->create([
        'business_id' => $business->id,
        'service_id' => $service->id,
        'customer_name' => 'Waiting Customer',
        'customer_phone' => '+37499123456',
        'customer_email' => 'waiting@example.com',
        'desired_date' => '2026-09-01',
        'party_size' => 1,
        'status' => 'offered',
        'offered_staff_id' => $staff->id,
        'offered_starts_at' => Carbon::parse('2026-09-01 10:00:00', 'UTC'),
        'offered_ends_at' => Carbon::parse('2026-09-01 11:00:00', 'UTC'),
        'offer_token_hash' => hash('sha256', $token),
        'offer_expires_at' => now()->addMinutes(30),
    ]);

    $waitlist = app(WaitlistService::class);
    expect($waitlist->slotCanFit(
        $business,
        $service,
        $staff->id,
        Carbon::parse('2026-09-01 10:00:00', 'UTC'),
        Carbon::parse('2026-09-01 11:00:00', 'UTC'),
        1,
    ))->toBeFalse();

    $slots = app(AvailabilityService::class)->slotsForDay(
        $staff->id,
        $service->id,
        '2026-09-01',
        $business->id,
    );
    expect(collect($slots)->contains(fn (array $slot) => $slot['starts_at'] === '2026-09-01 10:00:00'))->toBeFalse();

    $accepted = $waitlist->acceptOffer($entry, $token);
    expect($accepted['booking']->status)->toBe('confirmed');
    expect($accepted['booking']->source)->toBe('waitlist');
    $this->assertDatabaseHas('waitlist_entries', ['id' => $entry->id, 'status' => 'booked']);
});

it('enforces group capacity for bookings and active waiting-list holds', function () {
    $business = Business::factory()->create(['timezone' => 'UTC']);
    $staff = User::factory()->staff($business->id)->create(['is_active' => true, 'is_bookable' => true]);
    $service = Service::factory()->create([
        'business_id' => $business->id,
        'duration_minutes' => 60,
        'booking_mode' => 'group',
        'capacity' => 5,
    ]);
    $client = Client::factory()->create(['business_id' => $business->id]);
    Booking::query()->create([
        'booking_code' => 'GROUP001',
        'business_id' => $business->id,
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'client_id' => $client->id,
        'client_name' => $client->name,
        'client_phone' => $client->phone,
        'starts_at' => Carbon::parse('2026-09-01 10:00:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-09-01 11:00:00', 'UTC'),
        'party_size' => 3,
        'status' => 'confirmed',
        'currency' => 'AMD',
    ]);
    WaitlistEntry::query()->create([
        'business_id' => $business->id,
        'service_id' => $service->id,
        'customer_name' => 'Held Customer',
        'customer_phone' => '+37499111111',
        'customer_email' => 'held@example.com',
        'desired_date' => '2026-09-01',
        'party_size' => 1,
        'status' => 'offered',
        'offered_staff_id' => $staff->id,
        'offered_starts_at' => Carbon::parse('2026-09-01 10:00:00', 'UTC'),
        'offered_ends_at' => Carbon::parse('2026-09-01 11:00:00', 'UTC'),
        'offer_token_hash' => hash('sha256', 'hold'),
        'offer_expires_at' => now()->addMinutes(30),
    ]);

    $waitlist = app(WaitlistService::class);
    expect($waitlist->slotCanFit(
        $business,
        $service,
        $staff->id,
        Carbon::parse('2026-09-01 10:00:00', 'UTC'),
        Carbon::parse('2026-09-01 11:00:00', 'UTC'),
        1,
    ))->toBeTrue();
    expect($waitlist->slotCanFit(
        $business,
        $service,
        $staff->id,
        Carbon::parse('2026-09-01 10:00:00', 'UTC'),
        Carbon::parse('2026-09-01 11:00:00', 'UTC'),
        2,
    ))->toBeFalse();
});

it('creates and cancels future recurring bookings as one series', function () {
    $business = Business::factory()->billable()->create([
        'timezone' => 'UTC',
        'work_start' => '09:00:00',
        'work_end' => '18:00:00',
    ]);
    $owner = User::factory()->owner($business->id)->create(['is_active' => true]);
    $staff = User::factory()->staff($business->id)->create(['is_active' => true, 'is_bookable' => true]);
    $service = Service::factory()->create([
        'business_id' => $business->id,
        'duration_minutes' => 60,
        'booking_mode' => 'individual',
        'capacity' => 1,
    ]);
    Sanctum::actingAs($owner);

    $response = $this->postJson('/api/bookings', [
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-01 10:00',
        'client_name' => 'Recurring Customer',
        'client_phone' => '+37499123456',
        'status' => 'confirmed',
        'recurrence_frequency' => 'weekly',
        'recurrence_count' => 3,
    ])->assertCreated();

    $recurrenceId = $response->json('recurrence_id');
    expect($recurrenceId)->not->toBeEmpty();
    $series = Booking::query()->where('recurrence_id', $recurrenceId)->orderBy('starts_at')->get();
    expect($series)->toHaveCount(3);

    $this->patchJson('/api/bookings/' . $series[1]->id . '/recurrence/cancel', ['scope' => 'future'])
        ->assertOk()
        ->assertJsonCount(2, 'cancelled_booking_ids');

    expect($series[0]->fresh()->status)->toBe('confirmed');
    expect($series[1]->fresh()->status)->toBe('cancelled');
    expect($series[2]->fresh()->status)->toBe('cancelled');
});

it('sends a campaign only to clients who have opted in and does not duplicate deliveries', function () {
    $business = Business::factory()->create();
    Client::factory()->create(['business_id' => $business->id, 'email' => 'allowed@example.com', 'marketing_opt_in' => true]);
    Client::factory()->create(['business_id' => $business->id, 'email' => 'blocked@example.com', 'marketing_opt_in' => false]);
    $campaign = MarketingCampaign::query()->create([
        'business_id' => $business->id,
        'name' => 'September offer',
        'channel' => 'email',
        'segment' => 'all',
        'subject' => 'Offer',
        'body' => 'Hello {{name}}',
        'status' => 'draft',
    ]);

    $service = app(MarketingCampaignService::class);
    expect($service->recipientCount($campaign))->toBe(1);
    $service->dispatch($campaign);
    $service->dispatch($campaign->fresh());

    $this->assertDatabaseCount('marketing_deliveries', 1);
    $this->assertDatabaseHas('marketing_deliveries', ['email' => 'allowed@example.com', 'status' => 'sent']);
});
