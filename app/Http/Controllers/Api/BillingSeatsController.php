<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\Request;

class BillingSeatsController extends Controller
{
    public function show(Request $request)
    {
        $business = Business::query()
            ->with(['subscription.plan'])
            ->findOrFail($request->user()->business_id);

        $limit = $business->seatLimit();

        $activeUsers = $business->seatUsers()
            ->orderByRaw("FIELD(role,'owner','manager','staff')")
            ->orderBy('id')
            ->get(['id','name','email','role','is_active']);

        return response()->json([
            'data' => [
                'seat_limit' => $limit,
                'active_seat_count' => $activeUsers->count(),
                'users' => $activeUsers,
            ],
        ]);
    }
}
