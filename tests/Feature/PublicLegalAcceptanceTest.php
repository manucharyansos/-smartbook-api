<?php

use App\Models\Business;
use App\Models\Service;
use App\Models\WaitlistEntry;

function publicBookingBasePayload(): array
{
    return [
        'service_id' => 999999,
        'starts_at' => '2030-01-01 10:00',
        'client_name' => 'Legal Test',
        'client_phone' => '+37499123456',
        'client_email' => 'legal@example.com',
    ];
}

it('requires current legal acceptance on every public booking and waitlist entry point', function () {
    $single = publicBookingBasePayload();
    $requests = [
        ['/api/public/businesses/missing/bookings', $single],
        ['/api/public/businesses/missing/bookings/multi', array_merge($single, ['service_ids' => [999999]])],
        ['/api/public/businesses/missing/bookings/lines', array_merge($single, [
            'lines' => [['service_id' => 999999, 'starts_at' => '2030-01-01 10:00']],
        ])],
        ['/api/public/businesses/missing/waitlist', [
            'service_id' => 999999,
            'customer_name' => 'Legal Test',
            'customer_phone' => '+37499123456',
            'customer_email' => 'legal@example.com',
            'desired_date' => '2030-01-01',
        ]],
    ];

    foreach ($requests as [$url, $payload]) {
        $this->postJson($url, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['legal_accepted', 'terms_version', 'privacy_version']);
    }
});

it('rejects stale legal document versions before creating a public record', function () {
    $payload = array_merge(publicBookingBasePayload(), [
        'legal_accepted' => true,
        'terms_version' => 'outdated',
        'privacy_version' => 'outdated',
    ]);

    $this->postJson('/api/v1/public/businesses/missing/bookings', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['terms_version', 'privacy_version']);
});

it('stores legal evidence separately from optional marketing consent on waitlist entry', function () {
    $business = Business::factory()->create([
        'status' => 'active',
        'is_onboarding_completed' => true,
        'is_public_profile_enabled' => true,
    ]);
    $service = Service::factory()->create([
        'business_id' => $business->id,
        'is_active' => true,
        'booking_mode' => 'individual',
        'capacity' => 1,
    ]);

    $this->withHeader('User-Agent', 'Vizit legal acceptance test')
        ->postJson("/api/public/businesses/{$business->slug}/waitlist", [
            'service_id' => $service->id,
            'customer_name' => 'Legal Test',
            'customer_phone' => '+37499123456',
            'customer_email' => 'legal@example.com',
            'desired_date' => now($business->effectiveTimezone())->addDay()->format('Y-m-d'),
            'legal_accepted' => true,
            'terms_version' => config('legal.terms_version'),
            'privacy_version' => config('legal.privacy_version'),
        ])
        ->assertCreated();

    $entry = WaitlistEntry::query()->latest('id')->firstOrFail();
    expect($entry->legal_accepted_at)->not->toBeNull()
        ->and($entry->terms_version)->toBe(config('legal.terms_version'))
        ->and($entry->privacy_version)->toBe(config('legal.privacy_version'))
        ->and($entry->legal_acceptance_ip)->not->toBeNull()
        ->and($entry->legal_acceptance_user_agent)->toBe('Vizit legal acceptance test');
});
