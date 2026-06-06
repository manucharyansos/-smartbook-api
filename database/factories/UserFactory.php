<?php
// database/factories/UserFactory.php

namespace Database\Factories;

use App\Models\User;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => User::ROLE_STAFF,
            'business_id' => null,  // փոխել salon_id-ից business_id
            'is_active' => true,
            'deactivated_at' => null,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function owner($businessId = null): static
    {
        return $this->state(fn () => [
            'role' => User::ROLE_OWNER,
            'business_id' => $businessId,  // փոխել salon_id-ից business_id
        ]);
    }

    public function manager($businessId = null): static
    {
        return $this->state(fn () => [
            'role' => User::ROLE_MANAGER,
            'business_id' => $businessId,
        ]);
    }

    public function staff($businessId = null): static
    {
        return $this->state(fn () => [
            'role' => User::ROLE_STAFF,
            'business_id' => $businessId,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->state(fn () => [
            'role' => User::ROLE_SUPER_ADMIN,
            'business_id' => null,
        ]);
    }
}
