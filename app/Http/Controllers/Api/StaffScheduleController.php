<?php
// app/Http/Controllers/Api/StaffScheduleController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StaffWorkSchedule;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffScheduleController extends Controller
{
    public function show(User $staff)
    {
        if ($staff->business_id !== auth()->user()->business_id) { // Փոխել salon_id-ից business_id
            abort(404);
        }

        $items = StaffWorkSchedule::where('staff_id', $staff->id)
            ->orderBy('day_of_week')
            ->get(['day_of_week','starts_at','ends_at']);

        return response()->json(['data' => $items]);
    }

    public function update(Request $request, User $staff)
    {
        if ($staff->business_id !== auth()->user()->business_id) { // Փոխել salon_id-ից business_id
            abort(404);
        }

        $data = $request->validate([
            'schedule' => ['required','array','min:1'],
            'schedule.*.day_of_week' => ['required','integer', 'between:0,6'],
            'schedule.*.starts_at' => ['required','date_format:H:i'],
            'schedule.*.ends_at' => ['required','date_format:H:i'],
        ]);

        \DB::transaction(function () use ($staff, $data) {
            StaffWorkSchedule::where('staff_id', $staff->id)->delete();

            foreach ($data['schedule'] as $row) {
                if ($row['starts_at'] >= $row['ends_at']) {
                    abort(422, 'starts_at must be before ends_at');
                }

                StaffWorkSchedule::create([
                    'business_id' => $staff->business_id, // Ավելացնել business_id
                    'staff_id' => $staff->id,
                    'day_of_week' => (int)$row['day_of_week'],
                    'starts_at' => $row['starts_at'],
                    'ends_at' => $row['ends_at'],
                ]);
            }
        });

        return response()->json(['ok' => true]);
    }
}
