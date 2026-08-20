<?php

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

it('sends a password reset link to the Vizit frontend', function () {
    config(['app.frontend_url' => 'https://vizit.am']);
    Notification::fake();

    $user = User::factory()->create(['email' => 'owner@example.com']);

    $this->postJson('/api/auth/forgot-password', ['email' => 'OWNER@example.com'])
        ->assertOk()
        ->assertJson([
            'ok' => true,
            'message' => 'If the account exists, a password reset link has been sent.',
        ]);

    Notification::assertSentTo(
        $user,
        ResetPasswordNotification::class,
        function (ResetPasswordNotification $notification) use ($user) {
            $url = (string) $notification->toMail($user)->actionUrl;
            $parts = parse_url($url);
            parse_str($parts['query'] ?? '', $query);

            return ($parts['scheme'] ?? null) === 'https'
                && ($parts['host'] ?? null) === 'vizit.am'
                && ($parts['path'] ?? null) === '/reset-password'
                && ($query['email'] ?? null) === $user->email
                && !empty($query['token']);
        }
    );
});

it('does not reveal whether a password reset email exists', function () {
    Notification::fake();

    $this->postJson('/api/auth/forgot-password', ['email' => 'missing@example.com'])
        ->assertOk()
        ->assertExactJson([
            'ok' => true,
            'message' => 'If the account exists, a password reset link has been sent.',
        ]);

    Notification::assertNothingSent();
});

it('resets the password through the emailed token with a case-insensitive email', function () {
    Notification::fake();
    $user = User::factory()->create([
        'email' => 'owner@example.com',
        'password' => Hash::make('old-password'),
    ]);

    $this->postJson('/api/auth/forgot-password', ['email' => 'OWNER@EXAMPLE.COM'])
        ->assertOk();

    $token = null;
    Notification::assertSentTo(
        $user,
        ResetPasswordNotification::class,
        function (ResetPasswordNotification $notification) use (&$token) {
            $token = $notification->token;
            return true;
        }
    );

    $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => 'OWNER@EXAMPLE.COM',
        'password' => 'new-secure-password',
        'password_confirmation' => 'new-secure-password',
    ])->assertOk();

    expect(Hash::check('new-secure-password', $user->fresh()->password))->toBeTrue();
});

it('uses an isolated reset flow for client accounts', function () {
    config(['app.frontend_url' => 'https://vizit.am']);
    Notification::fake();
    $account = ClientAccount::query()->create([
        'name' => 'Client',
        'email' => 'client@example.com',
        'password' => Hash::make('old-client-password'),
    ]);

    $this->postJson('/api/client/auth/forgot-password', ['email' => 'CLIENT@EXAMPLE.COM'])
        ->assertOk();

    $token = null;
    Notification::assertSentTo(
        $account,
        ResetPasswordNotification::class,
        function (ResetPasswordNotification $notification) use ($account, &$token) {
            $token = $notification->token;
            $url = (string) $notification->toMail($account)->actionUrl;
            $parts = parse_url($url);
            parse_str($parts['query'] ?? '', $query);

            return ($parts['path'] ?? null) === '/reset-password'
                && ($query['audience'] ?? null) === 'client'
                && ($query['email'] ?? null) === $account->email;
        }
    );

    $this->postJson('/api/client/auth/reset-password', [
        'token' => $token,
        'email' => 'CLIENT@EXAMPLE.COM',
        'password' => 'new-client-password',
        'password_confirmation' => 'new-client-password',
    ])->assertOk();

    expect(Hash::check('new-client-password', $account->fresh()->password))->toBeTrue();
    $this->assertDatabaseCount('client_password_reset_tokens', 0);
});
