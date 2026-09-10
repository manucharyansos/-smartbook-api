<?php
// app/Http/Controllers/Api/AvailabilityController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Service;
use App\Services\AvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AvailabilityController extends Controller
{
    protected AvailabilityService $availabilityService;

    public function __construct(AvailabilityService $availabilityService)
    {
        $this->availabilityService = $availabilityService;
    }

    public function availability(Request $request)
    {
        $request->validate([
            'service_id' => 'nullable|integer|exists:services,id',
            'service_ids' => 'nullable|array|min:1',
            'service_ids.*' => 'integer|exists:services,id',
            'staff_id' => 'nullable|integer|exists:users,id',
            'location_id' => 'nullable|integer',
            'date' => 'required|date_format:Y-m-d',
            'party_size' => 'nullable|integer|min:1|max:500',
        ]);

        $serviceIds = [];
        if ($request->filled('service_id')) {
            $serviceIds[] = (int) $request->integer('service_id');
        }
        if (is_array($request->input('service_ids'))) {
            $serviceIds = array_merge($serviceIds, array_map('intval', $request->input('service_ids', [])));
        }
        $serviceIds = array_values(array_unique(array_filter($serviceIds, fn ($id) => $id > 0)));

        if (!$serviceIds) {
            throw ValidationException::withMessages([
                'service_id' => ['At least one service is required.'],
            ]);
        }

        $service = Service::findOrFail($serviceIds[0]);
        $businessId = (int) $service->business_id;
        $date = $request->string('date')->toString();
        $staffId = $request->filled('staff_id') ? (int) $request->integer('staff_id') : null;

        $slots = $this->availabilityService->slotsForSelection(
            serviceIds: $serviceIds,
            date: $date,
            businessId: $businessId,
            staffId: $staffId,
            locationId: $request->filled('location_id') ? (int) $request->integer('location_id') : null,
            partySize: max(1, (int) $request->integer('party_size', 1)),
        );

        // The authenticated business booking endpoint treats every non-cancelled
        // booking as a conflict. Apply the same rule here so the admin/mobile UI
        // never advertises a slot that POST /bookings will immediately reject.
        // Public/customer availability intentionally keeps its separate, more
        // permissive verification-expiry rules in PublicBookingController.
        if ($slots) {
            $business = Business::query()->find($businessId);
            $timezone = $business?->effectiveTimezone() ?? 'Asia/Yerevan';
            $dayStart = Carbon::createFromFormat('Y-m-d', $date, $timezone)->startOfDay();
            $dayEnd = $dayStart->copy()->addDay();
            $dayStartUtc = $dayStart->copy()->setTimezone('UTC');
            $dayEndUtc = $dayEnd->copy()->setTimezone('UTC');

            $conflicts = Booking::query()
                ->where('business_id', $businessId)
                ->when($staffId, fn ($query) => $query->where('staff_id', $staffId))
                ->where('status', '!=', 'cancelled')
                ->whereNotNull('starts_at')
                ->whereNotNull('ends_at')
                ->where('starts_at', '<', $dayEndUtc->format('Y-m-d H:i:s'))
                ->where('ends_at', '>', $dayStartUtc->format('Y-m-d H:i:s'))
                ->get(['staff_id', 'starts_at', 'ends_at']);

            $slots = array_values(array_filter($slots, function (array $slot) use ($conflicts, $timezone) {
                try {
                    $slotStart = Carbon::createFromFormat('Y-m-d H:i:s', (string) ($slot['starts_at'] ?? ''), $timezone)
                        ->setTimezone('UTC');
                    $slotEnd = Carbon::createFromFormat('Y-m-d H:i:s', (string) ($slot['ends_at'] ?? ''), $timezone)
                        ->setTimezone('UTC');
                } catch (\Throwable) {
                    return false;
                }

                $slotStaffId = (int) ($slot['staff_id'] ?? 0);
                foreach ($conflicts as $booking) {
                    if ((int) $booking->staff_id !== $slotStaffId) {
                        continue;
                    }

                    $bookingStart = $booking->starts_at instanceof Carbon
                        ? $booking->starts_at->copy()->setTimezone('UTC')
                        : Carbon::parse($booking->starts_at, 'UTC');
                    $bookingEnd = $booking->ends_at instanceof Carbon
                        ? $booking->ends_at->copy()->setTimezone('UTC')
                        : Carbon::parse($booking->ends_at, 'UTC');

                    if ($bookingStart->lt($slotEnd) && $bookingEnd->gt($slotStart)) {
                        return false;
                    }
                }

                return true;
            }));
        }

        return response()->json($slots)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }
}
