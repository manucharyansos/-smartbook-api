<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Business;
use App\Models\SiteEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SiteAnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'in:1,7,30,90,365']]);
        $days = (int) ($data['days'] ?? 30);
        $from = now()->subDays($days - 1)->startOfDay();
        $to = now();
        $base = fn () => SiteEvent::query()->whereBetween('created_at', [$from, $to]);
        $views = fn () => $base()->whereIn('event', ['page_view', 'screen_view']);

        // Visitors rotate daily, so period visitors are the sum of daily unique visitors.
        $daily = $base()->selectRaw('DATE(created_at) as day, COUNT(DISTINCT visitor) as visitors')
            ->groupByRaw('DATE(created_at)')->pluck('visitors', 'day');
        $dailyViews = $views()->selectRaw('DATE(created_at) as day, COUNT(*) as views')->groupByRaw('DATE(created_at)')->pluck('views', 'day');
        $series = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $series[] = ['date' => $key, 'visitors' => (int) ($daily[$key] ?? 0), 'views' => (int) ($dailyViews[$key] ?? 0)];
        }

        $group = fn ($query, string $column, int $limit = 10) => $query->selectRaw("{$column} as label, COUNT(*) as total, COUNT(DISTINCT visitor) as visitors")
            ->groupBy($column)->orderByDesc('total')->limit($limit)->get()
            ->map(fn ($row) => ['label' => $row->label, 'total' => (int) $row->total, 'visitors' => (int) $row->visitors])->values();

        $businessRows = $base()->whereNotNull('business_id')->whereIn('event', ['page_view', 'screen_view', 'business_view'])
            ->selectRaw('business_id, COUNT(*) as total, COUNT(DISTINCT visitor) as visitors')->groupBy('business_id')
            ->orderByDesc('total')->limit(10)->get();
        $names = Business::query()->whereIn('id', $businessRows->pluck('business_id'))->get(['id', 'name', 'slug'])->keyBy('id');

        $bookings = Booking::query()->withoutGlobalScopes()->whereBetween('created_at', [$from, $to]);

        return response()->json(['data' => [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'totals' => [
                'views' => $views()->count(),
                'visitors' => (int) $daily->sum(),
                'today_visitors' => (int) ($daily[now()->toDateString()] ?? 0),
                'app_opens' => $base()->where('event', 'app_open')->count(),
                'bookings_created' => (clone $bookings)->count(),
                'online_bookings' => (clone $bookings)->whereIn('source', ['website', 'mobile', 'widget', 'marketplace'])->count(),
            ],
            'series' => $series,
            'events' => $base()->selectRaw('event, COUNT(*) as total')->groupBy('event')->pluck('total', 'event'),
            // Owners' workspace (/app) is excluded so the list shows what visitors look at.
            'top_pages' => $group($views()->whereNull('business_id')->where('path', 'not like', '/app%'), 'path', 15),
            'workspace_views' => $views()->where('path', 'like', '/app%')->count(),
            'top_businesses' => $businessRows->map(fn ($row) => [
                'id' => (int) $row->business_id, 'name' => $names[$row->business_id]->name ?? '—', 'slug' => $names[$row->business_id]->slug ?? null,
                'total' => (int) $row->total, 'visitors' => (int) $row->visitors,
            ])->values(),
            'referrers' => $group($views()->whereNotNull('referrer_host'), 'referrer_host'),
            'campaigns' => $group($views()->whereNotNull('utm_source'), 'utm_source'),
            'devices' => $group($base(), 'device', 5),
            'sources' => $group($base(), 'source', 5),
        ]]);
    }
}
