<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2030-03-04 06:00:00', 'UTC'));
    Mail::fake();
    Notification::fake();
    config(['services.telegram.enabled' => false]);
    $this->business = Business::factory()->billable()->create([
        'slug' => 'discount-salon', 'timezone' => 'UTC', 'is_onboarding_completed' => true, 'is_public' => true,
        'is_public_profile_enabled' => true, 'work_start' => '09:00:00', 'work_end' => '18:00:00',
        'service_discounts' => ['visit_tiers' => [['min_visits' => 3, 'percent' => 5], ['min_visits' => 5, 'percent' => 7]],
            'bundle_tiers' => [['min_services' => 2, 'percent' => 10]]],
    ]);
    $this->staff = User::factory()->staff($this->business->id)->create(['is_active' => true, 'is_bookable' => true]);
    $this->cut = Service::factory()->create(['business_id' => $this->business->id, 'price' => 10000, 'duration_minutes' => 30, 'is_active' => true]);
    $this->color = Service::factory()->create(['business_id' => $this->business->id, 'price' => 20000, 'duration_minutes' => 30, 'is_active' => true]);
});

afterEach(fn () => Carbon::setTestNow());

function discountPayload(array $changes = []): array
{
    return array_merge(['staff_id' => test()->staff->id, 'starts_at' => '2030-03-05 10:00', 'client_name' => 'Ani Client',
        'client_phone' => '+37499111222', 'client_email' => 'ani@example.com', 'legal_accepted' => true,
        'terms_version' => (string) config('legal.terms_version'), 'privacy_version' => (string) config('legal.privacy_version')], $changes);
}

function pastVisits(int $count, string $status = 'done'): Client
{
    $client = Client::query()->firstOrCreate(['business_id' => test()->business->id, 'phone' => '+37499111222'], ['name' => 'Ani Client', 'email' => 'ani@example.com']);
    for ($i = 1; $i <= $count; $i++) {
        Booking::factory()->create(['business_id' => test()->business->id, 'client_id' => $client->id, 'service_id' => test()->cut->id,
            'staff_id' => test()->staff->id, 'status' => $status, 'starts_at' => now()->subDays($i * 7), 'ends_at' => now()->subDays($i * 7)->addMinutes(30)]);
    }

    return $client;
}

it('gives the bundle discount for several services in one visit', function () {
    $this->postJson('/api/public/businesses/discount-salon/bookings/multi', discountPayload(['service_ids' => [$this->cut->id, $this->color->id]]))
        ->assertCreated()->assertJsonPath('data.discount.type', 'bundle')->assertJsonPath('data.discount.percent', 10)->assertJsonPath('data.discount.amount', 3000);
    $booking = Booking::where('status', 'pending')->sole();
    expect($booking->final_price)->toBe(27000)->and($booking->source_meta)->toMatchArray(['service_discount_percent' => 10, 'price_before_service_discount' => 30000]);
});

it('rewards returning clients by completed visits and uses the larger discount', function (int $visits, int $percent, string $type) {
    pastVisits($visits);
    $this->postJson('/api/public/businesses/discount-salon/bookings', discountPayload(['service_id' => $this->cut->id]))
        ->assertCreated()->assertJsonPath('data.discount.percent', $percent);
    expect(Booking::where('status', 'pending')->sole()->final_price)->toBe(10000 - intdiv(10000 * $percent, 100));
    $this->postJson('/api/public/businesses/discount-salon/bookings/multi', discountPayload(['service_ids' => [$this->cut->id, $this->color->id], 'starts_at' => '2030-03-05 12:00']))
        ->assertCreated()->assertJsonPath('data.discount.type', $type);
})->with([
    'two visits: no visit tier' => [2, 0, 'bundle'],
    'three visits: 5%' => [3, 5, 'bundle'],
    'five visits: 7%' => [5, 7, 'bundle'],
]);

it('does not count cancelled, no-show or unverified bookings as visits', function () {
    $client = pastVisits(3, 'cancelled');
    Booking::factory()->create(['business_id' => $this->business->id, 'client_id' => $client->id, 'service_id' => $this->cut->id, 'staff_id' => $this->staff->id,
        'status' => 'pending', 'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addMinutes(30)]);
    $this->postJson('/api/public/businesses/discount-salon/bookings', discountPayload(['service_id' => $this->cut->id]))
        ->assertCreated()->assertJsonPath('data.discount.percent', 0);
});

it('lets the owner set tiers, shows them publicly and shows a signed-in client their progress', function () {
    $owner = User::factory()->owner($this->business->id)->create();
    Sanctum::actingAs($owner);
    $this->putJson('/api/business/service-discounts', ['visit_tiers' => [['min_visits' => 4, 'percent' => 6]], 'bundle_tiers' => []])->assertOk()
        ->assertJsonPath('data.visit_tiers.0.min_visits', 4)->assertJsonPath('data.bundle_tiers', []);
    $this->putJson('/api/business/service-discounts', ['visit_tiers' => [['min_visits' => 4, 'percent' => 95]], 'bundle_tiers' => []])->assertUnprocessable();
    $this->putJson('/api/business/service-discounts', ['visit_tiers' => [], 'bundle_tiers' => [['min_services' => 1, 'percent' => 5]]])->assertUnprocessable();
    Sanctum::actingAs(User::factory()->staff($this->business->id)->create());
    $this->putJson('/api/business/service-discounts', ['visit_tiers' => [], 'bundle_tiers' => []])->assertForbidden();

    $this->getJson('/api/public/businesses/discount-salon')->assertOk()->assertJsonPath('service_discounts.visit_tiers.0.percent', 6);

    $account = ClientAccount::create(['name' => 'Ani', 'email' => 'ani@example.com', 'phone' => '+37499111222', 'password' => 'secret-pass-1', 'email_verified_at' => now()]);
    $client = pastVisits(2);
    $client->update(['client_account_id' => $account->id]);
    Sanctum::actingAs($account);
    $this->getJson('/api/client/discounts/discount-salon')->assertOk()->assertJsonPath('data.visits', 2)
        ->assertJsonPath('data.next.remaining', 2)->assertJsonPath('data.next.percent', 6)->assertJsonPath('data.current_percent', 0);
});

it('applies the bundle discount to each line of a multi-line visit and counts that visit once', function () {
    $payload = discountPayload(['lines' => [
        ['service_id' => $this->cut->id, 'staff_id' => $this->staff->id, 'starts_at' => '2030-03-05 10:00'],
        ['service_id' => $this->color->id, 'staff_id' => $this->staff->id, 'starts_at' => '2030-03-05 10:30'],
    ]]);
    unset($payload['staff_id'], $payload['starts_at']);
    $this->postJson('/api/public/businesses/discount-salon/bookings/lines', $payload)->assertCreated()
        ->assertJsonPath('data.discount.percent', 10)->assertJsonPath('data.discount.amount', 3000);
    expect(Booking::orderBy('id')->pluck('final_price')->all())->toBe([9000, 18000]);
    Booking::query()->update(['status' => 'done']);
    expect(app(\App\Services\ServiceDiscountService::class)->completedVisits($this->business, Client::firstOrFail()))->toBe(1);
});
