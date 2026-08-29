<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\GiftCard;
use App\Models\GiftCardLedger;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class GiftCardService
{
    public function issue(User $actor, int $businessId, array $data): GiftCard
    {
        $code = !empty($data['code']) ? strtoupper(trim((string) $data['code'])) : $this->generateCode();
        while (GiftCard::where('code', $code)->exists()) {
            $code = $this->generateCode();
        }

        $giftCard = GiftCard::create([
            'business_id' => $businessId,
            'code' => $code,
            'initial_amount' => (int) $data['amount'],
            'balance' => (int) $data['amount'],
            'currency' => $data['currency'] ?? 'AMD',
            'issued_to_name' => $data['issued_to_name'] ?? null,
            'issued_to_phone' => $data['issued_to_phone'] ?? null,
            'issued_to_email' => isset($data['issued_to_email']) ? mb_strtolower(trim($data['issued_to_email'])) : null,
            'purchased_by_name' => $data['purchased_by_name'] ?? null,
            'purchased_by_phone' => $data['purchased_by_phone'] ?? null,
            'purchased_by_email' => isset($data['purchased_by_email']) ? mb_strtolower(trim($data['purchased_by_email'])) : null,
            'expires_at' => $data['expires_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'delivery_message' => $data['delivery_message'] ?? null,
            'delivery_status' => 'not_requested',
            'status' => 'active',
        ]);

        GiftCardLedger::create([
            'business_id' => $businessId,
            'gift_card_id' => $giftCard->id,
            'booking_id' => null,
            'delta_amount' => (int) $giftCard->initial_amount,
            'entry_type' => 'issued',
            'reason' => 'Նվերի քարտի թողարկում',
            'created_by' => $actor->id,
        ]);

        return $giftCard;
    }

    public function deliver(User $actor, GiftCard $giftCard): GiftCard
    {
        $giftCard->loadMissing('business');
        $email = trim((string) $giftCard->issued_to_email);
        if ($email === '') {
            throw ValidationException::withMessages(['issued_to_email' => 'Recipient email is required.']);
        }

        $expires = $giftCard->expires_at?->timezone($giftCard->business?->effectiveTimezone() ?? 'Asia/Yerevan')->format('d.m.Y') ?: 'անսահմանափակ';
        $body = implode("\n\n", array_filter([
            'Բարև ' . ($giftCard->issued_to_name ?: '') . ',',
            ($giftCard->business?->name ?? 'Vizit') . '-ից ձեզ նվեր քարտ է ուղարկվել։',
            $giftCard->delivery_message,
            'Կոդ՝ ' . $giftCard->code . "\nԳումար՝ " . number_format((int) $giftCard->balance, 0, '.', ' ') . ' ' . $giftCard->currency . "\nՎավեր է մինչև՝ " . $expires,
        ]));

        try {
            Mail::raw($body, function ($message) use ($giftCard, $email) {
                $message->to($email)->subject('Ձեր նվեր քարտը • ' . ($giftCard->business?->name ?? 'Vizit'));
            });
            $giftCard->update(['delivery_status' => 'sent', 'delivered_at' => now()]);
        } catch (\Throwable $exception) {
            $giftCard->update(['delivery_status' => 'failed']);
            Log::warning('Gift card delivery failed', [
                'gift_card_id' => $giftCard->id,
                'error' => $exception->getMessage(),
            ]);
            throw ValidationException::withMessages(['issued_to_email' => 'The gift card email could not be delivered.']);
        }

        return $giftCard->fresh();
    }

    public function lookupActiveByCode(int $businessId, string $code): GiftCard
    {
        $giftCard = GiftCard::query()
            ->where('business_id', $businessId)
            ->where('code', strtoupper(trim($code)))
            ->first();

        if (!$giftCard) {
            throw ValidationException::withMessages(['gift_card_code' => 'Նվերի քարտը չի գտնվել']);
        }

        if (!$giftCard->isActive()) {
            throw ValidationException::withMessages(['gift_card_code' => 'Նվերի քարտը ակտիվ չէ կամ սպառված է']);
        }

        return $giftCard;
    }

    public function redeemForBooking(User $actor, GiftCard $giftCard, Booking $booking, int $amount): array
    {
        if ($amount <= 0) {
            return ['amount' => 0, 'ledger' => null];
        }
        if (!$giftCard->isActive()) {
            throw ValidationException::withMessages(['gift_card_code' => 'Նվերի քարտը ակտիվ չէ կամ սպառված է']);
        }

        $existing = GiftCardLedger::query()
            ->where('gift_card_id', $giftCard->id)
            ->where('booking_id', $booking->id)
            ->where('entry_type', 'redeemed')
            ->exists();
        if ($existing) {
            return ['amount' => 0, 'ledger' => null];
        }

        $appliedAmount = min((int) $giftCard->balance, $amount, (int) ($booking->final_price ?? 0));
        if ($appliedAmount <= 0) {
            return ['amount' => 0, 'ledger' => null];
        }

        $giftCard->balance = max(0, (int) $giftCard->balance - $appliedAmount);
        $giftCard->redeemed_total = (int) $giftCard->redeemed_total + $appliedAmount;
        $giftCard->last_redeemed_at = now();
        if ((int) $giftCard->balance === 0) {
            $giftCard->status = 'redeemed';
        }
        $giftCard->save();

        $ledger = GiftCardLedger::create([
            'business_id' => $giftCard->business_id,
            'gift_card_id' => $giftCard->id,
            'booking_id' => $booking->id,
            'delta_amount' => -1 * $appliedAmount,
            'entry_type' => 'redeemed',
            'reason' => 'Նվերի քարտի օգտագործում ամրագրման վրա',
            'created_by' => $actor->id,
        ]);

        return ['amount' => $appliedAmount, 'ledger' => $ledger];
    }

    public function restoreForBooking(User $actor, Booking $booking): void
    {
        $entries = GiftCardLedger::query()
            ->where('business_id', $booking->business_id)
            ->where('booking_id', $booking->id)
            ->where('entry_type', 'redeemed')
            ->get();

        foreach ($entries as $entry) {
            $alreadyRestored = GiftCardLedger::query()->where('reverted_ledger_id', $entry->id)->exists();
            if ($alreadyRestored) continue;

            $giftCard = GiftCard::query()->find($entry->gift_card_id);
            if (!$giftCard) continue;

            $restoreAmount = abs((int) $entry->delta_amount);
            $giftCard->balance = (int) $giftCard->balance + $restoreAmount;
            $giftCard->redeemed_total = max(0, (int) $giftCard->redeemed_total - $restoreAmount);
            if ($giftCard->status !== 'cancelled') {
                $giftCard->status = 'active';
            }
            $giftCard->save();

            GiftCardLedger::create([
                'business_id' => $entry->business_id,
                'gift_card_id' => $entry->gift_card_id,
                'booking_id' => $entry->booking_id,
                'delta_amount' => $restoreAmount,
                'entry_type' => 'restored',
                'reason' => 'Չեղարկված ամրագրման նվերի քարտի վերադարձ',
                'created_by' => $actor->id,
                'reverted_ledger_id' => $entry->id,
            ]);
        }
    }

    public function manualRedeem(User $actor, GiftCard $giftCard, int $amount, ?string $reason = null): GiftCard
    {
        if (!$giftCard->isActive()) {
            throw ValidationException::withMessages(['gift_card' => 'Նվերի քարտը ակտիվ չէ կամ սպառված է']);
        }
        if ($amount > (int) $giftCard->balance) {
            throw ValidationException::withMessages(['amount' => 'Գումարը գերազանցում է մնացորդը']);
        }

        $giftCard->balance = (int) $giftCard->balance - $amount;
        $giftCard->redeemed_total = (int) $giftCard->redeemed_total + $amount;
        $giftCard->last_redeemed_at = now();
        if ((int) $giftCard->balance <= 0) {
            $giftCard->balance = 0;
            $giftCard->status = 'redeemed';
        }
        $giftCard->save();

        GiftCardLedger::create([
            'business_id' => $giftCard->business_id,
            'gift_card_id' => $giftCard->id,
            'booking_id' => null,
            'delta_amount' => -1 * $amount,
            'entry_type' => 'manual_redeem',
            'reason' => $reason ?: 'Ձեռքով մարում',
            'created_by' => $actor->id,
        ]);

        return $giftCard;
    }

    public function adjust(User $actor, GiftCard $giftCard, int $delta, ?string $reason = null): GiftCard
    {
        $next = (int) $giftCard->balance + $delta;
        if ($next < 0) {
            throw ValidationException::withMessages(['delta_amount' => 'Մնացորդը չի կարող բացասական դառնալ']);
        }

        $giftCard->balance = $next;
        if ($giftCard->balance > 0 && $giftCard->status !== 'cancelled') {
            $giftCard->status = 'active';
        }
        if ($giftCard->balance === 0 && $giftCard->status !== 'cancelled') {
            $giftCard->status = 'redeemed';
        }
        $giftCard->save();

        GiftCardLedger::create([
            'business_id' => $giftCard->business_id,
            'gift_card_id' => $giftCard->id,
            'booking_id' => null,
            'delta_amount' => $delta,
            'entry_type' => 'adjustment',
            'reason' => $reason ?: 'Ձեռքով փոփոխություն',
            'created_by' => $actor->id,
        ]);

        return $giftCard;
    }

    private function generateCode(): string
    {
        return 'GC-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
    }
}
