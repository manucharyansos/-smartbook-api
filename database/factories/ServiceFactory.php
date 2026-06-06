<?php
// database/factories/ServiceFactory.php

namespace Database\Factories;

use App\Models\Service;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        $beautyServices = ['Haircut', 'Coloring', 'Manicure', 'Pedicure', 'Makeup', 'Massage', 'Facial', 'Waxing'];
        $dentalServices = ['Cleaning', 'Filling', 'Extraction', 'Root Canal', 'Crown', 'Whitening', 'Checkup', 'X-Ray'];

        return [
            'business_id' => Business::factory(),
            'name' => $this->faker->randomElement(array_merge($beautyServices, $dentalServices)),
            'description' => $this->faker->optional()->sentence(),
            'duration_minutes' => $this->faker->randomElement([15, 30, 45, 60, 90, 120]),
            'price' => $this->faker->numberBetween(2000, 50000),
            'currency' => 'AMD',
            'is_active' => true,
        ];
    }

    // State for beauty services
    public function beauty(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $this->faker->randomElement(['Haircut', 'Coloring', 'Manicure', 'Pedicure', 'Makeup', 'Massage']),
        ]);
    }

    // State for dental services
    public function dental(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $this->faker->randomElement(['Cleaning', 'Filling', 'Extraction', 'Root Canal', 'Crown']),
        ]);
    }

    // State for active/inactive
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
