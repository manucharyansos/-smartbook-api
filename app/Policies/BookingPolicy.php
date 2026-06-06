<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            User::ROLE_OWNER,
            User::ROLE_MANAGER,
            User::ROLE_STAFF,
        ]);
    }

    public function view(User $user, Booking $booking): bool
    {
        return $user->business_id === $booking->business_id; // Փոխել salon_id-ից business_id
    }

    public function update(User $user, Booking $booking): bool
    {
        return $user->business_id === $booking->business_id; // Փոխել salon_id-ից business_id
    }
}
