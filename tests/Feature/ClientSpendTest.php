<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

afterEach(fn () => Carbon::setTestNow());

it('counts spend and completed visits only for visits that took place', function () {
    Carbon::setTestNow(Carbon::parse('2030-03-04 12:00:00', 'UTC'));
    $business = Business::factory()->billable()->create(['timezone' => 'UTC']);
    $owner = User::factory()->owner($business->id)->create();
    $staff = User::factory()->staff($business->id)->create();
    $service = Service::factory()->create(['business_id' => $business->id]);
    $client = Client::factory()->create(['business_id' => $business->id]);
    $n = 0;
    $book = function (string $status, string $start, int $price) use ($business, $staff, $service, $client, &$n) {
        $n++;
        Booking::query()->create([
            'booking_code' => 'SPEND'.$n, 'business_id' => $business->id, 'service_id' => $service->id, 'staff_id' => $staff->id,
            'client_id' => $client->id, 'client_name' => 'Spend Client', 'client_phone' => '+37499123456', 'status' => $status,
            'starts_at' => Carbon::parse($start), 'ends_at' => Carbon::parse($start)->addHour(), 'final_price' => $price, 'currency' => 'AMD',
        ]);
    };
    $book('done', '2030-03-01 10:00', 1000);
    $book('confirmed', '2030-03-02 10:00', 2000);   // over, never marked done
    $book('confirmed', '2030-03-06 10:00', 4000);   // upcoming
    $book('no_show', '2030-03-01 12:00', 8000);
    $book('cancelled', '2030-03-01 14:00', 16000);
    $book('pending', '2030-03-01 16:00', 32000);

    Sanctum::actingAs($owner);
    $this->getJson('/api/clients/'.$client->id)->assertOk()
        ->assertJsonPath('data.total_spent', 3000)
        ->assertJsonPath('data.crm.completed_count', 2)
        ->assertJsonPath('data.crm.no_show_count', 1);
    $row = collect($this->getJson('/api/clients')->assertOk()->json('data'))->firstWhere('id', $client->id);
    expect((int) $row['total_spent'])->toBe(3000);
});
