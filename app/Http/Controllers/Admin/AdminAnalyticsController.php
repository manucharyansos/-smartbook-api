<?php
// app/Http/Controllers/Admin/AdminAnalyticsController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Business; // Միայն Business, Salon չկա
use App\Models\Subscription;
use App\Models\User;
use App\Support\BusinessVertical;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminAnalyticsController extends Controller
{
    private array $paidStatuses = ['confirmed', 'done'];

    public function dashboard(Request $request)
    {
        $validated = $request->validate([
            'period' => 'nullable|in:7_days,30_days,90_days,12_months,custom',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'business_id' => 'nullable|integer|exists:businesses,id',
        ]);

        $period = $validated['period'] ?? '30_days';
        $range = $this->getDateRange($period, $request);
        $start = $range['start'];
        $end = $range['end'];
        $prev = $this->getPreviousPeriod($start, $end);
        $businessId = $validated['business_id'] ?? null;

        $bookingsPeriod = Booking::query()->whereBetween('starts_at', [$start, $end]);
        $bookingsPrev = Booking::query()->whereBetween('starts_at', [$prev['start'], $prev['end']]);
        $paidPeriod = Booking::query()->whereIn('status', $this->paidStatuses)->whereBetween('starts_at', [$start, $end]);
        $paidPrev = Booking::query()->whereIn('status', $this->paidStatuses)->whereBetween('starts_at', [$prev['start'], $prev['end']]);

        if ($businessId) {
            $bookingsPeriod->where('business_id', $businessId);
            $bookingsPrev->where('business_id', $businessId);
            $paidPeriod->where('business_id', $businessId);
            $paidPrev->where('business_id', $businessId);
        }

        $businessesTotal = Business::count();
        $businessesActive = Business::where('status', 'active')->count();
        $businessesSuspended = Business::where('status', 'suspended')->count();
        $businessesPending = Business::where('status', 'pending')->count();
        $businessesNewPeriod = Business::whereBetween('created_at', [$start, $end])->count();
        $businessesNewPrev = Business::whereBetween('created_at', [$prev['start'], $prev['end']])->count();
        $businessesGrowth = $this->pctChange($businessesNewPrev, $businessesNewPeriod);

        $usersTotal = User::count();
        $usersOwners = User::where('role', User::ROLE_OWNER)->count();
        $usersManagers = User::where('role', User::ROLE_MANAGER)->count();
        $usersStaff = User::where('role', User::ROLE_STAFF)->count();
        $usersNewPeriod = User::whereBetween('created_at', [$start, $end])->count();
        $usersNewPrev = User::whereBetween('created_at', [$prev['start'], $prev['end']])->count();
        $usersGrowth = $this->pctChange($usersNewPrev, $usersNewPeriod);

        $bookingsPeriodTotal = (clone $bookingsPeriod)->count();
        $bookingsPrevTotal = (clone $bookingsPrev)->count();
        $bookingsTrend = $this->pctChange($bookingsPrevTotal, $bookingsPeriodTotal);
        $todayBookings = Booking::query()
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->whereDate('starts_at', today())
            ->count();

        $confirmedDonePeriod = (clone $bookingsPeriod)->whereIn('status', ['confirmed', 'done'])->count();
        $canceledPeriod = (clone $bookingsPeriod)->whereIn('status', ['canceled', 'cancelled'])->count();
        $noShowPeriod = (clone $bookingsPeriod)->where('status', 'no_show')->count();
        $periodDays = max(1, $end->diffInDays($start) + 1);

        $revenuePeriodTotal = (float) (clone $paidPeriod)->sum('final_price');
        $revenuePrevTotal = (float) (clone $paidPrev)->sum('final_price');
        $revenueTrend = $this->pctChange($revenuePrevTotal, $revenuePeriodTotal);
        $revenueToday = (float) Booking::query()
            ->whereIn('status', $this->paidStatuses)
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->whereDate('starts_at', today())
            ->sum('final_price');
        $revenueAllTime = (float) Booking::query()
            ->whereIn('status', $this->paidStatuses)
            ->when($businessId, fn($q) => $q->where('business_id', $businessId))
            ->sum('final_price');

        $activeBusinessesWithBookings = (clone $bookingsPeriod)->distinct('business_id')->count('business_id');
        $activeStaffTotal = User::where('role', User::ROLE_STAFF)->where('is_active', true)->count();

        $subscriptionStats = [
            'active' => Subscription::where('status', 'active')->count(),
            'trialing' => Subscription::where('status', 'trialing')->count(),
            'canceled' => Subscription::where('status', 'canceled')->count(),
            'mrr' => $this->calculateMRR(),
        ];
        $subscriptionStats['arr'] = $subscriptionStats['mrr'] * 12;
        $subscriptionStats['expiring_trials_7d'] = Subscription::where('status', 'trialing')
            ->whereBetween('trial_ends_at', [now(), now()->copy()->addDays(7)])
            ->count();
        $subscriptionStats['renewals_due_30d'] = Subscription::where('status', 'active')
            ->whereBetween('current_period_ends_at', [now(), now()->copy()->addDays(30)])
            ->count();

        $recentBusinesses = Business::withCount(['users', 'bookings'])
            ->latest()
            ->limit(10)
            ->get();

        $groupBy = $this->suggestGroupBy($period);
        $revenueSeries = $this->revenueSeries($start, $end, $groupBy, $businessId);
        $bookingsSeries = $this->bookingsSeries($start, $end, $groupBy, $businessId);
        $businessMix = $this->businessMix($start, $end, $businessId);
        $topSources = $this->topSources($start, $end, $businessId);
        $topBusinesses = $this->topBusinesses($start, $end, $businessId);

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'date_range' => [
                    'start' => $start->format('Y-m-d'),
                    'end' => $end->format('Y-m-d'),
                ],
                'businesses' => [
                    'total' => $businessesTotal,
                    'active' => $businessesActive,
                    'suspended' => $businessesSuspended,
                    'pending' => $businessesPending,
                    'new' => $businessesNewPeriod,
                    'growth' => $businessesGrowth,
                ],
                'users' => [
                    'total' => $usersTotal,
                    'owners' => $usersOwners,
                    'managers' => $usersManagers,
                    'staff' => $usersStaff,
                    'new' => $usersNewPeriod,
                    'growth' => $usersGrowth,
                ],
                'bookings' => [
                    'period_total' => $bookingsPeriodTotal,
                    'today' => $todayBookings,
                    'trend' => $bookingsTrend,
                    'completed' => $confirmedDonePeriod,
                    'canceled' => $canceledPeriod,
                    'no_show' => $noShowPeriod,
                    'average_daily' => round($bookingsPeriodTotal / $periodDays, 1),
                    'completion_rate' => $this->safeRate($confirmedDonePeriod, $bookingsPeriodTotal),
                    'cancellation_rate' => $this->safeRate($canceledPeriod, $bookingsPeriodTotal),
                    'no_show_rate' => $this->safeRate($noShowPeriod, $bookingsPeriodTotal),
                ],
                'revenue' => [
                    'period_total' => $revenuePeriodTotal,
                    'today' => $revenueToday,
                    'all_time_total' => $revenueAllTime,
                    'trend' => $revenueTrend,
                    'average_booking_value' => round($revenuePeriodTotal / max(1, $confirmedDonePeriod), 1),
                    'average_business_revenue' => round($revenuePeriodTotal / max(1, $activeBusinessesWithBookings), 1),
                ],
                'subscriptions' => $subscriptionStats,
                'operations' => [
                    'active_businesses_with_bookings' => $activeBusinessesWithBookings,
                    'avg_staff_per_business' => round($activeStaffTotal / max(1, $businessesActive ?: $businessesTotal), 1),
                    'avg_bookings_per_active_business' => round($bookingsPeriodTotal / max(1, $activeBusinessesWithBookings), 1),
                ],
                'recent_businesses' => $recentBusinesses,
                'top_businesses' => $topBusinesses,
                'business_mix' => $businessMix,
                'top_sources' => $topSources,
                'charts' => [
                    'group_by' => $groupBy,
                    'revenue' => $revenueSeries,
                    'bookings' => $bookingsSeries,
                ],
                'currency' => 'AMD',
            ],
        ]);
    }

    public function businesses(Request $request) // Փոխել salons-ից businesses
    {
        $validated = $request->validate([
            'status' => 'nullable|in:active,suspended,pending',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'search' => 'nullable|string|max:100',
            'sort_by' => 'nullable|string',
            'sort_order' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Business::query() // Business, ոչ թե Salon
        ->withCount(['users', 'bookings'])
            ->withSum('bookings as total_revenue', 'final_price');

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['from']) && !empty($validated['to'])) {
            $query->whereBetween('created_at', [$validated['from'], $validated['to']]);
        }

        if (!empty($validated['search'])) {
            $s = $validated['search'];
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('slug', 'like', "%{$s}%");
            });
        }

        $allowedSort = ['created_at', 'name', 'status', 'users_count', 'bookings_count', 'total_revenue'];
        $sortBy = $validated['sort_by'] ?? 'created_at';
        if (!in_array($sortBy, $allowedSort, true)) $sortBy = 'created_at';
        $sortOrder = $validated['sort_order'] ?? 'desc';

        $query->orderBy($sortBy, $sortOrder);

        $businesses = $query->paginate($validated['per_page'] ?? 20);

        return response()->json(['success' => true, 'data' => $businesses]);
    }

    public function revenue(Request $request)
    {
        $validated = $request->validate([
            'group_by' => 'nullable|in:day,week,month',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'business_id' => 'nullable|integer|exists:businesses,id', // Փոխել salon_id-ից business_id
        ]);

        $groupBy = $validated['group_by'] ?? 'month';

        $from = !empty($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : now()->subMonths(12)->startOfDay();
        $to = !empty($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : now()->endOfDay();
        $businessId = $validated['business_id'] ?? null;

        $items = $this->revenueSeries($from, $to, $groupBy, $businessId);

        return response()->json([
            'success' => true,
            'data' => [
                'group_by' => $groupBy,
                'items' => $items,
                'currency' => 'AMD',
            ],
        ]);
    }

    // ------------------------
    // Helpers
    // ------------------------
    private function getDateRange(string $period, Request $request): array
    {
        $now = Carbon::now();

        return match ($period) {
            '7_days' => [
                'start' => $now->copy()->subDays(7)->startOfDay(),
                'end' => $now->copy()->endOfDay(),
            ],
            '30_days' => [
                'start' => $now->copy()->subDays(30)->startOfDay(),
                'end' => $now->copy()->endOfDay(),
            ],
            '90_days' => [
                'start' => $now->copy()->subDays(90)->startOfDay(),
                'end' => $now->copy()->endOfDay(),
            ],
            '12_months' => [
                'start' => $now->copy()->subMonths(12)->startOfDay(),
                'end' => $now->copy()->endOfDay(),
            ],
            'custom' => [
                'start' => Carbon::parse($request->get('from', now()->subDays(30)))->startOfDay(),
                'end' => Carbon::parse($request->get('to', now()))->endOfDay(),
            ],
            default => [
                'start' => $now->copy()->subDays(30)->startOfDay(),
                'end' => $now->copy()->endOfDay(),
            ],
        };
    }

    private function getPreviousPeriod(Carbon $start, Carbon $end): array
    {
        $days = max(1, $end->diffInDays($start));
        return [
            'start' => $start->copy()->subDays($days)->startOfDay(),
            'end' => $start->copy()->subSecond(),
        ];
    }

    private function pctChange(float|int $previous, float|int $current): float
    {
        $previous = (float) $previous;
        $current = (float) $current;
        if ($previous <= 0) return 0;
        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function suggestGroupBy(string $period): string
    {
        return match ($period) {
            '7_days' => 'day',
            '30_days' => 'day',
            '90_days' => 'week',
            '12_months' => 'month',
            default => 'day',
        };
    }

    private function revenueSeries(Carbon $from, Carbon $to, string $groupBy, ?int $businessId = null): array
    {
        $q = Booking::query()
            ->whereIn('status', $this->paidStatuses)
            ->whereBetween('starts_at', [$from, $to])
            ->when($businessId, fn($qq) => $qq->where('business_id', $businessId)); // Փոխել salon_id-ից business_id

        if ($groupBy === 'day') {
            return $q->select(
                DB::raw('DATE(starts_at) as period'),
                DB::raw('COUNT(*) as bookings'),
                DB::raw('SUM(final_price) as revenue')
            )
                ->groupBy('period')
                ->orderBy('period')
                ->get()
                ->map(fn($x) => [
                    'period' => (string) $x->period,
                    'bookings' => (int) $x->bookings,
                    'revenue' => (float) $x->revenue,
                ])->all();
        }

        if ($groupBy === 'week') {
            return $q->select(
                DB::raw('YEAR(starts_at) as y'),
                DB::raw('WEEK(starts_at, 3) as w'),
                DB::raw('COUNT(*) as bookings'),
                DB::raw('SUM(final_price) as revenue')
            )
                ->groupBy('y', 'w')
                ->orderBy('y')
                ->orderBy('w')
                ->get()
                ->map(fn($x) => [
                    'period' => "{$x->y}-W{$x->w}",
                    'bookings' => (int) $x->bookings,
                    'revenue' => (float) $x->revenue,
                ])->all();
        }

        // month
        return $q->select(
            DB::raw('DATE_FORMAT(starts_at, "%Y-%m") as period'),
            DB::raw('COUNT(*) as bookings'),
            DB::raw('SUM(final_price) as revenue')
        )
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn($x) => [
                'period' => (string) $x->period,
                'bookings' => (int) $x->bookings,
                'revenue' => (float) $x->revenue,
            ])->all();
    }

    private function bookingsSeries(Carbon $from, Carbon $to, string $groupBy, ?int $businessId = null): array
    {
        $q = Booking::query()
            ->whereBetween('starts_at', [$from, $to])
            ->when($businessId, fn($qq) => $qq->where('business_id', $businessId)); // Փոխել salon_id-ից business_id

        if ($groupBy === 'day') {
            return $q->select(
                DB::raw('DATE(starts_at) as period'),
                DB::raw('COUNT(*) as bookings')
            )
                ->groupBy('period')
                ->orderBy('period')
                ->get()
                ->map(fn($x) => [
                    'period' => (string) $x->period,
                    'bookings' => (int) $x->bookings,
                ])->all();
        }

        if ($groupBy === 'week') {
            return $q->select(
                DB::raw('YEAR(starts_at) as y'),
                DB::raw('WEEK(starts_at, 3) as w'),
                DB::raw('COUNT(*) as bookings')
            )
                ->groupBy('y', 'w')
                ->orderBy('y')
                ->orderBy('w')
                ->get()
                ->map(fn($x) => [
                    'period' => "{$x->y}-W{$x->w}",
                    'bookings' => (int) $x->bookings,
                ])->all();
        }

        // month
        return $q->select(
            DB::raw('DATE_FORMAT(starts_at, "%Y-%m") as period'),
            DB::raw('COUNT(*) as bookings')
        )
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn($x) => [
                'period' => (string) $x->period,
                'bookings' => (int) $x->bookings,
            ])->all();
    }

    private function calculateMRR(): float
    {
        return (float) Subscription::where('status', 'active')
            ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
            ->sum(DB::raw('COALESCE(plans.monthly_price, plans.price, plans.price_beauty, plans.price_dental, 0)'));
    }

    private function safeRate(int|float $value, int|float $total): float
    {
        $value = (float) $value;
        $total = (float) $total;
        if ($total <= 0) return 0.0;
        return round(($value / $total) * 100, 1);
    }

    private function businessMix(Carbon $start, Carbon $end, ?int $businessId = null): array
    {
        $businessTypeExpression = "CASE WHEN COALESCE(NULLIF(vertical, ''), business_type) IN ('healthcare', 'medical', 'clinic', 'dental', 'doctor', 'health') THEN 'healthcare' ELSE 'services' END";
        $bookingBusinessTypeExpression = "CASE WHEN COALESCE(NULLIF(businesses.vertical, ''), businesses.business_type) IN ('healthcare', 'medical', 'clinic', 'dental', 'doctor', 'health') THEN 'healthcare' ELSE 'services' END";

        $businessRows = Business::query()
            ->selectRaw("{$businessTypeExpression} as business_type, COUNT(*) as total, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active")
            ->when($businessId, fn($q) => $q->where('id', $businessId))
            ->groupByRaw($businessTypeExpression)
            ->get()
            ->keyBy('business_type');

        $revenueRows = Booking::query()
            ->join('businesses', 'businesses.id', '=', 'bookings.business_id')
            ->whereIn('bookings.status', $this->paidStatuses)
            ->whereBetween('bookings.starts_at', [$start, $end])
            ->when($businessId, fn($q) => $q->where('bookings.business_id', $businessId))
            ->selectRaw("{$bookingBusinessTypeExpression} as business_type, COUNT(bookings.id) as bookings_count, SUM(bookings.final_price) as revenue")
            ->groupByRaw($bookingBusinessTypeExpression)
            ->get()
            ->keyBy('business_type');

        return collect($businessRows->keys()->merge($revenueRows->keys())->unique()->values())
            ->map(function ($type) use ($businessRows, $revenueRows) {
                $business = $businessRows->get($type);
                $revenue = $revenueRows->get($type);
                return [
                    'business_type' => (string) $type,
                    'total' => (int) ($business->total ?? 0),
                    'active' => (int) ($business->active ?? 0),
                    'bookings' => (int) ($revenue->bookings_count ?? 0),
                    'revenue' => (float) ($revenue->revenue ?? 0),
                ];
            })
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    private function topSources(Carbon $start, Carbon $end, ?int $businessId = null): array
    {
        $base = DB::table('bookings')
            ->selectRaw('COALESCE(NULLIF(source, ""), "unknown") as booking_source, final_price, status, business_id')
            ->whereBetween('starts_at', [$start, $end]);

        if ($businessId) {
            $base->where('business_id', $businessId);
        }

        return DB::query()
            ->fromSub($base, 'booking_sources')
            ->selectRaw('booking_source, COUNT(*) as total, SUM(CASE WHEN status IN ("confirmed", "done") THEN final_price ELSE 0 END) as revenue')
            ->groupBy('booking_source')
            ->orderByDesc('total')
            ->limit(6)
            ->get()
            ->map(fn ($row) => [
                'source' => (string) $row->booking_source,
                'total' => (int) $row->total,
                'revenue' => (float) $row->revenue,
            ])
            ->all();
    }

    private function topBusinesses(Carbon $start, Carbon $end, ?int $businessId = null): array
    {
        return Business::query()
            ->when($businessId, fn($q) => $q->where('id', $businessId))
            ->withCount([
                'bookings as period_bookings_count' => fn ($q) => $q->whereBetween('starts_at', [$start, $end]),
                'users as active_staff_count' => fn ($q) => $q->where('role', User::ROLE_STAFF)->where('is_active', true),
            ])
            ->withSum([
                'bookings as period_revenue' => fn ($q) => $q->whereBetween('starts_at', [$start, $end])->whereIn('status', $this->paidStatuses),
            ], 'final_price')
            ->orderByDesc('period_revenue')
            ->orderByDesc('period_bookings_count')
            ->limit(6)
            ->get(['id', 'name', 'slug', 'business_type', 'status'])
            ->map(fn (Business $business) => [
                'id' => $business->id,
                'name' => $business->name,
                'slug' => $business->slug,
                'business_type' => (string) ($business->business_type ?? 'other'),
                'status' => (string) $business->status,
                'bookings_count' => (int) ($business->period_bookings_count ?? 0),
                'active_staff_count' => (int) ($business->active_staff_count ?? 0),
                'revenue' => (float) ($business->period_revenue ?? 0),
            ])
            ->all();
    }

    public function exportBusinesses(Request $request) // Փոխել exportSalons-ից exportBusinesses
    {
        $validated = $request->validate([
            'status' => 'nullable|in:active,suspended,pending',
            'business_type' => 'nullable|in:services,healthcare,beauty,dental,salon,clinic',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'search' => 'nullable|string|max:100',
            'sort_by' => 'nullable|string',
            'sort_order' => 'nullable|in:asc,desc',
        ]);

        $query = Business::query() // Business, ոչ թե Salon
        ->withCount(['users', 'bookings'])
            ->withSum('bookings as total_revenue', 'final_price');

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['business_type'])) {
            $vertical = BusinessVertical::normalize($validated['business_type']);
            $legacyTypes = $vertical === BusinessVertical::HEALTHCARE
                ? ['healthcare', 'dental', 'clinic']
                : ['services', 'beauty', 'salon'];
            $query->where(function ($builder) use ($vertical, $legacyTypes) {
                $builder->where('vertical', $vertical)
                    ->orWhere(function ($legacyQuery) use ($legacyTypes) {
                        $legacyQuery->where(function ($emptyVertical) {
                            $emptyVertical->whereNull('vertical')->orWhere('vertical', '');
                        })->whereIn('business_type', $legacyTypes);
                    });
            });
        }

        if (!empty($validated['from']) && !empty($validated['to'])) {
            $query->whereBetween('created_at', [$validated['from'], $validated['to']]);
        }

        if (!empty($validated['search'])) {
            $s = $validated['search'];
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('slug', 'like', "%{$s}%");
            });
        }

        $allowedSort = ['created_at', 'name', 'status', 'users_count', 'bookings_count', 'total_revenue'];
        $sortBy = $validated['sort_by'] ?? 'created_at';
        if (!in_array($sortBy, $allowedSort, true)) $sortBy = 'created_at';
        $sortOrder = $validated['sort_order'] ?? 'desc';
        $query->orderBy($sortBy, $sortOrder);

        $filename = 'businesses_export_' . now()->format('Y-m-d_His') . '.csv'; // Փոխել salons_export-ից businesses_export

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($out, [
                'ID',
                'Անուն',
                'Slug',
                'Տիպ', // Ավելացնենք business_type
                'Կարգավիճակ',
                'Օգտատերեր',
                'Ամրագրումներ',
                'Եկամուտ',
                'Ստեղծման ամսաթիվ',
            ]);

            $query->chunk(500, function ($businesses) use ($out) {
                foreach ($businesses as $business) {
                    fputcsv($out, [
                        $business->id,
                        $business->name,
                        $business->slug,
                        $business->business_type ?? 'services', // beauty/dental
                        $business->status,
                        $business->users_count ?? 0,
                        $business->bookings_count ?? 0,
                        $business->total_revenue ?? 0,
                        optional($business->created_at)->format('Y-m-d H:i'),
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportRevenue(Request $request)
    {
        $validated = $request->validate([
            'period' => 'nullable|in:7_days,30_days,90_days,12_months,custom',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'group_by' => 'nullable|in:day,week,month',
            'business_id' => 'nullable|integer|exists:businesses,id', // Փոխել salon_id-ից business_id
        ]);

        $period = $validated['period'] ?? null;

        if ($period) {
            $range = $this->getDateRange($period, $request);
            $from = $range['start'];
            $to = $range['end'];
            $groupBy = $validated['group_by'] ?? $this->suggestGroupBy($period);
        } else {
            $from = !empty($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : now()->subMonths(12)->startOfDay();
            $to = !empty($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : now()->endOfDay();
            $groupBy = $validated['group_by'] ?? 'month';
        }

        $businessId = $validated['business_id'] ?? null;

        $items = $this->revenueSeries($from, $to, $groupBy, $businessId);

        $filename = 'revenue_export_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($items) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($out, ['Ժամանակահատված', 'Ամրագրումներ', 'Եկամուտ (AMD)']);

            foreach ($items as $row) {
                fputcsv($out, [
                    $row['period'],
                    $row['bookings'],
                    $row['revenue'],
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
