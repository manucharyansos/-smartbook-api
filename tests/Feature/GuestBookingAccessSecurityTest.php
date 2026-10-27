<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Service;
use App\Models\User;
use App\Services\ClientIdentityLinker;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config([
        'mail.default' => 'array',
        'services.telegram.enabled' => false,
    ]);
    Mail::fake();

    $this->business = Business::factory()->create(['timezone' => 'UTC']);
    $this->staff = User::factory()->staff($this->business->id)->create(['is_active' => true]);
    $this->service = Service::factory()->create(['business_id' => $this->business->id]);
    $this->client = Client::factory()->create([
        'business_id' => $this->business->id,
        'email' => 'guest-owner@example.com',
    ]);
    $this->booking = Booking::query()->create([
        'booking_code' => 'GUESTSEC1',
        'business_id' => $this->business->id,
        'service_id' => $this->service->id,
        'staff_id' => $this->staff->id,
        'client_id' => $this->client->id,
        'client_name' => 'Guest Owner',
        'client_phone' => '+37499123456',
        'client_email' => 'guest-owner@example.com',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'status' => 'confirmed',
        'currency' => 'AMD',
        'phone_verified_at' => now()->subHour(),
    ]);
});

it('does not issue a guest token for a verified booking from the booking code alone', function () {
    $this->postJson('/api/public/bookings/GUESTSEC1/resend')
        ->assertOk()
        ->assertJsonMissingPath('manage_token');

    $this->postJson('/api/v1/public/bookings/GUESTSEC1/resend')
        ->assertOk()
        ->assertJsonMissingPath('manage_token');

    expect($this->booking->fresh()->guest_access_token_hash)->toBeNull();
});

it('rejects a wrong otp for a verified booking instead of issuing a token', function () {
    $this->booking->update([
        'phone_verification_code_hash' => Hash::make('4321'),
        'phone_verification_expires_at' => now()->addMinutes(10),
    ]);

    $this->postJson('/api/public/bookings/GUESTSEC1/verify', ['otp' => '1111'])
        ->assertStatus(422)
        ->assertJsonMissingPath('manage_token');

    expect($this->booking->fresh()->guest_access_token_hash)->toBeNull();
});

it('rejects verification of a verified booking when no recovery code was requested', function () {
    $this->postJson('/api/public/bookings/GUESTSEC1/verify', ['otp' => '1234'])
        ->assertStatus(422)
        ->assertJsonMissingPath('manage_token');
});

it('recovers guest access for a verified booking with a fresh otp without changing its status', function () {
    $this->booking->update([
        'status' => 'done',
        'phone_verification_code_hash' => Hash::make('4321'),
        'phone_verification_expires_at' => now()->addMinutes(10),
    ]);

    $token = $this->postJson('/api/public/bookings/GUESTSEC1/verify', ['otp' => '4321'])
        ->assertOk()
        ->assertJsonPath('already', true)
        ->json('manage_token');

    expect($token)->toBeString()->not->toBeEmpty();

    $fresh = $this->booking->fresh();
    expect($fresh->status)->toBe('done')
        ->and($fresh->phone_verification_code_hash)->toBeNull();

    $this->withToken($token)->getJson('/api/public/bookings/GUESTSEC1')->assertOk();

    // The recovery code is single use.
    $this->postJson('/api/public/bookings/GUESTSEC1/verify', ['otp' => '4321'])->assertStatus(422);
});

it('locks recovery after five wrong attempts', function () {
    $this->booking->update([
        'phone_verification_code_hash' => Hash::make('4321'),
        'phone_verification_expires_at' => now()->addMinutes(10),
    ]);

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/public/bookings/GUESTSEC1/verify', ['otp' => '0000'])->assertStatus(422);
    }

    $this->postJson('/api/public/bookings/GUESTSEC1/verify', ['otp' => '4321'])->assertStatus(429);
});

function verifiedCabinetAccount(string $email): ClientAccount
{
    $account = ClientAccount::query()->create([
        'name' => 'Cabinet Client',
        'email' => $email,
        'phone' => '+37498111111',
        'password' => 'A-client-password-123',
        'email_verified_at' => now(),
    ]);
    app(ClientIdentityLinker::class)->syncLinkedClients($account);

    return $account;
}

it('issues a guest token to the signed-in client who owns the booking', function () {
    Sanctum::actingAs(verifiedCabinetAccount('guest-owner@example.com'));

    $response = $this->postJson("/api/client/cabinet/bookings/{$this->booking->id}/access")
        ->assertOk()
        ->assertJsonPath('data.booking_code', 'GUESTSEC1');

    $this->withToken($response->json('data.manage_token'))
        ->getJson('/api/public/bookings/GUESTSEC1')
        ->assertOk();
});

it('does not issue a cabinet guest token for another client booking', function () {
    Sanctum::actingAs(verifiedCabinetAccount('someone-else@example.com'));

    $this->postJson("/api/client/cabinet/bookings/{$this->booking->id}/access")
        ->assertNotFound();

    expect($this->booking->fresh()->guest_access_token_hash)->toBeNull();
});

it('does not issue a cabinet guest token before the client email is verified', function () {
    $account = ClientAccount::query()->create([
        'name' => 'Unverified Client',
        'email' => 'guest-owner@example.com',
        'phone' => '+37498111112',
        'password' => 'A-client-password-123',
        'email_verified_at' => null,
    ]);
    Sanctum::actingAs($account);

    $this->postJson("/api/client/cabinet/bookings/{$this->booking->id}/access")
        ->assertNotFound();
});

it('does not issue a cabinet guest token for an unverified booking', function () {
    $this->booking->update(['phone_verified_at' => null, 'status' => 'pending']);
    Sanctum::actingAs(verifiedCabinetAccount('guest-owner@example.com'));

    $this->postJson("/api/client/cabinet/bookings/{$this->booking->id}/access")
        ->assertStatus(422);
});

it('requires a signed-in client for the cabinet access endpoint', function () {
    $this->postJson("/api/client/cabinet/bookings/{$this->booking->id}/access")
        ->assertUnauthorized();
});
