<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'from' => ['required','date_format:Y-m-d'],
            'to' => ['required','date_format:Y-m-d'],
        ]);

        $user = $request->user();

        $from = Carbon::parse($data['from'])->startOfDay();
        $to   = Carbon::parse($data['to'])->endOfDay();

        $query = Booking::query()
            ->whereBetween('starts_at', [$from, $to]);

        // staff-ը տեսնում է միայն իր stats-ը
        if ($user->role === User::ROLE_STAFF) {
            $query->where('staff_id', $user->id);
        }

        $total = $query->count();
        $confirmed = (clone $query)->where('status', 'confirmed')->count();
        $cancelled = (clone $query)->where('status', 'cancelled')->count();
        $done = (clone $query)->where('status', 'done')->count();

        // Revenue = sum(service price) for done bookings
        $revenue = (clone $query)
            ->where('status', 'done')
            ->join('services', 'bookings.service_id', '=', 'services.id')
            ->sum('services.price');

        // grouped by day
        $byDay = (clone $query)
            ->select(
                DB::raw('DATE(starts_at) as day'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('day')
            ->pluck('count', 'day');

        return response()->json([
            'data' => [
                'total_bookings' => $total,
                'confirmed' => $confirmed,
                'cancelled' => $cancelled,
                'done' => $done,
                'revenue' => $revenue,
                'by_day' => $byDay,
            ]
        ]);
    }
}
