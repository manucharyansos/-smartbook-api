<?php

use App\Jobs\SendExpoPushNotification;
use App\Models\Business;
use App\Models\MobileDevice;
use App\Models\User;
use Illuminate\Support\Facades\Http;

it('sends only non-sensitive booking data to registered Expo devices', function () {
    Http::fake(['https://exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);
    $business = Business::factory()->create();
    $owner = User::factory()->owner($business->id)->create();
    MobileDevice::query()->create([
        'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id, 'audience' => 'business',
        'expo_push_token' => 'ExponentPushToken[push-test-device]', 'platform' => 'android',
    ]);

    (new SendExpoPushNotification($owner->getMorphClass(), $owner->id, [
        'title' => 'New booking', 'body' => 'Booking created',
        'data' => ['type' => 'booking.created', 'audience' => 'business', 'booking_id' => 10, 'booking_code' => 'ABC123'],
    ]))->handle();

    Http::assertSent(fn ($request) => $request[0]['to'] === 'ExponentPushToken[push-test-device]'
        && $request[0]['data']['booking_id'] === 10
        && !isset($request[0]['data']['token']) && !isset($request[0]['data']['otp']));
});

it('disables a token rejected as DeviceNotRegistered', function () {
    Http::fake(['https://exp.host/*' => Http::response(['data' => [['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]]])]);
    $business = Business::factory()->create();
    $owner = User::factory()->owner($business->id)->create();
    $device = MobileDevice::query()->create([
        'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id, 'audience' => 'business',
        'expo_push_token' => 'ExponentPushToken[expired-device]', 'platform' => 'ios',
    ]);

    (new SendExpoPushNotification($owner->getMorphClass(), $owner->id, [
        'title' => 'Vizit', 'body' => 'Update', 'data' => ['type' => 'booking.rescheduled'],
    ]))->handle();

    expect($device->fresh()->disabled_at)->not->toBeNull();
});
