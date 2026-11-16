<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('shows only past visits as the last visit and future ones as the next visit', function () {
    $business = Business::factory()->billable()->create();
    $owner = User::factory()->owner($business->id)->create();
    $service = Service::factory()->create(['business_id' => $business->id]);
    $client = Client::factory()->create(['business_id' => $business->id]);
    $past = now()->subDays(3)->startOfMinute();
    $future = now()->addDays(2)->startOfMinute();
    foreach ([$past, $future] as $startsAt) {
        Booking::factory()->create([
            'business_id' => $business->id, 'service_id' => $service->id, 'client_id' => $client->id, 'staff_id' => $owner->id,
            'status' => 'confirmed', 'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addMinutes(30),
        ]);
    }
    Sanctum::actingAs($owner);
    // Same wall clock as BookingResource / the calendar: business-local, not UTC.
    $local = fn ($date) => $date->copy()->setTimezone($business->effectiveTimezone())->format('Y-m-d H:i:s');

    $row = collect($this->getJson('/api/clients')->assertOk()->json('data'))->firstWhere('id', $client->id);
    expect($row['last_booking_at'])->toBe($local($past))
        ->and($row['next_booking_at'])->toBe($local($future));

    $detail = $this->getJson("/api/clients/{$client->id}")->assertOk()->json('data');
    expect($detail['last_booking_at'])->toBe($local($past))
        ->and($detail['next_booking_at'])->toBe($local($future))
        ->and(collect($detail['timeline'])->pluck('occurred_at')->all())->toContain($local($future));
});

it('has no last visit for a client whose only booking is upcoming', function () {
    $business = Business::factory()->billable()->create();
    $owner = User::factory()->owner($business->id)->create();
    $service = Service::factory()->create(['business_id' => $business->id]);
    $client = Client::factory()->create(['business_id' => $business->id]);
    $future = now()->addDay();
    Booking::factory()->create([
        'business_id' => $business->id, 'service_id' => $service->id, 'client_id' => $client->id, 'staff_id' => $owner->id,
        'status' => 'confirmed', 'starts_at' => $future, 'ends_at' => $future->copy()->addHour(),
    ]);
    Sanctum::actingAs($owner);

    $row = collect($this->getJson('/api/clients')->assertOk()->json('data'))->firstWhere('id', $client->id);
    expect($row['last_booking_at'])->toBeNull()->and($row['next_booking_at'])->not->toBeNull();
});

it('keeps the WhatsApp number and its wa.me link in sync', function () {
    $business = Business::factory()->billable()->create();
    Sanctum::actingAs(User::factory()->owner($business->id)->create());

    $this->patchJson('/api/business/settings', ['whatsapp_phone' => '+374 91 408828'])
        ->assertOk()
        ->assertJsonPath('data.whatsapp_phone', '+374 91 408828')
        ->assertJsonPath('data.whatsapp_url', 'https://wa.me/37491408828');
    $this->getJson('/api/business/settings')->assertOk()->assertJsonPath('data.whatsapp_phone', '+374 91 408828');

    $this->patchJson('/api/business/settings', ['whatsapp_phone' => null])
        ->assertOk()
        ->assertJsonPath('data.whatsapp_phone', null)
        ->assertJsonPath('data.whatsapp_url', null);

    $this->patchJson('/api/business/settings', ['whatsapp_phone' => 'not a phone'])
        ->assertUnprocessable()->assertJsonValidationErrors('whatsapp_phone');
});

it('shows the number of a business saved before the WhatsApp number was stored', function () {
    $business = Business::factory()->billable()->create(['whatsapp_url' => 'https://wa.me/37491408828']);
    Sanctum::actingAs(User::factory()->owner($business->id)->create());

    $this->getJson('/api/business/settings')->assertOk()->assertJsonPath('data.whatsapp_phone', '+37491408828');
});
