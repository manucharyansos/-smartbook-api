<?php

use App\Models\Business;
use App\Models\ClientAccount;
use App\Models\MobileDevice;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

it('registers a client device with a server-derived client audience', function () {
    $client = ClientAccount::query()->create([
        'name' => 'Mobile Client',
        'email' => 'mobile-client@example.com',
        'password' => Hash::make('password'),
    ]);
    Sanctum::actingAs($client);

    $this->postJson('/api/mobile/devices', [
        'expo_push_token' => 'ExponentPushToken[client-device-token]',
        'platform' => 'ios',
        'locale' => 'hy',
        'timezone' => 'Asia/Yerevan',
    ])->assertCreated()->assertJsonPath('data.audience', 'client');

    $device = MobileDevice::query()->firstOrFail();
    expect($device->owner)->toBeInstanceOf(ClientAccount::class)
        ->and($device->owner_id)->toBe($client->id);
});

it('registers and revokes a business device without trusting a submitted audience', function () {
    $business = Business::factory()->create();
    $owner = User::factory()->owner($business->id)->create();
    Sanctum::actingAs($owner);

    $token = 'ExponentPushToken[business-device-token]';
    $this->postJson('/api/mobile/devices', [
        'expo_push_token' => $token,
        'platform' => 'android',
        'audience' => 'client',
    ])->assertCreated()->assertJsonPath('data.audience', 'business');

    $this->deleteJson('/api/mobile/devices/current', [
        'expo_push_token' => $token,
    ])->assertOk()->assertJsonPath('ok', true);

    $this->assertDatabaseMissing('mobile_devices', ['expo_push_token' => $token]);
});

it('does not allow an unauthenticated device registration', function () {
    $this->postJson('/api/mobile/devices', [
        'expo_push_token' => 'ExponentPushToken[anonymous-device-token]',
        'platform' => 'ios',
    ])->assertUnauthorized();
});
