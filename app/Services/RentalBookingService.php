<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingBlock;
use App\Models\Business;
use App\Models\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RentalBookingService
{
    public function assertBookable(Business $business): void
    {
        abort_unless($business->isRental(), 404);
        abort_unless($business->status === 'active' && $business->billing_status === 'active'
            && $business->subscription?->isActive(), 403, 'Տարածքի ամրագրումն այս պահին հասանելի չէ։');
        if ($business->rental_hourly_price === null) {
            throw ValidationException::withMessages(['starts_at' => 'Տարածքի վարձի գինը դեռ չի նշվել։']);
        }
    }

    public function validateDuration(Business $business, int $minutes): void
    {
        $step = max(5, min(60, (int) $business->slot_step_minutes));
        if ($minutes < $business->rental_min_minutes || $minutes > $business->rental_max_minutes || $minutes % $step !== 0) {
            throw ValidationException::withMessages(['duration_minutes' => 'Ընտրեք թույլատրելի տևողություն։']);
        }
    }

    public function price(Business $business, int $minutes): int
    {
        return (int) ceil(((int) $business->rental_hourly_price * $minutes) / 60);
    }

    public function reservations(Business $business)
    {
        return Booking::query()->where('business_id', $business->id)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->where(function ($q) {
                $q->where('status', '!=', 'pending')->orWhereNotNull('phone_verified_at')
                    ->orWhereNull('phone_verification_expires_at')
                    ->orWhere('phone_verification_expires_at', '>', now());
            });
    }

    public function assertAvailable(Business $business, Carbon $start, Carbon $end, ?int $ignoreId = null): void
    {
        $local = $start->copy()->timezone($business->effectiveTimezone());
        $schedule = app(ScheduleWindowService::class);
        $windows = array_merge($schedule->intervalsFor($business, $local->copy()->subDay()), $schedule->intervalsFor($business, $local));
        $withinHours = collect($windows)->contains(fn ($window) => $start->gte($window['start']) && $end->lte($window['end']));
        if ($start->lte(now()) || ! $withinHours) {
            throw ValidationException::withMessages(['starts_at' => 'Ընտրված ժամը հասանելի ժամանակացույցից դուրս է։']);
        }
        $step = max(5, min(60, (int) $business->slot_step_minutes));
        $localStart = $start->copy()->timezone($business->effectiveTimezone());
        if (($localStart->hour * 60 + $localStart->minute) % $step !== 0 || $localStart->second !== 0) {
            throw ValidationException::withMessages(['starts_at' => 'Ընտրեք օրացույցում առաջարկվող ժամը։']);
        }
        $buffer = (int) $business->rental_buffer_minutes;
        $from = $start->copy()->subMinutes($buffer);
        $to = $end->copy()->addMinutes($buffer);
        $busy = $this->reservations($business)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)->exists();
        $blocked = BookingBlock::query()->where('business_id', $business->id)->whereNull('staff_id')
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists();
        if ($busy || $blocked) {
            throw ValidationException::withMessages(['starts_at' => 'Այս ժամն արդեն զբաղված է։ Ընտրեք այլ ժամ։']);
        }
    }

    public function slots(Business $business, string $date, int $minutes, ?int $ignoreId = null): array
    {
        $this->assertBookable($business);
        if (! $ignoreId) {
            $this->validateDuration($business, $minutes);
        }
        $timezone = $business->effectiveTimezone();
        $day = Carbon::createFromFormat('!Y-m-d', $date, $timezone);
        if ($day->lt(now($timezone)->startOfDay()) || $day->gt(now($timezone)->addYear())) {
            return [];
        }
        $step = max(5, min(60, (int) $business->slot_step_minutes));
        $buffer = (int) $business->rental_buffer_minutes;
        $from = $day->copy()->subDay()->utc();
        $to = $day->copy()->addDays(2)->utc();
        $busy = $this->reservations($business)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)->get(['starts_at', 'ends_at']);
        $blocks = BookingBlock::query()->where('business_id', $business->id)->whereNull('staff_id')
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)->get(['starts_at', 'ends_at']);
        $slots = [];
        $schedule = app(ScheduleWindowService::class);
        $windows = array_merge($schedule->intervalsFor($business, $day->copy()->subDay()), $schedule->intervalsFor($business, $day));
        foreach ($windows as $window) {
            $start = $window['start']->copy()->max($day)->seconds(0);
            $remainder = ($start->hour * 60 + $start->minute) % $step;
            if ($remainder) {
                $start->addMinutes($step - $remainder);
            }
            for (; $start->lt($day->copy()->addDay()) && $start->copy()->addMinutes($minutes)->lte($window['end']); $start->addMinutes($step)) {
                if ($start->lte(now())) {
                    continue;
                }
                $end = $start->copy()->addMinutes($minutes);
                $overlap = $busy->contains(fn ($booking) => $booking->starts_at->lt($end->copy()->addMinutes($buffer))
                    && $booking->ends_at->gt($start->copy()->subMinutes($buffer)));
                $blocked = $blocks->contains(fn ($block) => $block->starts_at->lt($end) && $block->ends_at->gt($start));
                if (! $overlap && ! $blocked) {
                    $slots[] = [
                        'starts_at' => $start->format('Y-m-d H:i'), 'ends_at' => $end->format('Y-m-d H:i'),
                        'price' => $this->price($business, $minutes), 'currency' => 'AMD',
                    ];
                }
            }
        }

        return $slots;
    }

    /** Every writer locks the business row before checking intervals. */
    public function create(Business $business, array $data, array $extra = []): Booking
    {
        return DB::transaction(function () use ($business, $data, $extra) {
            $business = Business::query()->lockForUpdate()->findOrFail($business->id);
            $this->assertBookable($business);
            $minutes = (int) $data['duration_minutes'];
            $this->validateDuration($business, $minutes);
            $start = Carbon::createFromFormat('!Y-m-d H:i', $data['starts_at'], $business->effectiveTimezone())->utc();
            $end = $start->copy()->addMinutes($minutes);
            if ($start->gt(now()->addYear())) {
                throw ValidationException::withMessages(['starts_at' => 'Ամրագրումը հասանելի է մինչև մեկ տարի առաջ։']);
            }
            $this->assertAvailable($business, $start, $end);
            $client = Client::query()->firstOrCreate([
                'business_id' => $business->id, 'phone' => $data['client_phone'],
            ], ['name' => $data['client_name'], 'email' => Booking::normalizeContactEmail($data['client_email'] ?? null)]);
            app(ClientIdentityLinker::class)->linkClientProfile($client);

            return Booking::query()->create([
                'business_id' => $business->id, 'location_id' => $business->locations()->where('is_primary', true)->value('id'),
                'client_id' => $client->id, 'client_name' => $data['client_name'], 'client_phone' => $data['client_phone'],
                'client_email' => Booking::normalizeContactEmail($data['client_email'] ?? null),
                'service_id' => null, 'staff_id' => null, 'room_id' => null,
                'starts_at' => $start, 'ends_at' => $end, 'status' => 'confirmed',
                'final_price' => $this->price($business, $minutes), 'currency' => 'AMD',
                'booking_code' => strtoupper(Str::random(10)), 'notes' => $data['notes'] ?? null,
                'source' => 'website', 'source_meta' => ['rental_hourly_price' => $business->rental_hourly_price],
                ...$extra,
            ]);
        }, 3);
    }

    public function move(Business $business, Booking $booking, string $startsAt): Booking
    {
        return DB::transaction(function () use ($business, $booking, $startsAt) {
            $business = Business::query()->lockForUpdate()->findOrFail($business->id);
            $this->assertBookable($business);
            $booking = Booking::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($booking->id);
            abort_unless(in_array($booking->status, ['pending', 'confirmed'], true), 422, 'Այս ամրագրումը չի կարող տեղափոխվել։');
            $duration = (int) $booking->starts_at->diffInMinutes($booking->ends_at);
            $start = Carbon::createFromFormat('!Y-m-d H:i', $startsAt, $business->effectiveTimezone())->utc();
            abort_if($start->gt(now()->addYear()), 422, 'Ամրագրումը հասանելի է մինչև մեկ տարի առաջ։');
            $end = $start->copy()->addMinutes($duration);
            $this->assertAvailable($business, $start, $end, $booking->id);
            $booking->update(['starts_at' => $start, 'ends_at' => $end]);

            return $booking->refresh();
        }, 3);
    }
}
