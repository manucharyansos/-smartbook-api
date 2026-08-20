<?php

use App\Models\Admin;
use App\Models\AdminLog;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

function makePlatformAdmin(array $overrides = []): Admin
{
    return Admin::query()->create(array_merge([
        'name' => 'Vizit Admin',
        'email' => fake()->unique()->safeEmail(),
        'password' => Hash::make('A-secure-passphrase-123'),
        'role' => Admin::ROLE_SUPER_ADMIN,
        'is_active' => true,
        'email_verified_at' => now(),
    ], $overrides));
}

it('supports the complete admin login me and logout lifecycle', function () {
    $admin = makePlatformAdmin(['email' => 'owner@vizit.am']);

    $login = $this->postJson('/api/admin/login', [
        'email' => 'owner@vizit.am',
        'password' => 'A-secure-passphrase-123',
    ])->assertOk()
        ->assertJsonPath('data.admin.id', $admin->id)
        ->assertJsonPath('data.admin.role', Admin::ROLE_SUPER_ADMIN);

    $token = $login->json('data.token');

    $this->withToken($token)
        ->getJson('/api/admin/me')
        ->assertOk()
        ->assertJsonPath('data.admin.email', 'owner@vizit.am');

    $this->withToken($token)
        ->postJson('/api/admin/logout')
        ->assertOk()
        ->assertJsonPath('ok', true);

    $this->withToken($token)->getJson('/api/admin/me')->assertUnauthorized();
});

it('does not write an admin password hash into the audit log', function () {
    $actor = makePlatformAdmin();
    Sanctum::actingAs($actor);

    $this->postJson('/api/admin/admins', [
        'name' => 'Support Agent',
        'email' => 'support@vizit.am',
        'password' => 'Another-secure-pass-456',
        'password_confirmation' => 'Another-secure-pass-456',
        'role' => Admin::ROLE_SUPPORT,
        'is_active' => true,
    ])->assertCreated();

    $values = AdminLog::query()->where('action', 'create_admin')->sole()->new_values;

    expect($values)->not->toHaveKey('password');
});

it('prevents non privileged admins from changing business user status', function () {
    $actor = makePlatformAdmin(['role' => Admin::ROLE_SUPPORT]);
    $business = Business::factory()->create();
    $user = User::factory()->staff($business->id)->create();
    Sanctum::actingAs($actor);

    $this->patchJson("/api/admin/users/{$user->id}/toggle-active")
        ->assertForbidden();

    expect($user->fresh()->is_active)->toBeTrue();
});

it('prevents non super admins from opening super-admin-only platform data', function () {
    $actor = makePlatformAdmin(['role' => Admin::ROLE_SUPPORT]);
    $business = Business::factory()->create();
    Sanctum::actingAs($actor);

    $this->getJson('/api/admin/dashboard')->assertForbidden();
    $this->getJson("/api/admin/businesses/{$business->id}")->assertForbidden();
    $this->getJson('/api/admin/logs')->assertForbidden();
});

it('sets and clears the business user deactivation timestamp correctly', function () {
    $actor = makePlatformAdmin(['role' => Admin::ROLE_ADMIN]);
    $business = Business::factory()->create();
    $user = User::factory()->staff($business->id)->create(['is_active' => true]);
    Sanctum::actingAs($actor);

    $this->patchJson("/api/admin/users/{$user->id}/toggle-active")
        ->assertOk()
        ->assertJsonPath('is_active', false);

    expect($user->fresh()->deactivated_at)->not->toBeNull();

    $this->patchJson("/api/admin/users/{$user->id}/toggle-active")
        ->assertOk()
        ->assertJsonPath('is_active', true);

    expect($user->fresh()->deactivated_at)->toBeNull();
});

it('does not let the current super admin deactivate or delete itself', function () {
    $actor = makePlatformAdmin();
    Sanctum::actingAs($actor);

    $this->patchJson("/api/admin/admins/{$actor->id}/toggle-active")->assertUnprocessable();
    $this->deleteJson("/api/admin/admins/{$actor->id}")->assertUnprocessable();

    expect($actor->fresh()->is_active)->toBeTrue();
});

it('revokes an admin sessions when that admin is deactivated', function () {
    $actor = makePlatformAdmin();
    $target = makePlatformAdmin(['role' => Admin::ROLE_SUPPORT]);
    $target->createToken('admin-token');
    Sanctum::actingAs($actor);

    $this->patchJson("/api/admin/admins/{$target->id}/toggle-active")
        ->assertOk()
        ->assertJsonPath('is_active', false);

    expect($target->tokens()->count())->toBe(0);
});

it('suspends and restores a business without destroying user activation state', function () {
    $actor = makePlatformAdmin();
    $business = Business::factory()->create(['status' => 'active']);
    $user = User::factory()->staff($business->id)->create(['is_active' => true]);
    Sanctum::actingAs($actor);

    $this->postJson("/api/admin/businesses/{$business->id}/suspend")
        ->assertOk();

    expect($business->fresh()->status)->toBe('suspended')
        ->and($business->fresh()->suspended_at)->not->toBeNull()
        ->and($user->fresh()->is_active)->toBeTrue();

    $this->postJson("/api/admin/businesses/{$business->id}/restore")
        ->assertOk();

    expect($business->fresh()->status)->toBe('active')
        ->and($business->fresh()->suspended_at)->toBeNull()
        ->and($user->fresh()->is_active)->toBeTrue();
});
