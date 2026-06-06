<?php
// database/factories/RoomFactory.php

namespace Database\Factories;

use App\Models\Room;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoomFactory extends Factory
{
    protected $model = Room::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory()->dental(),
            'name' => $this->faker->randomElement(['Room 1', 'Room 2', 'Chair 1', 'Chair 2', 'Surgery', 'X-Ray Room']),
            'type' => $this->faker->randomElement(['room', 'chair', 'surgery']),
            'capacity' => $this->faker->numberBetween(1, 3),
            'equipment' => $this->faker->randomElements(['X-Ray', 'Surgical Light', 'Dental Chair', 'Monitor'], rand(1, 3)),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
