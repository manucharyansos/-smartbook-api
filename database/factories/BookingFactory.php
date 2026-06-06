<?php
// database/factories/BookingFactory.php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        $start = now()->addDays(rand(0, 10))->setTime(rand(9, 18), 0);
        $business = Business::factory()->create();
        $client = Client::factory()->create(['business_id' => $business->id]);

        return [
            'booking_code' => $this->faker->unique()->regexify('[A-Z0-9]{8}'),
            'business_id' => $business->id,
            'service_id' => Service::factory()->create(['business_id' => $business->id])->id,
            'staff_id' => User::factory()->staff($business->id)->create()->id,
            'client_id' => $client->id,  // ՄԻԱՅՆ client_id
            'starts_at' => $start,
            'ends_at' => (clone $start)->addMinutes(60),
            'status' => $this->faker->randomElement(['pending', 'confirmed', 'done', 'cancelled']),
            'notes' => $this->faker->optional()->text(),
            'final_price' => $this->faker->numberBetween(3000, 25000),
            'currency' => 'AMD',
            'room_id' => null,
            'clinical_notes' => null,
            'treatment_codes' => null,
            'is_emergency' => false,
        ];
    }

    // State for beauty bookings
    public function beauty(): static
    {
        return $this->state(fn (array $attributes) => [
            'room_id' => null,
            'clinical_notes' => null,
            'treatment_codes' => null,
            'is_emergency' => false,
        ]);
    }

    // State for dental bookings
    public function dental(): static
    {
        return $this->state(fn (array $attributes) => [
            'clinical_notes' => $this->faker->sentence(),
            'treatment_codes' => ['D0120', 'D0210', 'D1110'],
            'is_emergency' => $this->faker->boolean(10),
        ]);
    }
}
