<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ClientAccount;
use Illuminate\Http\Request;

class ClientCabinetController extends Controller
{
    public function bookings(Request $request)
    {
        /** @var ClientAccount $account */
        $account = $request->user();
        abort_unless($account instanceof ClientAccount, 401);

        $verifiedEmail = $account->hasVerifiedEmail()
            ? mb_strtolower(trim((string) $account->email))
            : null;

        $linkedClientIds = $verifiedEmail
            ? $account->clientProfiles()
                ->whereRaw('LOWER(clients.email) = ?', [$verifiedEmail])
                ->pluck('clients.id')
            : collect();

        $bookings = Booking::query()
            ->with([
                'business:id,name,slug,logo_url,address',
                'service:id,name,duration_minutes,price,currency',
                'staff:id,name,avatar_url',
            ])
            ->whereIn('bookings.client_id', $linkedClientIds)
            ->whereExists(function ($query) use ($account, $verifiedEmail) {
                $query->selectRaw('1')
                    ->from('clients')
                    ->whereColumn('clients.id', 'bookings.client_id')
                    ->whereColumn('clients.business_id', 'bookings.business_id')
                    ->where('clients.client_account_id', $account->id)
                    ->whereRaw('LOWER(clients.email) = ?', [$verifiedEmail]);
            })
            ->orderByDesc('starts_at')
            ->get();

        $today = now()->startOfDay();

        $format = function (Booking $booking) {
            return [
                'id' => $booking->id,
                'booking_code' => $booking->booking_code,
                'status' => $booking->status,
                'starts_at' => optional($booking->starts_at)->toISOString(),
                'ends_at' => optional($booking->ends_at)->toISOString(),
                'client_name' => $booking->client_name,
                'client_phone' => $booking->client_phone,
                'final_price' => $booking->final_price,
                'currency' => $booking->currency,
                'business' => [
                    'name' => $booking->business?->name,
                    'slug' => $booking->business?->slug,
                    'logo_url' => $booking->business?->logo_url,
                    'address' => $booking->business?->address,
                ],
                'service' => [
                    'name' => $booking->service?->name,
                    'duration_minutes' => $booking->service?->duration_minutes,
                    'price' => $booking->service?->price,
                    'currency' => $booking->service?->currency,
                ],
                'staff' => [
                    'name' => $booking->staff?->name,
                    'avatar_url' => $booking->staff?->avatar_url,
                ],
            ];
        };

        return response()->json([
            'data' => [
                'upcoming' => $bookings->filter(fn (Booking $b) => $b->starts_at >= $today)->values()->map($format),
                'past' => $bookings->filter(fn (Booking $b) => $b->starts_at < $today)->values()->map($format),
            ],
            'meta' => [
                'linked_profiles' => $linkedClientIds->count(),
                'requires_email_verification' => !$account->hasVerifiedEmail(),
            ],
        ]);
    }
}
