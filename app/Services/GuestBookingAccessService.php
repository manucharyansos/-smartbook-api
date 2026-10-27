<?php

namespace App\Services;

use App\Models\Booking;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Issues the short-lived token a guest uses to view, cancel or reschedule a booking.
 *
 * A token must only be issued after the caller has proved control of the booking:
 * a fresh OTP check, or an authenticated client account that owns the booking.
 * Knowing the booking code alone is never enough.
 */
class GuestBookingAccessService
{
    public function issue(Booking $booking): string
    {
        $plainToken = Str::random(40);

        $this->groupQuery($booking)->update([
            'guest_access_token_hash' => Hash::make($plainToken),
            'guest_access_expires_at' => now()->addDays(7),
        ]);

        return $plainToken;
    }

    /** All bookings created together with this one (recurring or multi-service). */
    public function groupQuery(Booking $booking)
    {
        $query = Booking::query()->where('client_id', $booking->client_id);

        return $booking->group_id
            ? $query->where('group_id', $booking->group_id)
            : $query->where('id', $booking->id);
    }
}
