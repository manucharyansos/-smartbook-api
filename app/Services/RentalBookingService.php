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
    public const MAX_MINUTES = 30 * 24 * 60;

    public const MAX_PRICE = 4294967295;

    public const MAX_DISCOUNT_TIERS = 10;

    public const MAX_DISCOUNT_PERCENT = 90;

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
        if ($minutes < $business->rental_min_minutes || $minutes > min(self::MAX_MINUTES, $business->rental_max_minutes) || $minutes % $step !== 0) {
            throw ValidationException::withMessages(['duration_minutes' => 'Ընտրեք թույլատրելի տևողություն։']);
        }
        if ($this->grossPrice($business, $minutes) > self::MAX_PRICE) {
            throw ValidationException::withMessages(['duration_minutes' => 'Ընտրված տևողության ընդհանուր գինը գերազանցում է թույլատրելի սահմանը։']);
        }
    }

    public function grossPrice(Business $business, int $minutes): int
    {
        return (int) ceil(((int) $business->rental_hourly_price * $minutes) / 60);
    }

    /** The price a renter pays, after the owner's duration discount. */
    public function price(Business $business, int $minutes): int
    {
        return $this->quote($business, $minutes)['price'];
    }

    /** Valid tiers, longest first. Invalid stored rows are ignored, never applied. */
    public function durationDiscounts(Business $business): array
    {
        $tiers = collect(is_array($business->rental_duration_discounts) ? $business->rental_duration_discounts : [])
            ->filter(fn ($tier) => is_array($tier) && is_numeric($tier['min_minutes'] ?? null) && is_numeric($tier['percent'] ?? null))
            ->map(fn ($tier) => ['min_minutes' => (int) $tier['min_minutes'], 'percent' => (int) $tier['percent']])
            ->filter(fn ($tier) => $tier['min_minutes'] > 0 && $tier['percent'] >= 1 && $tier['percent'] <= self::MAX_DISCOUNT_PERCENT)
            ->unique('min_minutes')
            ->sortBy('min_minutes')
            ->values()
            ->all();

        return array_slice($tiers, 0, self::MAX_DISCOUNT_TIERS);
    }

    public function discountPercent(Business $business, int $minutes): int
    {
        $percent = 0;
        foreach ($this->durationDiscounts($business) as $tier) {
            if ($minutes >= $tier['min_minutes']) {
                $percent = $tier['percent'];
            }
        }

        return $percent;
    }

    /** @return array{gross_price: int, discount_percent: int, discount_amount: int, price: int} */
    public function quote(Business $business, int $minutes): array
    {
        $gross = $this->grossPrice($business, $minutes);
        $percent = $this->discountPercent($business, $minutes);
        // Round the discount down so the renter is never charged a fraction more than shown.
        $discount = (int) floor($gross * $percent / 100);

        return ['gross_price' => $gross, 'discount_percent' => $percent, 'discount_amount' => $discount, 'price' => $gross - $discount];
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
        $windows = $this->scheduleWindows($business, $start, $end);
        if ($start->lte(now()) || ! $this->fitsSchedule($business, $start, $end, $windows)) {
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
        // A long rental can collide with a reservation several days after arrival.
        $from = $day->copy()->subMinutes($buffer)->utc();
        $to = $day->copy()->addDay()->addMinutes($minutes + $buffer)->utc();
        $busy = $this->reservations($business)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)->get(['starts_at', 'ends_at']);
        $blocks = BookingBlock::query()->where('business_id', $business->id)->whereNull('staff_id')
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)->get(['starts_at', 'ends_at']);
        $slots = [];
        $quote = $this->quote($business, $minutes);
        $windows = $this->scheduleWindows($business, $day, $day->copy()->addDay()->addMinutes($minutes));
        foreach ($windows as $window) {
            $start = $window['start']->copy()->max($day)->seconds(0);
            $remainder = ($start->hour * 60 + $start->minute) % $step;
            if ($remainder) {
                $start->addMinutes($step - $remainder);
            }
            for (; $start->lt($day->copy()->addDay()) && $start->lt($window['end']); $start->addMinutes($step)) {
                if ($start->lte(now())) {
                    continue;
                }
                $end = $start->copy()->addMinutes($minutes);
                if (! $this->fitsSchedule($business, $start, $end, $windows)) {
                    continue;
                }
                $overlap = $busy->contains(fn ($booking) => $booking->starts_at->lt($end->copy()->addMinutes($buffer))
                    && $booking->ends_at->gt($start->copy()->subMinutes($buffer)));
                $blocked = $blocks->contains(fn ($block) => $block->starts_at->lt($end) && $block->ends_at->gt($start));
                if (! $overlap && ! $blocked) {
                    $slots[$start->format('Y-m-d H:i')] = [
                        'starts_at' => $start->format('Y-m-d H:i'), 'ends_at' => $end->format('Y-m-d H:i'),
                        ...$quote, 'currency' => 'AMD',
                    ];
                }
            }
        }

        ksort($slots);

        return array_values($slots);
    }

    /** Merge touching windows so an uninterrupted 24-hour schedule supports several days. */
    private function scheduleWindows(Business $business, Carbon $from, Carbon $to): array
    {
        $timezone = $business->effectiveTimezone();
        $lastDay = $to->copy()->timezone($timezone)->startOfDay();
        $schedule = app(ScheduleWindowService::class);
        $windows = [];
        for ($day = $from->copy()->timezone($timezone)->startOfDay()->subDay(); $day->lte($lastDay); $day->addDay()) {
            foreach ($schedule->intervalsFor($business, $day) as $window) {
                $windows[] = $window;
            }
        }
        usort($windows, fn ($left, $right) => $left['start']->getTimestamp() <=> $right['start']->getTimestamp());
        $merged = [];
        foreach ($windows as $window) {
            $last = count($merged) - 1;
            if ($last >= 0 && $window['start']->lte($merged[$last]['end'])) {
                $merged[$last]['end'] = $merged[$last]['end']->copy()->max($window['end']);
            } else {
                $merged[] = $window;
            }
        }

        return $merged;
    }

    private function fitsSchedule(Business $business, Carbon $start, Carbon $end, array $windows): bool
    {
        if ($end->lte($start)) {
            return false;
        }
        if (collect($windows)->contains(fn ($window) => $start->gte($window['start']) && $end->lte($window['end']))) {
            return true;
        }
        $timezone = $business->effectiveTimezone();
        if (! $business->rental_allow_overnight || $start->copy()->timezone($timezone)->isSameDay($end->copy()->timezone($timezone))) {
            return false;
        }

        // Overnight rentals retain the space during closed hours too. Only handover
        // times must be open; bookings and explicit blocks still cover the full stay.
        return collect($windows)->contains(fn ($window) => $start->gte($window['start']) && $start->lt($window['end']))
            && collect($windows)->contains(fn ($window) => $end->gt($window['start']) && $end->lte($window['end']));
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
            $quote = $this->quote($business, $minutes);
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
                'final_price' => $quote['price'], 'currency' => 'AMD',
                'booking_code' => strtoupper(Str::random(10)), 'notes' => $data['notes'] ?? null,
                'source' => 'website', 'source_meta' => ['rental_hourly_price' => $business->rental_hourly_price,
                    'gross_amount' => $quote['gross_price'], 'rental_discount_percent' => $quote['discount_percent'],
                    'rental_discount_amount' => $quote['discount_amount']],
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
