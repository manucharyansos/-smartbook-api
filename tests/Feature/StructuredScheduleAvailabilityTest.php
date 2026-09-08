<?php

use App\Models\Booking;
use App\Models\Business;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-31 08:00:00', 'UTC'));

    $this->business = Business::factory()->create([
        'timezone' => 'UTC',
        'work_start' => '08:00:00',
        'work_end' => '20:00:00',
        'slot_step_minutes' => 30,
    ]);
    $this->staff = User::factory()->staff($this->business->id)->create([
        'is_active' => true,
        'is_bookable' => true,
        'show_in_public_team' => true,
    ]);
    $this->service = Service::factory()->create([
        'business_id' => $this->business->id,
        'duration_minutes' => 60,
        'is_active' => true,
    ]);
    $this->client = Client::factory()->create(['business_id' => $this->business->id]);

    DB::table('business_working_hours')->insert([
        'business_id' => $this->business->id,
        'weekday' => 2,
        'is_closed' => false,
        'start' => '10:00',
        'end' => '16:00',
        'break_start' => '12:00',
        'break_end' => '13:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('staff_working_hours')->insert([
        'business_id' => $this->business->id,
        'user_id' => $this->staff->id,
        'weekday' => 2,
        'is_closed' => false,
        'start' => '11:00',
        'end' => '15:00',
        'break_start' => '13:30',
        'break_end' => '14:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

afterEach(fn () => Carbon::setTestNow());

it('returns only slots inside business and staff windows and outside breaks', function () {
    $slots = app(AvailabilityService::class)->slotsForDay(
        staffId: $this->staff->id,
        serviceId: $this->service->id,
        date: '2026-09-01',
        businessId: $this->business->id,
    );

    $starts = collect($slots)->pluck('starts_at')->values()->all();

    expect($starts)
        ->toContain('2026-09-01 11:00:00')
        ->toContain('2026-09-01 14:00:00')
        ->not->toContain('2026-09-01 10:00:00')
        ->not->toContain('2026-09-01 11:30:00')
        ->not->toContain('2026-09-01 12:00:00')
        ->not->toContain('2026-09-01 13:00:00')
        ->not->toContain('2026-09-01 13:30:00');
});

it('returns no slots when the business is closed for the weekday', function () {
    DB::table('business_working_hours')
        ->where('business_id', $this->business->id)
        ->where('weekday', 2)
        ->update(['is_closed' => true, 'start' => null, 'end' => null]);

    $slots = app(AvailabilityService::class)->slotsForDay(
        staffId: $this->staff->id,
        serviceId: $this->service->id,
        date: '2026-09-01',
        businessId: $this->business->id,
    );

    expect($slots)->toBe([]);
});

it('rejects a booking write that crosses a configured break', function () {
    expect(fn () => Booking::query()->create([
        'booking_code' => 'SCHD1234',
        'business_id' => $this->business->id,
        'service_id' => $this->service->id,
        'staff_id' => $this->staff->id,
        'client_id' => $this->client->id,
        'client_name' => 'Schedule Guard',
        'client_phone' => '+37499123456',
        'starts_at' => Carbon::parse('2026-09-01 11:30:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-09-01 12:30:00', 'UTC'),
        'status' => 'confirmed',
        'currency' => 'AMD',
    ]))->toThrow(ValidationException::class);
});

it('accepts a booking write fully contained in the effective schedule', function () {
    $booking = Booking::query()->create([
        'booking_code' => 'SCHD5678',
        'business_id' => $this->business->id,
        'service_id' => $this->service->id,
        'staff_id' => $this->staff->id,
        'client_id' => $this->client->id,
        'client_name' => 'Schedule Guard',
        'client_phone' => '+37499123456',
        'starts_at' => Carbon::parse('2026-09-01 14:00:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-09-01 15:00:00', 'UTC'),
        'status' => 'confirmed',
        'currency' => 'AMD',
    ]);

    expect($booking->exists)->toBeTrue();
});
