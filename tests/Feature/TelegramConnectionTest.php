<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config([
        'services.telegram.enabled' => true,
        'services.telegram.bot_token' => 'test-bot-token',
        'services.telegram.bot_username' => 'vizit_test_bot',
        'services.telegram.webhook_secret' => 'test-webhook-secret',
        'services.telegram.link_ttl_minutes' => 15,
    ]);

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
    ]);
});

function telegramStartPayload(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    return (string) ($query['start'] ?? '');
}

it('connects a business user through a one-time Telegram deep link', function () {
    $business = Business::factory()->create([
        'is_onboarding_completed' => true,
        'status' => 'active',
        'billing_status' => 'active',
    ]);
    $owner = User::factory()->owner($business->id)->create();
    Sanctum::actingAs($owner);

    $connection = $this->postJson('/api/telegram/connection')
        ->assertOk()
        ->assertJsonPath('data.connected', false)
        ->json('data');

    expect($connection['url'])->toStartWith('https://t.me/vizit_test_bot?start=vizit_');
    $payload = telegramStartPayload($connection['url']);
    expect($payload)->toHaveLength(46);

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong-secret')
        ->postJson('/api/webhooks/telegram', [
            'message' => ['chat' => ['id' => 456789], 'text' => '/start ' . $payload],
        ])
        ->assertForbidden();

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret')
        ->postJson('/api/webhooks/telegram', [
            'message' => ['chat' => ['id' => 456789], 'text' => '/start ' . $payload],
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect($owner->fresh()->telegram_chat_id)->toBe('456789');
    $this->getJson('/api/telegram/connection')
        ->assertOk()
        ->assertJsonPath('data.connected', true);
});

it('connects a verified booking customer and exposes the connected state', function () {
    $business = Business::factory()->create(['timezone' => 'UTC']);
    $staff = User::factory()->staff($business->id)->create(['is_active' => true]);
    $service = Service::factory()->create(['business_id' => $business->id]);
    $client = Client::factory()->create(['business_id' => $business->id, 'telegram_chat_id' => null]);
    $guestToken = 'customer-guest-token';
    $booking = Booking::query()->create([
        'booking_code' => 'TELE1234',
        'business_id' => $business->id,
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'client_id' => $client->id,
        'client_name' => 'Telegram Customer',
        'client_phone' => '+37499123456',
        'client_email' => 'customer@example.com',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'status' => 'confirmed',
        'currency' => 'AMD',
        'phone_verified_at' => now(),
        'guest_access_token_hash' => Hash::make($guestToken),
        'guest_access_expires_at' => now()->addDays(7),
    ]);

    $connection = $this->withHeader('X-Guest-Token', $guestToken)
        ->postJson('/api/public/bookings/TELE1234/telegram-link')
        ->assertOk()
        ->assertJsonPath('data.connected', false)
        ->json('data');

    $payload = telegramStartPayload($connection['url']);
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret')
        ->postJson('/api/webhooks/telegram', [
            'message' => ['chat' => ['id' => -100123456], 'text' => '/start ' . $payload],
        ])
        ->assertOk();

    expect($client->fresh()->telegram_chat_id)->toBe('-100123456');
    $this->withHeader('X-Guest-Token', $guestToken)
        ->getJson('/api/public/bookings/' . $booking->booking_code)
        ->assertOk()
        ->assertJsonPath('data.telegram_connected', true);
});

it('does not expose the internal booking code in Telegram notifications', function () {
    $business = Business::factory()->create(['timezone' => 'UTC']);
    $staff = User::factory()->staff($business->id)->create(['is_active' => true]);
    $service = Service::factory()->create(['business_id' => $business->id]);
    $client = Client::factory()->create(['business_id' => $business->id]);
    $booking = Booking::query()->create([
        'booking_code' => 'HIDDEN88',
        'business_id' => $business->id,
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'client_id' => $client->id,
        'client_name' => 'Notification Customer',
        'client_phone' => '+37499123456',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'status' => 'confirmed',
        'currency' => 'AMD',
    ]);

    $telegram = app(TelegramService::class);
    expect($telegram->bookingConfirmedMessage($booking))->not->toContain('HIDDEN88');
    expect($telegram->bookingCancelledMessage($booking))->not->toContain('HIDDEN88');
    expect($telegram->staffBookingConfirmedMessage($booking, $staff))->not->toContain('HIDDEN88');
});
