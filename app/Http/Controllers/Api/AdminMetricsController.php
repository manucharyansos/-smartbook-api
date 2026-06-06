<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminMetricsController extends Controller
{
    public function mrr(Request $request)
    {
        // միայն super admin
        if ($request->user()->role !== 'super_admin') {
            abort(403);
        }

        $activeSubs = Subscription::query()
            ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
            ->where('subscriptions.status', 'active')
            ->groupBy('plans.currency')
            ->select(
                'plans.currency',
                DB::raw('COUNT(subscriptions.id) as active_count'),
                DB::raw('SUM(plans.price) as mrr')
            )
            ->get();

        return response()->json([
            'data' => [
                'mrr_by_currency' => $activeSubs,
            ]
        ]);
    }
}
