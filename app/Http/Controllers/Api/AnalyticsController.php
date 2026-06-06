<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    private const REVENUE_STATUSES = ['confirmed', 'done'];

    private function getBusiness(Request $request): Business
    {
        $businessId = (int) ($request->query('business_id') ?? 0);
        if ($businessId) {
            return Business::query()->findOrFail($businessId);
        }

        $user = $request->user();
        if ($user && $user->business_id) {
            return Business::query()->findOrFail((int) $user->business_id);
        }

        if (app()->environment('local', 'development')) {
            $business = Business::query()->orderBy('id')->first();
            if ($business) {
                return $business;
            }
        }

        abort(404, 'Business not found');
    }

    private function bookingBase(Request $request, Business $business): Builder
    {
        $query = Booking::query()->where('business_id', $business->id);

        $source = trim((string) $request->query('source', ''));
        if ($source !== '') {
            $query->where('source', $source);
        }

        $staffId = (int) ($request->query('staff_id') ?? 0);
        if ($staffId > 0) {
            $query->where('staff_id', $staffId);
        }

        return $query;
    }

    private function revenueBookingBase(Request $request, Business $business): Builder
    {
        return $this->bookingBase($request, $business)
            ->whereIn('status', self::REVENUE_STATUSES);
    }

    public function overview(Request $request)
    {
        $business = $this->getBusiness($request);
        $nowUtc = Carbon::now('UTC');

        $todayFromUtc = $nowUtc->copy()->startOfDay();
        $todayToUtc = $nowUtc->copy()->endOfDay();
        $weekFromUtc = $nowUtc->copy()->subDays(6)->startOfDay();
        $weekToUtc = $nowUtc->copy()->endOfDay();
        $sourceWindowFrom = $nowUtc->copy()->subDays(29)->startOfDay();
        $sourceWindowTo = $nowUtc->copy()->endOfDay();

        $base = $this->bookingBase($request, $business);

        $todayBookings = (clone $base)
            ->whereBetween('starts_at', [$todayFromUtc, $todayToUtc])
            ->count();

        $todayRevenue = (float) (clone $this->revenueBookingBase($request, $business))
            ->whereBetween('starts_at', [$todayFromUtc, $todayToUtc])
            ->sum('final_price');

        $weekBookings = (clone $base)
            ->whereBetween('starts_at', [$weekFromUtc, $weekToUtc])
            ->count();

        $weekRevenue = (float) (clone $this->revenueBookingBase($request, $business))
            ->whereBetween('starts_at', [$weekFromUtc, $weekToUtc])
            ->sum('final_price');

        $uniqueClients = (clone $base)
            ->whereBetween('starts_at', [$weekFromUtc, $weekToUtc])
            ->whereNotNull('client_id')
            ->distinct('client_id')
            ->count('client_id');

        $sourceRows = (clone $base)
            ->whereBetween('starts_at', [$sourceWindowFrom, $sourceWindowTo])
            ->get(['source', 'status', 'final_price']);

        $sourceBreakdown = $sourceRows
            ->groupBy(function ($row) {
                $source = trim((string) ($row->source ?? ''));
                return $source !== '' ? $source : 'unknown';
            })
            ->map(function ($rows, $source) {
                $bookings = $rows->count();
                $revenue = $rows->reduce(function ($carry, $row) {
                    return $carry + (in_array((string) $row->status, ['confirmed', 'done'], true) ? (float) ($row->final_price ?? 0) : 0.0);
                }, 0.0);

                return [
                    'source' => (string) $source,
                    'bookings' => (int) $bookings,
                    'revenue' => (float) $revenue,
                ];
            })
            ->sortByDesc('bookings')
            ->values();

        $statusRows = (clone $base)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->whereBetween('starts_at', [$sourceWindowFrom, $sourceWindowTo])
            ->groupBy('status')
            ->orderByDesc('total')
            ->get();

        $statusBreakdown = $statusRows->map(fn ($row) => [
            'status' => (string) $row->status,
            'count' => (int) ($row->total ?? 0),
        ])->values();

        $windowRows = (clone $base)
            ->select('status', DB::raw('COUNT(*) as total'), DB::raw("SUM(CASE WHEN status IN ('confirmed','done') THEN COALESCE(final_price,0) ELSE 0 END) as revenue"))
            ->whereBetween('starts_at', [$sourceWindowFrom, $sourceWindowTo])
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $windowTotalBookings = (int) $windowRows->sum(fn ($row) => (int) ($row->total ?? 0));
        $doneBookings = (int) ($windowRows->get('done')->total ?? 0);
        $confirmedBookings = (int) ($windowRows->get('confirmed')->total ?? 0);
        $cancelledBookings = (int) ($windowRows->get('cancelled')->total ?? 0);
        $noShowBookings = (int) ($windowRows->get('no_show')->total ?? 0);
        $windowRevenue = (float) $windowRows->sum(fn ($row) => (float) ($row->revenue ?? 0));
        $paidBookings = $doneBookings + $confirmedBookings;
        $avgTicket = $paidBookings > 0 ? round($windowRevenue / $paidBookings, 1) : 0.0;
        $completionRate = $windowTotalBookings > 0 ? round(($doneBookings / $windowTotalBookings) * 100, 1) : 0.0;
        $cancellationRate = $windowTotalBookings > 0 ? round(($cancelledBookings / $windowTotalBookings) * 100, 1) : 0.0;
        $noShowRate = $windowTotalBookings > 0 ? round(($noShowBookings / $windowTotalBookings) * 100, 1) : 0.0;

        $daily = (clone $base)
            ->select(
                DB::raw('DATE(starts_at) as date'),
                DB::raw('COUNT(*) as bookings'),
                DB::raw("SUM(CASE WHEN status IN ('confirmed','done') THEN COALESCE(final_price,0) ELSE 0 END) as revenue")
            )
            ->whereBetween('starts_at', [$weekFromUtc, $weekToUtc])
            ->groupBy(DB::raw('DATE(starts_at)'))
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = $nowUtc->copy()->subDays($i)->toDateString();
            $trend[] = [
                'date' => $date,
                'bookings' => (int) ($daily[$date]->bookings ?? 0),
                'revenue' => (float) ($daily[$date]->revenue ?? 0),
            ];
        }

        return response()->json([
            'data' => [
                'business' => [
                    'id' => $business->id,
                    'name' => $business->name,
                    'type' => $business->business_type ?? 'services',
                ],
                'today' => [
                    'bookings' => $todayBookings,
                    'revenue' => $todayRevenue,
                ],
                'last_7_days' => [
                    'bookings' => $weekBookings,
                    'revenue' => $weekRevenue,
                    'unique_clients' => $uniqueClients,
                ],
                'trend' => $trend,
                'source_breakdown' => $sourceBreakdown,
                'status_breakdown' => $statusBreakdown,
                'metrics_30d' => [
                    'window_bookings' => $windowTotalBookings,
                    'paid_bookings' => $paidBookings,
                    'window_revenue' => $windowRevenue,
                    'avg_ticket' => $avgTicket,
                    'done_bookings' => $doneBookings,
                    'confirmed_bookings' => $confirmedBookings,
                    'cancelled_bookings' => $cancelledBookings,
                    'no_show_bookings' => $noShowBookings,
                    'completion_rate' => $completionRate,
                    'cancellation_rate' => $cancellationRate,
                    'no_show_rate' => $noShowRate,
                ],
                'currency' => 'AMD',
            ],
        ]);
    }

    public function revenue(Request $request)
    {
        $business = $this->getBusiness($request);
        $months = max(1, min(36, (int) ($request->query('months') ?? 12)));

        $toUtc = Carbon::now('UTC')->endOfDay();
        $fromUtc = Carbon::now('UTC')->subMonths($months - 1)->startOfMonth();

        $bookings = $this->revenueBookingBase($request, $business)
            ->whereBetween('starts_at', [$fromUtc, $toUtc])
            ->get(['starts_at', 'final_price']);

        $monthlyData = [];
        $cursor = $fromUtc->copy()->startOfMonth();
        for ($i = 0; $i < $months; $i++) {
            $ym = $cursor->format('Y-m');
            $monthlyData[$ym] = ['revenue' => 0.0, 'bookings' => 0];
            $cursor->addMonth();
        }

        foreach ($bookings as $booking) {
            $ym = $booking->starts_at->format('Y-m');
            if (isset($monthlyData[$ym])) {
                $monthlyData[$ym]['revenue'] += (float) ($booking->final_price ?? 0);
                $monthlyData[$ym]['bookings']++;
            }
        }

        $result = [];
        foreach ($monthlyData as $ym => $data) {
            $result[] = [
                'year_month' => $ym,
                'revenue' => (float) $data['revenue'],
                'bookings' => (int) $data['bookings'],
            ];
        }

        return response()->json([
            'data' => [
                'months' => $result,
                'currency' => 'AMD',
            ],
        ]);
    }

    public function services(Request $request)
    {
        $business = $this->getBusiness($request);
        $days = max(7, min(365, (int) ($request->query('days') ?? 28)));

        $toUtc = Carbon::now('UTC')->endOfDay();
        $fromUtc = Carbon::now('UTC')->subDays($days - 1)->startOfDay();

        $bookings = $this->revenueBookingBase($request, $business)
            ->whereBetween('starts_at', [$fromUtc, $toUtc])
            ->get(['service_id', 'final_price']);

        $agg = [];
        foreach ($bookings as $booking) {
            $serviceId = (int) $booking->service_id;
            if ($serviceId <= 0) {
                continue;
            }

            if (!isset($agg[$serviceId])) {
                $agg[$serviceId] = ['bookings' => 0, 'revenue' => 0.0];
            }

            $agg[$serviceId]['bookings']++;
            $agg[$serviceId]['revenue'] += (float) ($booking->final_price ?? 0);
        }

        $serviceIds = array_keys($agg);
        $servicesMap = Service::query()
            ->whereIn('id', $serviceIds)
            ->get(['id', 'name'])
            ->keyBy('id');

        $rows = [];
        foreach ($agg as $serviceId => $data) {
            $rows[] = [
                'service_id' => (int) $serviceId,
                'service_name' => $servicesMap->get($serviceId)?->name ?? 'Deleted Service',
                'bookings' => (int) $data['bookings'],
                'revenue' => (float) $data['revenue'],
            ];
        }

        usort($rows, fn ($a, $b) => $b['bookings'] <=> $a['bookings']);

        return response()->json([
            'data' => [
                'top' => array_slice($rows, 0, 10),
                'currency' => 'AMD',
            ],
        ]);
    }

    public function staff(Request $request)
    {
        $business = $this->getBusiness($request);
        $days = max(7, min(365, (int) ($request->query('days') ?? 28)));

        $toUtc = Carbon::now('UTC')->endOfDay();
        $fromUtc = Carbon::now('UTC')->subDays($days - 1)->startOfDay();

        $bookings = $this->revenueBookingBase($request, $business)
            ->whereBetween('starts_at', [$fromUtc, $toUtc])
            ->whereNotNull('staff_id')
            ->get(['staff_id', 'final_price']);

        $agg = [];
        foreach ($bookings as $booking) {
            $staffId = (int) $booking->staff_id;
            if ($staffId <= 0) {
                continue;
            }

            if (!isset($agg[$staffId])) {
                $agg[$staffId] = ['bookings' => 0, 'revenue' => 0.0];
            }

            $agg[$staffId]['bookings']++;
            $agg[$staffId]['revenue'] += (float) ($booking->final_price ?? 0);
        }

        $staffMap = User::query()
            ->whereIn('id', array_keys($agg))
            ->get(['id', 'name'])
            ->keyBy('id');

        $rows = [];
        foreach ($agg as $staffId => $data) {
            $rows[] = [
                'staff_id' => (int) $staffId,
                'staff_name' => $staffMap->get($staffId)?->name ?? 'Deleted Staff',
                'bookings' => (int) $data['bookings'],
                'revenue' => (float) $data['revenue'],
            ];
        }

        usort($rows, fn ($a, $b) => $b['bookings'] <=> $a['bookings']);

        return response()->json([
            'data' => [
                'rows' => $rows,
                'currency' => 'AMD',
            ],
        ]);
    }

    public function sources(Request $request)
    {
        $business = $this->getBusiness($request);
        $days = max(7, min(365, (int) ($request->query('days') ?? 30)));

        $toUtc = Carbon::now('UTC')->endOfDay();
        $fromUtc = Carbon::now('UTC')->subDays($days - 1)->startOfDay();

        $rows = $this->bookingBase($request, $business)
            ->whereBetween('starts_at', [$fromUtc, $toUtc])
            ->get(['source', 'status', 'final_price'])
            ->groupBy(function ($row) {
                $source = trim((string) ($row->source ?? ''));
                return $source !== '' ? $source : 'unknown';
            })
            ->map(function ($rows, $source) {
                $bookings = $rows->count();
                $revenue = $rows->reduce(function ($carry, $row) {
                    return $carry + (in_array((string) $row->status, ['confirmed', 'done'], true) ? (float) ($row->final_price ?? 0) : 0.0);
                }, 0.0);

                return [
                    'source' => (string) $source,
                    'bookings' => (int) $bookings,
                    'revenue' => (float) $revenue,
                ];
            })
            ->sortByDesc('bookings')
            ->values();

        return response()->json([
            'data' => [
                'rows' => $rows,
                'currency' => 'AMD',
            ],
        ]);
    }

    public function clients(Request $request)
    {
        $business = $this->getBusiness($request);
        $days = max(7, min(365, (int) ($request->query('days') ?? 30)));
        $lostDays = max(30, min(365, (int) ($request->query('lost_days') ?? 60)));

        $nowUtc = Carbon::now('UTC')->endOfDay();
        $fromUtc = Carbon::now('UTC')->subDays($days - 1)->startOfDay();
        $thresholdUtc = Carbon::now('UTC')->subDays($lostDays)->startOfDay();

        $bookingsBase = $this->bookingBase($request, $business)
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('client_id');

        $allClientRows = (clone $bookingsBase)
            ->select(
                'client_id',
                DB::raw('MIN(starts_at) as first_booking_at'),
                DB::raw('MAX(starts_at) as last_booking_at'),
                DB::raw('COUNT(*) as total_bookings'),
                DB::raw('SUM(COALESCE(final_price,0)) as total_spent')
            )
            ->groupBy('client_id')
            ->get();

        $activeRows = (clone $bookingsBase)
            ->whereBetween('starts_at', [$fromUtc, $nowUtc])
            ->select('client_id', DB::raw('COUNT(*) as bookings'))
            ->groupBy('client_id')
            ->get()
            ->keyBy('client_id');

        $newClients = 0;
        $returningClients = 0;
        $lostClientIds = [];
        foreach ($allClientRows as $row) {
            $clientId = (int) $row->client_id;
            $firstAt = $row->first_booking_at ? Carbon::parse($row->first_booking_at, 'UTC') : null;
            $lastAt = $row->last_booking_at ? Carbon::parse($row->last_booking_at, 'UTC') : null;

            if ($activeRows->has($clientId)) {
                if ($firstAt && $firstAt->betweenIncluded($fromUtc, $nowUtc)) {
                    $newClients++;
                } elseif ($firstAt && $firstAt->lt($fromUtc)) {
                    $returningClients++;
                }
            }

            if ($lastAt && $lastAt->lt($thresholdUtc)) {
                $lostClientIds[] = $clientId;
            }
        }

        $lostClientIds = array_values(array_unique($lostClientIds));
        $lostClients = Client::query()
            ->where('business_id', $business->id)
            ->whereIn('id', $lostClientIds)
            ->get(['id', 'name', 'phone', 'group_name', 'is_vip', 'is_blacklisted'])
            ->keyBy('id');

        $lostRows = collect($allClientRows)
            ->filter(fn ($row) => in_array((int) $row->client_id, $lostClientIds, true))
            ->sortBy('last_booking_at')
            ->take(8)
            ->map(function ($row) use ($lostClients) {
                $client = $lostClients->get((int) $row->client_id);
                return [
                    'client_id' => (int) $row->client_id,
                    'name' => $client?->name ?? 'Unknown client',
                    'phone' => $client?->phone,
                    'group_name' => $client?->group_name,
                    'is_vip' => (bool) ($client?->is_vip ?? false),
                    'is_blacklisted' => (bool) ($client?->is_blacklisted ?? false),
                    'last_booking_at' => $row->last_booking_at,
                    'total_spent' => (float) ($row->total_spent ?? 0),
                    'total_bookings' => (int) ($row->total_bookings ?? 0),
                ];
            })
            ->values();

        $activeClients = $activeRows->count();
        $rebookingRate = $activeClients > 0 ? round(($returningClients / $activeClients) * 100, 1) : 0.0;

        $groupRows = Client::query()
            ->where('business_id', $business->id)
            ->whereNotNull('group_name')
            ->where('group_name', '!=', '')
            ->select('group_name', DB::raw('COUNT(*) as clients'))
            ->groupBy('group_name')
            ->orderByDesc('clients')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'group_name' => (string) $row->group_name,
                'clients' => (int) ($row->clients ?? 0),
            ])
            ->values();

        $vipClients = (int) Client::query()->where('business_id', $business->id)->where('is_vip', true)->count();
        $blacklistedClients = (int) Client::query()->where('business_id', $business->id)->where('is_blacklisted', true)->count();

        return response()->json([
            'data' => [
                'window_days' => $days,
                'lost_threshold_days' => $lostDays,
                'active_clients' => $activeClients,
                'new_clients' => $newClients,
                'returning_clients' => $returningClients,
                'lost_clients' => count($lostClientIds),
                'vip_clients' => $vipClients,
                'blacklisted_clients' => $blacklistedClients,
                'rebooking_rate' => $rebookingRate,
                'group_rows' => $groupRows,
                'lost_rows' => $lostRows,
                'status_rows' => [
                    ['status' => 'done', 'count' => (int) (clone $bookingsBase)->where('status', 'done')->count()],
                    ['status' => 'confirmed', 'count' => (int) (clone $bookingsBase)->where('status', 'confirmed')->count()],
                    ['status' => 'cancelled', 'count' => (int) (clone $this->bookingBase($request, $business))->where('status', 'cancelled')->whereBetween('starts_at', [$fromUtc, $nowUtc])->count()],
                    ['status' => 'no_show', 'count' => (int) (clone $this->bookingBase($request, $business))->where('status', 'no_show')->whereBetween('starts_at', [$fromUtc, $nowUtc])->count()],
                ],
            ],
        ]);
    }

    public function summary(Request $request)
    {
        $business = $this->getBusiness($request);

        $now = Carbon::now('UTC');
        $todayStart = $now->copy()->startOfDay();
        $todayEnd = $now->copy()->endOfDay();
        $monthStart = $now->copy()->startOfMonth();

        $base = $this->bookingBase($request, $business);

        $todayBookings = (clone $base)
            ->whereBetween('starts_at', [$todayStart, $todayEnd])
            ->count();

        $todayRevenue = (clone $this->revenueBookingBase($request, $business))
            ->whereBetween('starts_at', [$todayStart, $todayEnd])
            ->sum('final_price');

        $monthBookings = (clone $base)
            ->where('starts_at', '>=', $monthStart)
            ->count();

        $monthRevenue = (clone $this->revenueBookingBase($request, $business))
            ->where('starts_at', '>=', $monthStart)
            ->sum('final_price');

        $totalBookings = (clone $base)->count();
        $totalRevenue = (clone $this->revenueBookingBase($request, $business))->sum('final_price');

        $staffCount = User::query()
            ->where('business_id', $business->id)
            ->whereIn('role', [User::ROLE_STAFF, User::ROLE_MANAGER])
            ->where('is_active', true)
            ->count();

        $serviceCount = Service::query()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->count();

        return response()->json([
            'data' => [
                'business' => [
                    'id' => $business->id,
                    'name' => $business->name,
                    'type' => $business->business_type,
                ],
                'today' => [
                    'bookings' => $todayBookings,
                    'revenue' => (float) $todayRevenue,
                ],
                'this_month' => [
                    'bookings' => $monthBookings,
                    'revenue' => (float) $monthRevenue,
                ],
                'total' => [
                    'bookings' => $totalBookings,
                    'revenue' => (float) $totalRevenue,
                ],
                'counts' => [
                    'staff' => $staffCount,
                    'services' => $serviceCount,
                ],
                'currency' => 'AMD',
            ],
        ]);
    }
}
