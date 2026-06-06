<?php
// app/Http/Controllers/Api/RoomController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoomController extends Controller
{
    public function index(Request $request)
    {
        $business = $request->user()->business;

        // Միայն clinic-ի համար
        if (!$business->isDental()) {
            return response()->json(['message' => 'Rooms are only available for clinic clinics'], 403);
        }

        $rooms = $business->rooms()
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $rooms]);
    }

    public function store(Request $request)
    {
        $business = $request->user()->business;

        if (!$business->isDental()) {
            return response()->json(['message' => 'Rooms are only available for clinic clinics'], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['room', 'chair', 'surgery'])],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:10'],
            'equipment' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ]);

        $data['business_id'] = $business->id;

        $room = Room::create($data);

        return response()->json(['data' => $room], 201);
    }

    public function update(Request $request, Room $room)
    {
        $business = $request->user()->business;

        if ($room->business_id !== $business->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(['room', 'chair', 'surgery'])],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:10'],
            'equipment' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $room->update($data);

        return response()->json(['data' => $room]);
    }

    public function destroy(Request $request, Room $room)
    {
        $business = $request->user()->business;

        if ($room->business_id !== $business->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Ստուգել, որ room-ը չունի ապագա bookings
        $hasFutureBookings = $room->bookings()
            ->where('starts_at', '>', now())
            ->whereIn('status', ['pending', 'confirmed'])
            ->exists();

        if ($hasFutureBookings) {
            return response()->json([
                'message' => 'Cannot delete room with future bookings. Deactivate it instead.'
            ], 422);
        }

        $room->delete();

        return response()->json(['ok' => true]);
    }
}
