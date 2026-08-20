<?php

use App\Models\Admin;
use Database\Seeders\AdminSeeder;
use Illuminate\Support\Facades\Hash;

it('creates one configured Vizit super admin without hardcoded accounts', function () {
    config()->set('admin.seed', [
        'name' => 'Platform Owner',
        'email' => 'owner@vizit.am',
        'password' => 'A-secure-passphrase-123',
        'rotate_password' => false,
    ]);

    $this->seed(AdminSeeder::class);

    $admin = Admin::query()->sole();

    expect($admin->name)->toBe('Platform Owner')
        ->and($admin->email)->toBe('owner@vizit.am')
        ->and($admin->role)->toBe(Admin::ROLE_SUPER_ADMIN)
        ->and($admin->is_active)->toBeTrue()
        ->and(Hash::check('A-secure-passphrase-123', $admin->password))->toBeTrue();
});

it('preserves an existing admin password on repeat runs by default', function () {
    config()->set('admin.seed', [
        'name' => 'Platform Owner',
        'email' => 'owner@vizit.am',
        'password' => 'A-secure-passphrase-123',
        'rotate_password' => false,
    ]);
    $this->seed(AdminSeeder::class);

    config()->set('admin.seed.password', null);
    $this->seed(AdminSeeder::class);

    $admin = Admin::query()->where('email', 'owner@vizit.am')->sole();

    expect(Hash::check('A-secure-passphrase-123', $admin->password))->toBeTrue()
        ->and(Admin::query()->count())->toBe(1);
});

it('rotates the configured admin password only when explicitly enabled', function () {
    config()->set('admin.seed', [
        'name' => 'Platform Owner',
        'email' => 'owner@vizit.am',
        'password' => 'A-secure-passphrase-123',
        'rotate_password' => false,
    ]);
    $this->seed(AdminSeeder::class);

    config()->set('admin.seed.password', 'A-different-passphrase-456');
    config()->set('admin.seed.rotate_password', true);
    $this->seed(AdminSeeder::class);

    $admin = Admin::query()->where('email', 'owner@vizit.am')->sole();

    expect(Hash::check('A-secure-passphrase-123', $admin->password))->toBeFalse()
        ->and(Hash::check('A-different-passphrase-456', $admin->password))->toBeTrue();
});

it('refuses to seed weak or missing production credentials', function () {
    config()->set('admin.seed', [
        'name' => 'Platform Owner',
        'email' => 'owner@vizit.am',
        'password' => 'short',
        'rotate_password' => false,
    ]);

    expect(fn () => $this->seed(AdminSeeder::class))
        ->toThrow(RuntimeException::class, 'at least 12 characters');
});

it('disables legacy default-password admin accounts', function () {
    $legacy = Admin::query()->create([
        'name' => 'Legacy Admin',
        'email' => 'super@beautybook.am',
        'password' => Hash::make('password'),
        'role' => Admin::ROLE_SUPER_ADMIN,
        'is_active' => true,
    ]);
    $legacy->createToken('admin-token');

    config()->set('admin.seed', [
        'name' => 'Platform Owner',
        'email' => 'owner@vizit.am',
        'password' => 'A-secure-passphrase-123',
        'rotate_password' => false,
    ]);

    $this->seed(AdminSeeder::class);

    expect($legacy->fresh()->is_active)->toBeFalse()
        ->and(Hash::check('password', $legacy->fresh()->password))->toBeFalse()
        ->and($legacy->tokens()->count())->toBe(0);
});

it('forces rotation when the configured account still has the legacy default password', function () {
    $legacy = Admin::query()->create([
        'name' => 'Legacy Super Admin',
        'email' => 'super@beautybook.am',
        'password' => Hash::make('password'),
        'role' => Admin::ROLE_SUPER_ADMIN,
        'is_active' => true,
    ]);

    config()->set('admin.seed', [
        'name' => 'Platform Owner',
        'email' => 'super@beautybook.am',
        'password' => 'A-new-secure-passphrase-789',
        'rotate_password' => false,
    ]);

    $this->seed(AdminSeeder::class);

    expect($legacy->fresh()->is_active)->toBeTrue()
        ->and(Hash::check('password', $legacy->fresh()->password))->toBeFalse()
        ->and(Hash::check('A-new-secure-passphrase-789', $legacy->fresh()->password))->toBeTrue();
});
