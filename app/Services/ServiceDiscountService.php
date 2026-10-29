<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Client;
use Illuminate\Support\Collection;

/**
 * Owner-defined discounts for service businesses:
 *  - visit tiers: after N completed visits a returning client gets X% off;
 *  - bundle tiers: booking N or more services in one visit gives Y% off.
 * Only the larger of the two applies, before loyalty points and gift cards.
 */
class ServiceDiscountService
{
    public const MAX_TIERS = 10;

    public const MAX_PERCENT = 90;

    /** @return array{visit_tiers: array<int, array{min_visits: int, percent: int}>, bundle_tiers: array<int, array{min_services: int, percent: int}>} */
    public function tiers(Business $business): array
    {
        $raw = is_array($business->service_discounts) ? $business->service_discounts : [];

        return [
            'visit_tiers' => $this->clean($raw['visit_tiers'] ?? [], 'min_visits', 1, 1000),
            'bundle_tiers' => $this->clean($raw['bundle_tiers'] ?? [], 'min_services', 2, 20),
        ];
    }

    private function clean(mixed $rows, string $key, int $min, int $max): array
    {
        return collect(is_array($rows) ? $rows : [])
            ->filter(fn ($row) => is_array($row) && is_numeric($row[$key] ?? null) && is_numeric($row['percent'] ?? null))
            ->map(fn ($row) => [$key => (int) $row[$key], 'percent' => (int) $row['percent']])
            ->filter(fn ($row) => $row[$key] >= $min && $row[$key] <= $max && $row['percent'] >= 1 && $row['percent'] <= self::MAX_PERCENT)
            ->unique($key)->sortBy($key)->values()->take(self::MAX_TIERS)->all();
    }

    /** Completed visits: finished bookings, with a multi-service visit (shared group) counted once. */
    public function completedVisits(Business $business, ?Client $client): int
    {
        if (! $client) {
            return 0;
        }
        $done = Booking::query()->withoutGlobalScopes()->where('business_id', $business->id)->where('client_id', $client->id)
            ->where(function ($query) {
                $query->where('status', 'done')
                    ->orWhere(fn ($q) => $q->whereIn('status', ['confirmed', 'completed'])->where('ends_at', '<', now()));
            });
        $single = (clone $done)->where(fn ($q) => $q->whereNull('group_id')->orWhereNotNull('recurrence_id'))->count();
        $grouped = (clone $done)->whereNotNull('group_id')->whereNull('recurrence_id')->distinct()->count('group_id');

        return $single + $grouped;
    }

    /** @return array{percent: int, type: ?string, visits: int} */
    public function choose(Business $business, ?Client $client, int $serviceCount): array
    {
        $tiers = $this->tiers($business);
        $visits = $tiers['visit_tiers'] ? $this->completedVisits($business, $client) : 0;
        $visitPercent = $this->reached($tiers['visit_tiers'], 'min_visits', $visits);
        $bundlePercent = $this->reached($tiers['bundle_tiers'], 'min_services', $serviceCount);

        if ($visitPercent === 0 && $bundlePercent === 0) {
            return ['percent' => 0, 'type' => null, 'visits' => $visits];
        }

        return $visitPercent >= $bundlePercent
            ? ['percent' => $visitPercent, 'type' => 'visits', 'visits' => $visits]
            : ['percent' => $bundlePercent, 'type' => 'bundle', 'visits' => $visits];
    }

    private function reached(array $tiers, string $key, int $value): int
    {
        $percent = 0;
        foreach ($tiers as $tier) {
            if ($value >= $tier[$key]) {
                $percent = $tier['percent'];
            }
        }

        return $percent;
    }

    /**
     * Lowers each new booking's price by the chosen discount and records it.
     *
     * @param  iterable<Booking>  $bookings
     * @return array{percent: int, type: ?string, amount: int}
     */
    public function apply(Business $business, ?Client $client, iterable $bookings, int $serviceCount): array
    {
        $choice = $this->choose($business, $client, $serviceCount);
        $total = 0;
        if ($choice['percent'] > 0) {
            foreach ($bookings as $booking) {
                $gross = (int) ($booking->final_price ?? 0);
                if ($gross <= 0) {
                    continue;
                }
                // Rounded down so the client never pays a fraction more than shown.
                $amount = (int) floor($gross * $choice['percent'] / 100);
                $total += $amount;
                $booking->final_price = $gross - $amount;
                $booking->source_meta = array_merge((array) ($booking->source_meta ?? []), [
                    'service_discount_type' => $choice['type'], 'service_discount_percent' => $choice['percent'],
                    'service_discount_amount' => $amount, 'price_before_service_discount' => $gross,
                ]);
                $booking->save();
            }
        }

        return ['percent' => $choice['percent'], 'type' => $choice['type'], 'amount' => $total];
    }

    /** Where a client stands on the visit ladder of one business. */
    public function progress(Business $business, Collection $clients): array
    {
        $tiers = $this->tiers($business)['visit_tiers'];
        $visits = $clients->sum(fn (Client $client) => $this->completedVisits($business, $client));
        $current = $this->reached($tiers, 'min_visits', $visits);
        $next = collect($tiers)->first(fn ($tier) => $tier['min_visits'] > $visits);

        return [
            'visits' => $visits,
            'current_percent' => $current,
            'next' => $next ? ['min_visits' => $next['min_visits'], 'percent' => $next['percent'], 'remaining' => $next['min_visits'] - $visits] : null,
            'visit_tiers' => $tiers,
        ];
    }
}
