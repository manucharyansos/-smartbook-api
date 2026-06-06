<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // Ստեղծել Super Admin
        Admin::updateOrCreate(
            ['email' => 'super@beautybook.am'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'role' => 'super_admin',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // Ստեղծել Regular Admin
        Admin::updateOrCreate(
            ['email' => 'admin@beautybook.am'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // Ստեղծել Support Admin
        Admin::updateOrCreate(
            ['email' => 'support@beautybook.am'],
            [
                'name' => 'Support Agent',
                'password' => Hash::make('password'),
                'role' => 'support',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // Ստեղծել Finance Admin
        Admin::updateOrCreate(
            ['email' => 'finance@beautybook.am'],
            [
                'name' => 'Finance Manager',
                'password' => Hash::make('password'),
                'role' => 'finance',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('✅ Admin users created successfully!');
        $this->command->info('📧 super@beautybook.am / password');
        $this->command->info('📧 admin@beautybook.am / password');
        $this->command->info('📧 support@beautybook.am / password');
        $this->command->info('📧 finance@beautybook.am / password');
    }
}
