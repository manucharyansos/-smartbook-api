<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401);

        $business = Business::query()
            ->with(['subscription.plan'])
            ->findOrFail((int) $user->business_id);

        $tz = $business->effectiveTimezone();
        $nowLocal = Carbon::now($tz);
        $nowUtc = $nowLocal->copy()->utc();

        $todayStartUtc = $nowLocal->copy()->startOfDay()->utc();
        $todayEndUtc = $nowLocal->copy()->endOfDay()->utc();
        $upcomingToUtc = $nowLocal->copy()->addDays(7)->endOfDay()->utc();
        $window30FromUtc = $nowLocal->copy()->subDays(29)->startOfDay()->utc();
        $window30ToUtc = $todayEndUtc->copy();

        $bookingsBase = Booking::query()->where('business_id', $business->id);
        $activeStatuses = ['pending', 'confirmed', 'done', 'completed'];
        $revenueStatuses = ['confirmed', 'done', 'completed'];

        $todayStats = [
            'total' => (clone $bookingsBase)
                ->whereBetween('starts_at', [$todayStartUtc, $todayEndUtc])
                ->count(),
            'confirmed' => (clone $bookingsBase)
                ->whereBetween('starts_at', [$todayStartUtc, $todayEndUtc])
                ->whereIn('status', ['confirmed', 'done', 'completed'])
                ->count(),
            'pending' => (clone $bookingsBase)
                ->whereBetween('starts_at', [$todayStartUtc, $todayEndUtc])
                ->where('status', 'pending')
                ->count(),
            'cancelled' => (clone $bookingsBase)
                ->whereBetween('starts_at', [$todayStartUtc, $todayEndUtc])
                ->whereIn('status', ['cancelled', 'no_show'])
                ->count(),
            'revenue' => (float) (clone $bookingsBase)
                ->whereBetween('starts_at', [$todayStartUtc, $todayEndUtc])
                ->whereIn('status', $revenueStatuses)
                ->sum('final_price'),
        ];

        $upcomingCount = (clone $bookingsBase)
            ->where('starts_at', '>=', $nowUtc)
            ->where('starts_at', '<=', $upcomingToUtc)
            ->whereIn('status', $activeStatuses)
            ->count();

        $counts = [
            'staff' => $business->activeSeatCount(),
            'services' => $business->activeServiceCount(),
            'locations' => $business->locationCount(),
        ];

        $recentWindowBase = (clone $bookingsBase)
            ->whereBetween('starts_at', [$window30FromUtc, $window30ToUtc])
            ->whereNotIn('status', ['cancelled']);

        $topStaffRow = (clone $recentWindowBase)
            ->whereNotNull('staff_id')
            ->select('staff_id', DB::raw('COUNT(*) as bookings'))
            ->groupBy('staff_id')
            ->orderByDesc('bookings')
            ->first();
        $topStaff = null;
        if ($topStaffRow) {
            $staff = User::query()->find($topStaffRow->staff_id, ['id', 'name']);
            $topStaff = [
                'id' => (int) $topStaffRow->staff_id,
                'name' => $staff?->name ?? 'Deleted Staff',
                'bookings' => (int) ($topStaffRow->bookings ?? 0),
            ];
        }

        $topServiceRow = (clone $recentWindowBase)
            ->whereNotNull('service_id')
            ->select('service_id', DB::raw('COUNT(*) as bookings'))
            ->groupBy('service_id')
            ->orderByDesc('bookings')
            ->first();
        $topService = null;
        if ($topServiceRow) {
            $service = Service::query()->find($topServiceRow->service_id, ['id', 'name']);
            $topService = [
                'id' => (int) $topServiceRow->service_id,
                'name' => $service?->name ?? 'Deleted Service',
                'bookings' => (int) ($topServiceRow->bookings ?? 0),
            ];
        }

        $bookingsByLocation = collect();

        if (Schema::hasColumn('bookings', 'location_id')) {
            $locationRows = (clone $recentWindowBase)
                ->whereNotNull('location_id')
                ->select('location_id', DB::raw('COUNT(*) as bookings'))
                ->groupBy('location_id')
                ->orderByDesc('bookings')
                ->limit(6)
                ->get();

            $locationMap = BusinessLocation::query()
                ->whereIn('id', $locationRows->pluck('location_id')->all())
                ->get(['id', 'name', 'address'])
                ->keyBy('id');

            $bookingsByLocation = $locationRows->map(function ($row) use ($locationMap) {
                $location = $locationMap->get((int) $row->location_id);

                return [
                    'location_id' => (int) $row->location_id,
                    'location_name' => $location?->name ?: ($location?->address ?: 'Unknown location'),
                    'bookings' => (int) ($row->bookings ?? 0),
                ];
            })->values();
        }

        $upcomingBookings = (clone $bookingsBase)
            ->with(['service:id,name', 'staff:id,name', 'location:id,name,address'])
            ->where('starts_at', '>=', $nowUtc)
            ->whereIn('status', $activeStatuses)
            ->orderBy('starts_at')
            ->limit(6)
            ->get()
            ->map(function (Booking $booking) use ($tz) {
                return [
                    'id' => (int) $booking->id,
                    'client_name' => (string) ($booking->client_name ?? '—'),
                    'status' => (string) ($booking->status ?? 'pending'),
                    'starts_at' => optional($booking->starts_at)->clone()?->timezone($tz)?->toIso8601String(),
                    'ends_at' => optional($booking->ends_at)->clone()?->timezone($tz)?->toIso8601String(),
                    'service' => $booking->service ? [
                        'id' => (int) $booking->service->id,
                        'name' => (string) $booking->service->name,
                    ] : null,
                    'staff' => $booking->staff ? [
                        'id' => (int) $booking->staff->id,
                        'name' => (string) $booking->staff->name,
                    ] : null,
                    'location' => $booking->location ? [
                        'id' => (int) $booking->location->id,
                        'name' => (string) ($booking->location->name ?: $booking->location->address ?: 'Location'),
                    ] : null,
                ];
            })
            ->values();

        $usage = [
            'staff' => [
                'current' => $business->activeSeatCount(),
                'limit' => $business->seatLimit(),
            ],
            'services' => [
                'current' => $business->activeServiceCount(),
                'limit' => $business->serviceLimit(),
            ],
            'locations' => [
                'current' => $business->locationCount(),
                'limit' => $business->locationLimit(),
            ],
        ];

        return response()->json([
            'data' => [
                'business' => [
                    'id' => $business->id,
                    'name' => $business->name,
                    'timezone' => $tz,
                ],
                'today' => $todayStats,
                'upcoming' => [
                    'next_7_days' => $upcomingCount,
                    'rows' => $upcomingBookings,
                ],
                'counts' => $counts,
                'usage' => $usage,
                'highlights_30d' => [
                    'top_staff' => $topStaff,
                    'top_service' => $topService,
                    'bookings_by_location' => $bookingsByLocation,
                ],
                'currency' => 'AMD',
            ],
        ]);
    }
}
