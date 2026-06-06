<?php
// app/Http/Controllers/Api/AvailabilityController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\AvailabilityService;
use Illuminate\Http\Request;
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
        );

        return response()->json($slots);
    }
}
