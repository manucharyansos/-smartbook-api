<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Models\StaffWorkSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use App\Models\BookingBlock;

class BookingService
{
    public function makeBooking(
        User $actor,
        int $serviceId,
        int $staffId,
        string $dateTime,
        string $clientName,
        string $clientPhone,
        ?string $notes = null,
        string $status = 'confirmed'
    ): Booking {
        $service = Service::query()->findOrFail($serviceId);

        $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $dateTime);
        $endsAt = $startsAt->addMinutes((int)$service->duration_minutes);

        $this->assertStaffAllowed($actor, $staffId);
        $this->assertSameBusiness($actor, $service->business_id); // Փոխել salon_id-ից business_id

        $staff = User::query()->findOrFail($staffId);
        $this->assertSameBusiness($actor, $staff->business_id); // Փոխել salon_id-ից business_id

        $this->assertWithinSchedule($actor->business_id, $staffId, $startsAt, $endsAt); // Փոխել salon_id-ից business_id
        $this->assertNoOverlap($actor->business_id, $staffId, $startsAt, $endsAt); // Փոխել salon_id-ից business_id
        $this->assertNotBlocked($actor->business_id, $staffId, $startsAt, $endsAt);

        return Booking::create([
            'business_id' => $actor->business_id, // Ավելացնել business_id
            'service_id' => $service->id,
            'staff_id' => $staffId,
            'client_name' => $clientName,
            'client_phone' => $clientPhone,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => $status,
            'notes' => $notes,
        ]);
    }

    private function assertStaffAllowed(User $actor, int $staffId): void
    {
        if ($actor->role === User::ROLE_STAFF && $actor->id !== $staffId) {
            throw ValidationException::withMessages([
                'staff_id' => 'Staff cannot create booking for another staff member.',
            ]);
        }
    }

    private function assertSameBusiness(User $actor, ?int $entityBusinessId): void // Փոխել անունը
    {
        if ($actor->isSuperAdmin()) return;

        if (!$entityBusinessId || $entityBusinessId !== $actor->business_id) { // Փոխել salon_id-ից business_id
            throw ValidationException::withMessages([
                'business' => 'Invalid business context.', // Փոխել հաղորդագրությունը
            ]);
        }
    }

    private function assertWithinSchedule(int $businessId, int $staffId, CarbonImmutable $start, CarbonImmutable $end): void // Փոխել salonId-ից businessId
    {
        $dow = $start->dayOfWeek;

        $schedule = StaffWorkSchedule::query()
            ->where('business_id', $businessId) // Փոխել salon_id-ից business_id
            ->where('staff_id', $staffId)
            ->where('day_of_week', $dow)
            ->first();

        if (!$schedule) {
            throw ValidationException::withMessages([
                'starts_at' => 'Staff has no working schedule for this day.',
            ]);
        }

        $workStart = CarbonImmutable::parse($start->format('Y-m-d').' '.$schedule->starts_at);
        $workEnd   = CarbonImmutable::parse($start->format('Y-m-d').' '.$schedule->ends_at);

        if (!($start >= $workStart && $end <= $workEnd)) {
            throw ValidationException::withMessages([
                'starts_at' => 'Time is outside staff working hours.',
            ]);
        }
    }

    private function assertNoOverlap(int $businessId, int $staffId, CarbonImmutable $start, CarbonImmutable $end, ?int $ignoreBookingId = null): void // Փոխել salonId-ից businessId
    {
        $q = Booking::query()
            ->where('business_id', $businessId) // Փոխել salon_id-ից business_id
            ->where('staff_id', $staffId)
            ->whereNotIn('status', ['cancelled'])
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start);

        if ($ignoreBookingId) {
            $q->where('id', '!=', $ignoreBookingId);
        }

        if ($q->exists()) {
            throw ValidationException::withMessages([
                'starts_at' => 'This time slot is already booked.',
            ]);
        }
    }

    private function assertNotBlocked(
        int $businessId,
        int $staffId,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): void {
        $blocked = BookingBlock::query()
            ->where('business_id', $businessId)
            ->where(function ($q) use ($staffId) {
                // staff_id = null -> applies to all staff
                $q->whereNull('staff_id')->orWhere('staff_id', $staffId);
            })
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->exists();

        if ($blocked) {
            throw ValidationException::withMessages([
                'starts_at' => 'This time is blocked (break / day off).',
            ]);
        }
    }

    public function updateBooking(User $actor, Booking $booking, array $data): Booking
    {
        // tenant safety
        if (!$actor->isSuperAdmin() && $booking->business_id !== $actor->business_id) { // Փոխել salon_id-ից business_id
            throw ValidationException::withMessages(['booking' => 'Invalid booking context.']);
        }

        // staff can update only own booking
        if ($actor->role === User::ROLE_STAFF && $booking->staff_id !== $actor->id) {
            throw ValidationException::withMessages(['booking' => 'Forbidden.']);
        }

        $serviceId = isset($data['service_id']) ? (int)$data['service_id'] : (int)$booking->service_id;
        $staffId   = isset($data['staff_id']) ? (int)$data['staff_id'] : (int)$booking->staff_id;

        $this->assertStaffAllowed($actor, $staffId);

        $service = Service::query()->findOrFail($serviceId);
        $this->assertSameBusiness($actor, $service->business_id); // Փոխել salon_id-ից business_id

        $staff = User::query()->findOrFail($staffId);
        $this->assertSameBusiness($actor, $staff->business_id); // Փոխել salon_id-ից business_id

        $startsAt = isset($data['starts_at'])
            ? CarbonImmutable::createFromFormat('Y-m-d H:i', $data['starts_at'])
            : CarbonImmutable::parse($booking->starts_at);

        $endsAt = $startsAt->addMinutes((int)$service->duration_minutes);

        $this->assertWithinSchedule($actor->business_id, $staffId, $startsAt, $endsAt); // Փոխել salon_id-ից business_id
        $this->assertNoOverlap($actor->business_id, $staffId, $startsAt, $endsAt, $booking->id); // Փոխել salon_id-ից business_id

        $booking->fill([
            'service_id' => $service->id,
            'staff_id' => $staffId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        if (array_key_exists('client_name', $data)) {
            $booking->client_name = $data['client_name'];
        }
        if (array_key_exists('client_phone', $data)) {
            $booking->client_phone = $data['client_phone'];
        }
        if (array_key_exists('notes', $data)) {
            $booking->notes = $data['notes'];
        }
        if (array_key_exists('status', $data)) {
            $booking->status = $data['status'];
        }

        $booking->save();

        return $booking;
    }
}
