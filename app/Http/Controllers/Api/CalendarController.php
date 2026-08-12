<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CalendarController extends Controller
{
    /**
     * GET /api/calendar?from=2026-02-01&to=2026-02-28
     * (optional for super-admin) &business_id=123
     */
    public function index(Request $request)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to'   => ['required', 'date'],
            'business_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $q = Booking::query()
            ->with(['service', 'staff', 'business', 'location', 'items.service']);

        $business = null;
        if ($actor->isSuperAdmin()) {
            if (!empty($data['business_id'])) {
                $business = Business::query()->find((int) $data['business_id']);
                $q->where('business_id', (int) $data['business_id']);
            }
        } else {
            $business = $actor->business;
            // tenant enforce
            $q->where('business_id', $actor->business_id);

            // staff sees only own bookings
            if ($actor->role === User::ROLE_STAFF) {
                $q->where('staff_id', $actor->id);
            }
        }

        $tz = $business?->effectiveTimezone() ?? 'Asia/Yerevan';

        $fromLocal = Carbon::createFromFormat('Y-m-d', (string) $data['from'], $tz)->startOfDay();
        $toLocal = Carbon::createFromFormat('Y-m-d', (string) $data['to'], $tz)->endOfDay();
        $fromUtc = $fromLocal->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');
        $toUtc = $toLocal->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');

        $q->whereBetween('starts_at', [$fromUtc, $toUtc]);

        if (!empty($data['location_id'])) {
            $locationId = (int) $data['location_id'];
            $q->where(function ($filtered) use ($locationId) {
                $filtered->where('location_id', $locationId)
                    ->orWhere(function ($legacy) use ($locationId) {
                        $legacy->whereNull('location_id')
                            ->where(function ($legacyMatch) use ($locationId) {
                                $legacyMatch->whereHas('staff', fn ($staffQ) => $staffQ->where('location_id', $locationId))
                                    ->orWhereHas('service', fn ($serviceQ) => $serviceQ->where('location_id', $locationId))
                                    ->orWhereHas('items.service', fn ($itemServiceQ) => $itemServiceQ->where('location_id', $locationId));
                            });
                    });
            });
        }

        return response()->json([
            'data' => BookingResource::collection($q->orderBy('starts_at')->get()),
        ]);
    }
}
