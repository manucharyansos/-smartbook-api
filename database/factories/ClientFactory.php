<?php
// database/factories/ClientFactory.php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => $this->faker->name(),
            'phone' => $this->faker->e164PhoneNumber(),
            'email' => $this->faker->optional()->safeEmail(),
            'notes' => $this->faker->optional()->text(),

            // Dental fields (nullable)
            'birth_date' => $this->faker->optional()->date(),
            'blood_type' => $this->faker->optional()->randomElement(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']),
            'medical_history' => $this->faker->optional()->sentence(),
            'allergies' => $this->faker->optional()->words(3, true),
            'emergency_contact_name' => $this->faker->optional()->name(),
            'emergency_contact_phone' => $this->faker->optional()->e164PhoneNumber(),
            'medical_notes' => $this->faker->optional()->sentence(),
        ];
    }

    // State for dental clients
    public function dental(): static
    {
        return $this->state(fn (array $attributes) => [
            'birth_date' => $this->faker->date(),
            'blood_type' => $this->faker->randomElement(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']),
            'medical_history' => $this->faker->sentence(),
            'allergies' => $this->faker->words(3, true),
            'emergency_contact_name' => $this->faker->name(),
            'emergency_contact_phone' => $this->faker->e164PhoneNumber(),
        ]);
    }
}
