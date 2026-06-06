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

        $linkedClientIds = $account->clientProfiles()->pluck('clients.id');

        $bookings = Booking::query()
            ->with([
                'business:id,name,slug,logo_url,address',
                'service:id,name,duration_minutes,price,currency',
                'staff:id,name,avatar_url',
            ])
            ->whereIn('client_id', $linkedClientIds)
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
            ],
        ]);
    }
}
