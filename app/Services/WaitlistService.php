<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\Phone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WaitlistService
{
    public const OFFER_MINUTES = 30;

    public function offerFreedSlot(
        Booking $booking,
        ?Carbon $freedStart = null,
        ?Carbon $freedEnd = null,
        ?int $freedStaffId = null,
    ): ?WaitlistEntry {
        $booking->loadMissing(['business', 'service']);
        $business = $booking->business;
        $service = $booking->service;
        $startUtc = $freedStart ?: $booking->starts_at;
        $endUtc = $freedEnd ?: $booking->ends_at;
        $staffId = $freedStaffId ?: (int) $booking->staff_id;

        if (!$business || !$service || !$startUtc || !$endUtc || !$staffId) {
            return null;
        }

        $timezone = $business->effectiveTimezone();
        $startLocal = $startUtc->copy()->timezone($timezone);
        $endLocal = $endUtc->copy()->timezone($timezone);

        $entries = WaitlistEntry::query()
            ->where('business_id', $business->id)
            ->where('service_id', $service->id)
            ->where('status', 'waiting')
            ->whereDate('desired_date', $startLocal->toDateString())
            ->when($booking->location_id, fn ($query) => $query->where(function ($location) use ($booking) {
                $location->whereNull('location_id')->orWhere('location_id', $booking->location_id);
            }))
            ->where(function ($query) use ($staffId) {
                $query->whereNull('staff_id')->orWhere('staff_id', $staffId);
            })
            ->orderBy('created_at')
            ->get();

        foreach ($entries as $entry) {
            if (!$this->insideWindow($entry, $startLocal, $endLocal)) {
                continue;
            }
            if (!$this->slotCanFit($business, $service, $staffId, $startUtc, $endUtc, (int) $entry->party_size)) {
                continue;
            }

            try {
                return $this->issueOffer($entry, $staffId, $startUtc, $endUtc);
            } catch (ValidationException) {
                // Another request may have claimed the slot or the notification may
                // have failed. Continue fairly with the next waiting customer.
                continue;
            }
        }

        return null;
    }

    public function offerEntry(WaitlistEntry $entry, int $staffId, Carbon $startLocal): WaitlistEntry
    {
        $entry->loadMissing(['business', 'service']);
        $business = $entry->business;
        $service = $entry->service;
        if (!$business || !$service) {
            throw ValidationException::withMessages(['waitlist' => 'Waiting-list entry is no longer available.']);
        }

        $staff = User::query()
            ->whereKey($staffId)
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->where('is_bookable', true)
            ->first();
        if (!$staff) {
            throw ValidationException::withMessages(['staff_id' => 'Invalid specialist.']);
        }

        $endLocal = $startLocal->copy()->addMinutes((int) $service->duration_minutes);
        if (!$this->insideWindow($entry, $startLocal, $endLocal)) {
            throw ValidationException::withMessages(['starts_at' => 'The time is outside the customer’s requested window.']);
        }

        $startUtc = $startLocal->copy()->timezone('UTC');
        $endUtc = $endLocal->copy()->timezone('UTC');
        if (!$this->slotCanFit($business, $service, $staffId, $startUtc, $endUtc, (int) $entry->party_size)) {
            throw ValidationException::withMessages(['starts_at' => 'The selected time is no longer available.']);
        }

        return $this->issueOffer($entry, $staffId, $startUtc, $endUtc);
    }

    public function issueOffer(WaitlistEntry $entry, int $staffId, Carbon $startUtc, Carbon $endUtc): WaitlistEntry
    {
        $plainToken = Str::random(48);
        $tokenHash = hash('sha256', $plainToken);
        $offered = DB::transaction(function () use ($entry, $staffId, $startUtc, $endUtc, $tokenHash) {
            User::query()->whereKey($staffId)->lockForUpdate()->firstOrFail();
            $locked = WaitlistEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($locked->status !== 'waiting') {
                throw ValidationException::withMessages(['waitlist' => 'This waiting-list entry already has an offer.']);
            }

            $locked->loadMissing(['business', 'service']);
            if (!$locked->business || !$locked->service || !$this->slotCanFit(
                $locked->business,
                $locked->service,
                $staffId,
                $startUtc,
                $endUtc,
                (int) $locked->party_size,
            )) {
                throw ValidationException::withMessages(['starts_at' => 'The selected time is no longer available.']);
            }

            $locked->update([
                'status' => 'offered',
                'offered_staff_id' => $staffId,
                'offered_starts_at' => $startUtc->copy()->timezone('UTC'),
                'offered_ends_at' => $endUtc->copy()->timezone('UTC'),
                'offer_token_hash' => $tokenHash,
                'offer_expires_at' => now()->addMinutes(self::OFFER_MINUTES),
                'notified_at' => now(),
            ]);

            return $locked->fresh(['business', 'service', 'staff', 'offeredStaff', 'location']);
        });

        try {
            $this->sendOfferEmail($offered, $plainToken);
        } catch (\Throwable $exception) {
            Log::warning('Waitlist offer email failed', [
                'waitlist_entry_id' => $offered->id,
                'error' => $exception->getMessage(),
            ]);
            WaitlistEntry::query()
                ->whereKey($offered->id)
                ->where('status', 'offered')
                ->where('offer_token_hash', $tokenHash)
                ->update([
                    'status' => 'waiting',
                    'offered_staff_id' => null,
                    'offered_starts_at' => null,
                    'offered_ends_at' => null,
                    'offer_token_hash' => null,
                    'offer_expires_at' => null,
                    'notified_at' => null,
                ]);
            throw ValidationException::withMessages(['customer_email' => 'The offer email could not be delivered.']);
        }

        return $offered->fresh(['business', 'service', 'staff', 'offeredStaff', 'location']);
    }

    public function acceptOffer(WaitlistEntry $entry, string $plainToken): array
    {
        if ($plainToken === '' || !hash_equals((string) $entry->offer_token_hash, hash('sha256', $plainToken))) {
            throw ValidationException::withMessages(['token' => 'Invalid waiting-list offer.']);
        }

        return DB::transaction(function () use ($entry, $plainToken) {
            User::query()->whereKey((int) $entry->offered_staff_id)->lockForUpdate()->firstOrFail();
            $locked = WaitlistEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if (!$locked->offer_token_hash || !hash_equals($locked->offer_token_hash, hash('sha256', $plainToken))) {
                throw ValidationException::withMessages(['token' => 'Invalid waiting-list offer.']);
            }
            if ($locked->status !== 'offered' || !$locked->offer_expires_at || $locked->offer_expires_at->isPast()) {
                if ($locked->status === 'offered') {
                    $locked->update(['status' => 'expired']);
                }
                throw ValidationException::withMessages(['offer' => 'This waiting-list offer has expired.']);
            }

            $locked->loadMissing(['business', 'service']);
            $business = $locked->business;
            $service = $locked->service;
            if (!$business || !$service || !$locked->offered_starts_at || !$locked->offered_ends_at) {
                throw ValidationException::withMessages(['offer' => 'This waiting-list offer is incomplete.']);
            }
            if (!$this->slotCanFit(
                $business,
                $service,
                (int) $locked->offered_staff_id,
                $locked->offered_starts_at,
                $locked->offered_ends_at,
                (int) $locked->party_size,
                null,
                (int) $locked->id,
            )) {
                throw ValidationException::withMessages(['offer' => 'This time was just taken. Join the waiting list again for another offer.']);
            }

            $phone = Phone::normalizeAM($locked->customer_phone) ?: $locked->customer_phone;
            $email = Booking::normalizeContactEmail($locked->customer_email);
            $client = Client::query()->firstOrCreate(
                ['business_id' => $business->id, 'phone' => $phone],
                ['name' => $locked->customer_name, 'email' => $email]
            );
            $client->update(array_filter([
                'name' => $locked->customer_name,
                'email' => $email,
            ], fn ($value) => $value !== null && $value !== ''));

            $guestToken = Str::random(40);
            $booking = Booking::query()->create([
                'business_id' => $business->id,
                'location_id' => $locked->location_id ?: $service->location_id,
                'service_id' => $service->id,
                'staff_id' => $locked->offered_staff_id,
                'client_id' => $client->id,
                'client_name' => $locked->customer_name,
                'client_phone' => $phone,
                'client_email' => $email,
                'starts_at' => $locked->offered_starts_at,
                'ends_at' => $locked->offered_ends_at,
                'party_size' => max(1, (int) $locked->party_size),
                'status' => 'confirmed',
                'source' => 'waitlist',
                'booking_code' => strtoupper(Str::random(8)),
                'final_price' => $service->price === null ? null : (int) $service->price * max(1, (int) $locked->party_size),
                'currency' => $service->currency ?? 'AMD',
                'phone_verified_at' => now(),
                'guest_access_token_hash' => Hash::make($guestToken),
                'guest_access_expires_at' => now()->addDays(7),
            ]);

            $locked->update([
                'status' => 'booked',
                'client_id' => $client->id,
                'booked_booking_id' => $booking->id,
                'offer_token_hash' => null,
            ]);

            return ['booking' => $booking, 'guest_token' => $guestToken];
        });
    }

    public function slotCanFit(
        Business $business,
        Service $service,
        int $staffId,
        Carbon $startUtc,
        Carbon $endUtc,
        int $partySize,
        ?int $ignoreBookingId = null,
        ?int $ignoreWaitlistEntryId = null,
    ): bool
    {
        $overlaps = Booking::query()
            ->where('business_id', $business->id)
            ->where('staff_id', $staffId)
            ->where(function ($query) {
                $query->whereIn('status', ['confirmed', 'in_progress'])
                    ->orWhere(function ($pending) {
                        $pending->where('status', 'pending')
                            ->where(function ($verifiedOrFresh) {
                                $verifiedOrFresh->whereNotNull('phone_verified_at')
                                    ->orWhereNull('phone_verification_code_hash')
                                    ->orWhere(function ($fresh) {
                                        $fresh->whereNotNull('phone_verification_expires_at')
                                            ->where('phone_verification_expires_at', '>=', now());
                                    });
                            });
                    });
            })
            ->where('starts_at', '<', $endUtc->copy()->timezone('UTC')->format('Y-m-d H:i:s'))
            ->where('ends_at', '>', $startUtc->copy()->timezone('UTC')->format('Y-m-d H:i:s'))
            ->when($ignoreBookingId, fn ($query) => $query->where('id', '!=', $ignoreBookingId))
            ->get(['service_id', 'starts_at', 'ends_at', 'party_size']);

        $holds = WaitlistEntry::query()
            ->where('business_id', $business->id)
            ->where('offered_staff_id', $staffId)
            ->where('status', 'offered')
            ->where('offer_expires_at', '>', now())
            ->where('offered_starts_at', '<', $endUtc->copy()->timezone('UTC')->format('Y-m-d H:i:s'))
            ->where('offered_ends_at', '>', $startUtc->copy()->timezone('UTC')->format('Y-m-d H:i:s'))
            ->when($ignoreWaitlistEntryId, fn ($query) => $query->where('id', '!=', $ignoreWaitlistEntryId))
            ->get(['id', 'service_id', 'offered_starts_at', 'offered_ends_at', 'party_size']);

        if (($service->booking_mode ?? 'individual') !== 'group') {
            return $overlaps->isEmpty() && $holds->isEmpty();
        }

        $used = 0;
        foreach ($overlaps as $booking) {
            $sameClass = (int) $booking->service_id === (int) $service->id
                && $booking->starts_at?->equalTo($startUtc)
                && $booking->ends_at?->equalTo($endUtc);
            if (!$sameClass) {
                return false;
            }
            $used += max(1, (int) ($booking->party_size ?? 1));
        }

        foreach ($holds as $hold) {
            $sameClass = (int) $hold->service_id === (int) $service->id
                && $hold->offered_starts_at?->equalTo($startUtc)
                && $hold->offered_ends_at?->equalTo($endUtc);
            if (!$sameClass) {
                return false;
            }
            $used += max(1, (int) ($hold->party_size ?? 1));
        }

        return $used + max(1, $partySize) <= max(1, (int) $service->capacity);
    }

    private function insideWindow(WaitlistEntry $entry, Carbon $startLocal, Carbon $endLocal): bool
    {
        if ($entry->window_start && $startLocal->format('H:i:s') < (string) $entry->window_start) {
            return false;
        }
        if ($entry->window_end && $endLocal->format('H:i:s') > (string) $entry->window_end) {
            return false;
        }
        return true;
    }

    private function sendOfferEmail(WaitlistEntry $entry, string $plainToken): void
    {
        $business = $entry->business;
        $timezone = $business?->effectiveTimezone() ?? 'Asia/Yerevan';
        $time = $entry->offered_starts_at?->copy()->timezone($timezone)->format('d.m.Y H:i') ?? '—';
        $url = rtrim((string) config('services.public_booking.frontend_url', 'https://vizit.am'), '/')
            . '/book/' . rawurlencode((string) $business?->slug)
            . '?waitlist_offer=' . $entry->id
            . '&waitlist_token=' . rawurlencode($plainToken);
        $body = implode("\n\n", [
            'Բարև ' . $entry->customer_name . ',',
            ($business?->name ?? 'Vizit') . '-ում ազատվել է ձեր սպասած ժամը։',
            'Ծառայություն՝ ' . ($entry->service?->name ?? '—') . "\nԺամ՝ " . $time . "\nՄասնագետ՝ " . ($entry->offeredStaff?->name ?? '—'),
            'Ժամը ձեր համար պահվում է ' . self::OFFER_MINUTES . ' րոպե։ Հաստատեք այստեղ՝ ' . $url,
        ]);

        Mail::raw($body, function ($message) use ($entry, $business) {
            $message->to($entry->customer_email)
                ->subject('Ազատ ժամ է հայտնվել • ' . ($business?->name ?? 'Vizit'));
        });
    }
}
