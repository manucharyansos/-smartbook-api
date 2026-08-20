<?php

use App\Models\Business;
use App\Models\Booking;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Service;
use App\Notifications\ClientVerifyEmail;
use App\Services\ClientIdentityLinker;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

function makeClientAccount(array $overrides = []): ClientAccount
{
    return ClientAccount::query()->create(array_merge([
        'name' => 'Client Account',
        'email' => fake()->unique()->safeEmail(),
        'phone' => '+37498111111',
        'password' => 'A-client-password-123',
        'email_verified_at' => null,
    ], $overrides));
}

it('sends verification and does not link profiles during password registration', function () {
    Notification::fake();
    $business = Business::factory()->create();
    $profile = Client::factory()->create([
        'business_id' => $business->id,
        'email' => 'client@example.com',
        'phone' => '+37498123456',
    ]);

    $this->postJson('/api/client/auth/register', [
        'name' => 'Client',
        'email' => 'client@example.com',
        'phone' => '+37498123456',
        'password' => 'A-client-password-123',
        'password_confirmation' => 'A-client-password-123',
    ])->assertOk()
        ->assertJsonPath('user.email_verified', false)
        ->assertJsonPath('user.requires_email_verification', true);

    $account = ClientAccount::query()->where('email', 'client@example.com')->sole();
    Notification::assertSentTo($account, ClientVerifyEmail::class);

    expect($profile->fresh()->client_account_id)->toBeNull();
});

it('sends email verification through the authenticated frontend confirmation page', function () {
    config()->set('app.frontend_url', 'https://vizit.am');
    $account = makeClientAccount(['email' => 'frontend-link@example.com']);

    $actionUrl = (new ClientVerifyEmail())->toMail($account)->actionUrl;

    expect($actionUrl)
        ->toStartWith("https://vizit.am/client/verify-email/{$account->id}/")
        ->and($actionUrl)->toContain('expires=')
        ->and($actionUrl)->toContain('signature=');
});

it('never exposes profiles linked only by an unverified identifier', function () {
    $business = Business::factory()->create();
    $profile = Client::factory()->create([
        'business_id' => $business->id,
        'email' => 'victim@example.com',
        'phone' => '+37498999999',
    ]);
    $account = makeClientAccount([
        'email' => 'victim@example.com',
        'phone' => '+37498999999',
    ]);

    expect(app(ClientIdentityLinker::class)->syncLinkedClients($account))->toBe(0)
        ->and($profile->fresh()->client_account_id)->toBeNull();

    Sanctum::actingAs($account);
    $this->getJson('/api/client/cabinet/bookings')
        ->assertOk()
        ->assertJsonPath('meta.linked_profiles', 0)
        ->assertJsonPath('meta.requires_email_verification', true);
});

it('links only profiles whose email matches the verified account email', function () {
    $businessA = Business::factory()->create();
    $businessB = Business::factory()->create();
    $matching = Client::factory()->create([
        'business_id' => $businessA->id,
        'email' => 'verified@example.com',
        'phone' => '+37498999999',
    ]);
    $phoneOnlyMatch = Client::factory()->create([
        'business_id' => $businessB->id,
        'email' => 'someone-else@example.com',
        'phone' => '+37498999999',
    ]);
    $account = makeClientAccount([
        'email' => 'verified@example.com',
        'phone' => '+37498999999',
        'email_verified_at' => now(),
    ]);

    expect(app(ClientIdentityLinker::class)->syncLinkedClients($account))->toBe(1)
        ->and($matching->fresh()->client_account_id)->toBe($account->id)
        ->and($phoneOnlyMatch->fresh()->client_account_id)->toBeNull();
});

it('verifies a signed email link and only then links matching profiles', function () {
    config()->set('app.frontend_url', 'https://vizit.am');
    $business = Business::factory()->create();
    $profile = Client::factory()->create([
        'business_id' => $business->id,
        'email' => 'verify@example.com',
    ]);
    $account = makeClientAccount(['email' => 'verify@example.com']);

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(30),
        ['id' => $account->id, 'hash' => sha1($account->getEmailForVerification())],
    );

    Sanctum::actingAs($account);
    $this->get($url)
        ->assertOk()
        ->assertJsonPath('user.email_verified', true);

    expect($account->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and($profile->fresh()->client_account_id)->toBe($account->id);
});

it('does not let a different signed-in account use another clients verification link', function () {
    $account = makeClientAccount(['email' => 'victim@example.com']);
    $otherAccount = makeClientAccount([
        'email' => 'other@example.com',
        'phone' => '+37498222222',
    ]);
    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(30),
        ['id' => $account->id, 'hash' => sha1($account->getEmailForVerification())],
    );

    Sanctum::actingAs($otherAccount);
    $this->get($url)->assertForbidden();

    expect($account->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('shows a verified client only their correctly matched bookings across businesses', function () {
    $businessA = Business::factory()->create();
    $businessB = Business::factory()->create();
    $profileA = Client::factory()->create([
        'business_id' => $businessA->id,
        'email' => 'owner-of-bookings@example.com',
    ]);
    $profileB = Client::factory()->create([
        'business_id' => $businessB->id,
        'email' => 'owner-of-bookings@example.com',
    ]);
    $account = makeClientAccount([
        'email' => 'owner-of-bookings@example.com',
        'email_verified_at' => now(),
    ]);
    app(ClientIdentityLinker::class)->syncLinkedClients($account);

    $serviceA = Service::factory()->create(['business_id' => $businessA->id]);
    $serviceB = Service::factory()->create(['business_id' => $businessB->id]);

    Booking::query()->create([
        'booking_code' => 'OWN-A-BOOKING',
        'business_id' => $businessA->id,
        'service_id' => $serviceA->id,
        'client_id' => $profileA->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'status' => 'confirmed',
    ]);
    Booking::query()->create([
        'booking_code' => 'OWN-B-BOOKING',
        'business_id' => $businessB->id,
        'service_id' => $serviceB->id,
        'client_id' => $profileB->id,
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHour(),
        'status' => 'confirmed',
    ]);
    Booking::query()->create([
        'booking_code' => 'MISMATCHED-BOOKING',
        'business_id' => $businessB->id,
        'service_id' => $serviceB->id,
        'client_id' => $profileA->id,
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHour(),
        'status' => 'confirmed',
    ]);

    Sanctum::actingAs($account);
    $this->getJson('/api/client/cabinet/bookings')
        ->assertOk()
        ->assertJsonFragment(['booking_code' => 'OWN-A-BOOKING'])
        ->assertJsonFragment(['booking_code' => 'OWN-B-BOOKING'])
        ->assertJsonMissing(['booking_code' => 'MISMATCHED-BOOKING'])
        ->assertJsonPath('meta.linked_profiles', 2);
});
