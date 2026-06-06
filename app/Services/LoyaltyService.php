<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Client;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyPointLedger;
use App\Models\User;
use Illuminate\Support\Carbon;

class LoyaltyService
{
    public function getOrCreateProgram(int $businessId): LoyaltyProgram
    {
        return LoyaltyProgram::query()->firstOrCreate(
            ['business_id' => $businessId],
            [
                'is_enabled' => false,
                'currency_unit' => 1000,
                'points_per_currency_unit' => 1,
                'redeem_points_step' => 10,
                'redeem_currency_amount' => 100,
                'max_redeem_percent' => 50,
                'allow_gift_card_with_points' => true,
                'points_expire_after_days' => 0,
                'min_booking_amount' => 0,
            ]
        );
    }

    public function getClientBalance(int $businessId, int $clientId): int
    {
        return (int) LoyaltyPointLedger::query()
            ->where('business_id', $businessId)
            ->where('client_id', $clientId)
            ->sum('delta_points');
    }

    public function computePoints(LoyaltyProgram $program, int $amount): int
    {
        if (!$program->is_enabled) return 0;
        if ($amount < (int) $program->min_booking_amount) return 0;

        $unit = max(1, (int) $program->currency_unit);
        $pp = max(0, (int) $program->points_per_currency_unit);
        if ($pp === 0) return 0;

        return intdiv($amount, $unit) * $pp;
    }

    public function previewRedemption(LoyaltyProgram $program, int $balance, int $grossAmount, int $requestedPoints): array
    {
        if (!$program->is_enabled || $grossAmount <= 0 || $requestedPoints <= 0 || $balance <= 0) {
            return ['applied_points' => 0, 'discount_amount' => 0];
        }

        $step = max(1, (int) ($program->redeem_points_step ?: 10));
        $value = max(1, (int) ($program->redeem_currency_amount ?: 100));
        $maxPercent = max(1, min(100, (int) ($program->max_redeem_percent ?: 50)));
        $maxDiscount = (int) floor($grossAmount * ($maxPercent / 100));

        $usable = min($balance, $requestedPoints);
        $normalizedPoints = intdiv($usable, $step) * $step;
        if ($normalizedPoints <= 0) {
            return ['applied_points' => 0, 'discount_amount' => 0];
        }

        $discountAmount = intdiv($normalizedPoints, $step) * $value;
        $discountAmount = min($discountAmount, $maxDiscount, $grossAmount);
        $appliedPoints = intdiv($discountAmount, $value) * $step;

        return [
            'applied_points' => max(0, $appliedPoints),
            'discount_amount' => max(0, $discountAmount),
        ];
    }

    public function redeemForBooking(User $actor, Client $client, Booking $booking, int $requestedPoints, int $grossAmount): array
    {
        $program = $this->getOrCreateProgram((int) $booking->business_id);
        $balance = $this->getClientBalance((int) $booking->business_id, (int) $client->id);
        $preview = $this->previewRedemption($program, $balance, $grossAmount, $requestedPoints);

        if (($preview['applied_points'] ?? 0) <= 0) {
            return ['applied_points' => 0, 'discount_amount' => 0, 'ledger' => null];
        }

        $existing = LoyaltyPointLedger::query()
            ->where('business_id', $booking->business_id)
            ->where('booking_id', $booking->id)
            ->where('entry_type', 'redeemed')
            ->exists();
        if ($existing) {
            return ['applied_points' => 0, 'discount_amount' => 0, 'ledger' => null];
        }

        $ledger = LoyaltyPointLedger::create([
            'business_id' => $booking->business_id,
            'client_id' => $client->id,
            'booking_id' => $booking->id,
            'delta_points' => -1 * (int) $preview['applied_points'],
            'entry_type' => 'redeemed',
            'reason' => 'Միավորների օգտագործում ամրագրման վրա',
            'meta' => [
                'discount_amount' => (int) $preview['discount_amount'],
                'gross_amount' => $grossAmount,
            ],
            'created_by' => $actor->id,
        ]);

        return [
            'applied_points' => (int) $preview['applied_points'],
            'discount_amount' => (int) $preview['discount_amount'],
            'ledger' => $ledger,
        ];
    }

    public function awardForBookingDone(User $actor, Booking $booking): ?LoyaltyPointLedger
    {
        if (!$booking->client_id) return null;

        $program = $this->getOrCreateProgram((int) $booking->business_id);
        if (!$program->is_enabled) return null;

        $amount = (int) ($booking->final_price ?? 0);
        $points = $this->computePoints($program, $amount);
        if ($points <= 0) return null;

        $exists = LoyaltyPointLedger::query()
            ->where('business_id', $booking->business_id)
            ->where('booking_id', $booking->id)
            ->where('entry_type', 'earned')
            ->exists();
        if ($exists) return null;

        $expiresAt = ((int) $program->points_expire_after_days > 0)
            ? Carbon::now()->addDays((int) $program->points_expire_after_days)
            : null;

        return LoyaltyPointLedger::create([
            'business_id' => $booking->business_id,
            'client_id' => $booking->client_id,
            'booking_id' => $booking->id,
            'delta_points' => $points,
            'entry_type' => 'earned',
            'reason' => 'Ավարտված ամրագրման միավորներ',
            'expires_at' => $expiresAt,
            'meta' => [
                'final_price' => (int) ($booking->final_price ?? 0),
            ],
            'created_by' => $actor->id,
        ]);
    }

    public function reverseRedeemedForBooking(User $actor, Booking $booking): void
    {
        if (!$booking->client_id) return;

        $redemptions = LoyaltyPointLedger::query()
            ->where('business_id', $booking->business_id)
            ->where('booking_id', $booking->id)
            ->where('entry_type', 'redeemed')
            ->get();

        foreach ($redemptions as $entry) {
            $alreadyRestored = LoyaltyPointLedger::query()
                ->where('reverted_ledger_id', $entry->id)
                ->exists();
            if ($alreadyRestored) continue;

            LoyaltyPointLedger::create([
                'business_id' => $entry->business_id,
                'client_id' => $entry->client_id,
                'booking_id' => $entry->booking_id,
                'delta_points' => abs((int) $entry->delta_points),
                'entry_type' => 'restored',
                'reason' => 'Չեղարկված ամրագրման միավորների վերադարձ',
                'meta' => $entry->meta,
                'reverted_ledger_id' => $entry->id,
                'created_by' => $actor->id,
            ]);
        }
    }

    public function adjust(User $actor, Client $client, int $delta, ?string $reason = null): LoyaltyPointLedger
    {
        return LoyaltyPointLedger::create([
            'business_id' => $client->business_id,
            'client_id' => $client->id,
            'booking_id' => null,
            'delta_points' => $delta,
            'entry_type' => 'adjustment',
            'reason' => $reason,
            'created_by' => $actor->id,
        ]);
    }
}
