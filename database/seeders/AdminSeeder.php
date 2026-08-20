<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $name = trim((string) config('admin.seed.name', 'Vizit Super Admin'));
        $email = mb_strtolower(trim((string) config('admin.seed.email')));
        $password = (string) config('admin.seed.password');
        $rotatePassword = (bool) config('admin.seed.rotate_password', false);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('ADMIN_SEED_EMAIL must contain a valid email address.');
        }

        $admin = Admin::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        $isNew = !$admin;
        $admin ??= new Admin();
        $previousRole = $admin->role;
        $wasActive = (bool) $admin->is_active;
        $usesLegacyDefaultPassword = $admin->exists
            && is_string($admin->password)
            && Hash::check('password', $admin->password);
        $mustSetPassword = $isNew || $rotatePassword || $usesLegacyDefaultPassword;

        if ($mustSetPassword && ($password === '' || mb_strlen($password) < 12)) {
            throw new \RuntimeException(
                'ADMIN_SEED_PASSWORD must contain at least 12 characters when creating or rotating the admin password.'
            );
        }

        $admin->name = $name !== '' ? $name : 'Vizit Super Admin';
        $admin->email = $email;
        $admin->role = Admin::ROLE_SUPER_ADMIN;
        $admin->is_active = true;
        $admin->email_verified_at ??= now();

        if ($mustSetPassword) {
            $admin->password = Hash::make($password);
        }

        $admin->save();
        if ($mustSetPassword || (!$isNew && ($previousRole !== Admin::ROLE_SUPER_ADMIN || !$wasActive))) {
            $admin->tokens()->delete();
        }

        // Disable the known development accounts created by the legacy
        // seeder. Keeping them active with a shared default password would be
        // a production backdoor. The configured account itself is excluded so
        // an intentionally reused email can be secured instead.
        $legacyAdmins = Admin::query()
            ->whereIn('email', [
                'super@beautybook.am',
                'admin@beautybook.am',
                'support@beautybook.am',
                'finance@beautybook.am',
            ])
            ->where('id', '!=', $admin->getKey())
            ->get();

        foreach ($legacyAdmins as $legacyAdmin) {
            $legacyAdmin->forceFill([
                'is_active' => false,
                'password' => Hash::make(Str::random(64)),
            ])->save();
            $legacyAdmin->tokens()->delete();
        }

        $legacyDemoUserCount = 0;
        if (app()->environment('production')) {
            $legacyDemoEmails = collect(['starter_salon', 'pro_salon', 'pro_clinic', 'biz_clinic'])
                ->flatMap(fn (string $prefix) => collect(['owner', 'manager', 'staff1', 'staff2'])
                    ->map(fn (string $role) => "{$prefix}.{$role}@mail.com"))
                ->all();

            $legacyDemoUsers = User::query()->whereIn('email', $legacyDemoEmails)->get();
            foreach ($legacyDemoUsers as $legacyDemoUser) {
                $legacyDemoUser->forceFill([
                    'is_active' => false,
                    'deactivated_at' => now(),
                    'password' => Hash::make(Str::random(64)),
                ])->save();
                $legacyDemoUser->tokens()->delete();
            }
            $legacyDemoUserCount = $legacyDemoUsers->count();
        }

        $action = $isNew ? 'created' : 'updated';
        $passwordStatus = $mustSetPassword ? 'password set' : 'existing password preserved';
        $this->command?->info(
            "Vizit super admin {$action}; {$passwordStatus}; {$legacyAdmins->count()} legacy admin(s) and {$legacyDemoUserCount} production demo user(s) disabled."
        );
    }
}
