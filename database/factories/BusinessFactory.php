<?php
// database/factories/BusinessFactory.php

namespace Database\Factories;

use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BusinessFactory extends Factory
{
    protected $model = Business::class;

    public function definition(): array
    {
        // Keep the factory independent from the configured Faker locale.
        // Some supported locales do not provide company/address formatters.
        $suffix = Str::upper(Str::random(8));
        $name = 'Test Business ' . $suffix;

        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(4),
            'business_type' => $this->faker->randomElement(['beauty', 'dental']),
            'phone' => '+37499' . $this->faker->numerify('######'),
            'address' => 'Test address ' . $suffix,
            'is_onboarding_completed' => $this->faker->boolean(80),
            'status' => 'active',
            'billing_status' => 'active',
            'suspended_at' => null,
            'work_start' => '09:00:00',
            'work_end' => '18:00:00',
            'slot_step_minutes' => 15,
            'timezone' => 'Asia/Yerevan',
        ];
    }

    // State for beauty business
    public function beauty(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $this->faker->randomElement(['Beauty Salon', 'Spa & Beauty', 'Nail Studio', 'Barber Shop']),
            'business_type' => 'beauty',
        ]);
    }

    // State for dental business
    public function dental(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $this->faker->randomElement(['Dental Clinic', 'Smile Center', 'Dentist Office', 'Orthodontics']),
            'business_type' => 'dental',
        ]);
    }

    // State for onboarding completed
    public function onboardingCompleted(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_onboarding_completed' => true,
        ]);
    }

    // State for suspended business
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
            'billing_status' => 'suspended',
            'suspended_at' => now(),
        ]);
    }
}
