<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;

it('keeps the booking email unchanged when the client profile email changes later', function () {
    $business = Business::factory()->create();
    $client = Client::factory()->create([
        'business_id' => $business->id,
        'email' => 'newer@example.com',
    ]);
    $service = Service::factory()->create(['business_id' => $business->id]);
    $staff = User::factory()->staff($business->id)->create();

    $booking = Booking::query()->create([
        'booking_code' => 'SNAPSHOT1',
        'business_id' => $business->id,
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'client_id' => $client->id,
        'client_name' => 'Snapshot Client',
        'client_phone' => '+37498123456',
        'client_email' => 'original@example.com',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addMinutes(30),
        'status' => 'confirmed',
        'currency' => 'AMD',
    ]);

    $client->update(['email' => 'changed@example.com']);

    expect($booking->fresh()->load('client')->contactEmail())->toBe('original@example.com');
});

it('does not inherit a later client email when the booking snapshot is empty', function () {
    $business = Business::factory()->create();
    $client = Client::factory()->create([
        'business_id' => $business->id,
        'email' => null,
    ]);
    $service = Service::factory()->create(['business_id' => $business->id]);
    $staff = User::factory()->staff($business->id)->create();

    $booking = Booking::query()->create([
        'booking_code' => 'LEGACY01',
        'business_id' => $business->id,
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'client_id' => $client->id,
        'client_name' => 'Legacy Client',
        'client_phone' => '+37498123457',
        'client_email' => null,
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addMinutes(30),
        'status' => 'confirmed',
        'currency' => 'AMD',
    ]);

    $client->update(['email' => 'later@example.com']);

    expect($booking->fresh()->load('client')->contactEmail())->toBeNull();
});
