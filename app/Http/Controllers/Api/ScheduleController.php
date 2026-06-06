<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduleController extends Controller
{
    /* =========================================================
     * BUSINESS WEEKLY SCHEDULE
     * ========================================================= */

    public function show(Request $request)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        $data = DB::table('business_working_hours')
            ->where('business_id', $actor->business_id)
            ->orderBy('weekday')
            ->get()
            ->map(function ($row) {
                $row->start = $this->normalizeTimeValue($row->start ?? null);
                $row->end = $this->normalizeTimeValue($row->end ?? null);
                $row->break_start = $this->normalizeTimeValue($row->break_start ?? null);
                $row->break_end = $this->normalizeTimeValue($row->break_end ?? null);
                return $row;
            })->values();

        return response()->json(['data' => $data]);
    }

    public function update(Request $request)
    {
        $request->merge([
            'days' => collect((array) $request->input('days', []))
                ->map(function ($day) {
                    if (!is_array($day)) {
                        return $day;
                    }

                    $day['start'] = $this->normalizeTimeValue($day['start'] ?? null);
                    $day['end'] = $this->normalizeTimeValue($day['end'] ?? null);
                    $day['break_start'] = $this->normalizeTimeValue($day['break_start'] ?? null);
                    $day['break_end'] = $this->normalizeTimeValue($day['break_end'] ?? null);

                    return $day;
                })
                ->all(),
        ]);

        $actor = $request->user();
        if (!$actor) abort(401);

        if (!in_array($actor->role, [User::ROLE_OWNER, User::ROLE_MANAGER], true)) {
            abort(403);
        }

        $validated = $request->validate([
            'days' => ['required', 'array'],
            'days.*.weekday' => ['required', 'integer', 'between:1,7'],
            'days.*.is_closed' => ['required', 'boolean'],
            'days.*.start' => ['nullable', 'date_format:H:i'],
            'days.*.end' => ['nullable', 'date_format:H:i'],
            'days.*.break_start' => ['nullable', 'date_format:H:i'],
            'days.*.break_end' => ['nullable', 'date_format:H:i'],
        ]);

        foreach ($validated['days'] as $day) {
            DB::table('business_working_hours')->updateOrInsert(
                [
                    'business_id' => $actor->business_id,
                    'weekday' => $day['weekday'],
                ],
                [
                    'is_closed' => (bool) $day['is_closed'],
                    'start' => $day['is_closed'] ? null : ($day['start'] ?? null),
                    'end'   => $day['is_closed'] ? null : ($day['end'] ?? null),
                    'break_start' => $day['break_start'] ?? null,
                    'break_end'   => $day['break_end'] ?? null,
                    'updated_at'  => now(),
                    'created_at'  => now(),
                ]
            );
        }

        return response()->json(['ok' => true]);
    }

    /* =========================================================
     * STAFF WEEKLY SCHEDULE
     * ========================================================= */

    public function showStaff(Request $request, User $user)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        // tenant safety
        if ((int) $user->business_id !== (int) $actor->business_id) abort(404);

        $data = DB::table('staff_working_hours')
            ->where('user_id', $user->id)
            ->where('business_id', $actor->business_id)
            ->orderBy('weekday')
            ->get()
            ->map(function ($row) {
                $row->start = $this->normalizeTimeValue($row->start ?? null);
                $row->end = $this->normalizeTimeValue($row->end ?? null);
                $row->break_start = $this->normalizeTimeValue($row->break_start ?? null);
                $row->break_end = $this->normalizeTimeValue($row->break_end ?? null);
                return $row;
            })->values();

        return response()->json(['data' => $data]);
    }

    public function updateStaff(Request $request, User $user)
    {
        $request->merge([
            'days' => collect((array) $request->input('days', []))
                ->map(function ($day) {
                    if (!is_array($day)) {
                        return $day;
                    }

                    $day['start'] = $this->normalizeTimeValue($day['start'] ?? null);
                    $day['end'] = $this->normalizeTimeValue($day['end'] ?? null);
                    $day['break_start'] = $this->normalizeTimeValue($day['break_start'] ?? null);
                    $day['break_end'] = $this->normalizeTimeValue($day['break_end'] ?? null);

                    return $day;
                })
                ->all(),
        ]);

        $actor = $request->user();
        if (!$actor) abort(401);

        if (!in_array($actor->role, [User::ROLE_OWNER, User::ROLE_MANAGER], true)) {
            abort(403);
        }

        if ((int) $user->business_id !== (int) $actor->business_id) abort(404);

        $validated = $request->validate([
            'days' => ['required', 'array'],
            'days.*.weekday' => ['required', 'integer', 'between:1,7'],
            'days.*.is_closed' => ['required', 'boolean'],
            'days.*.start' => ['nullable', 'date_format:H:i'],
            'days.*.end' => ['nullable', 'date_format:H:i'],
            'days.*.break_start' => ['nullable', 'date_format:H:i'],
            'days.*.break_end' => ['nullable', 'date_format:H:i'],
        ]);

        foreach ($validated['days'] as $day) {
            DB::table('staff_working_hours')->updateOrInsert(
                [
                    'business_id' => $actor->business_id,
                    'user_id' => $user->id,
                    'weekday' => $day['weekday'],
                ],
                [
                    'is_closed' => (bool) $day['is_closed'],
                    'start' => $day['is_closed'] ? null : ($day['start'] ?? null),
                    'end'   => $day['is_closed'] ? null : ($day['end'] ?? null),
                    'break_start' => $day['break_start'] ?? null,
                    'break_end'   => $day['break_end'] ?? null,
                    'updated_at'  => now(),
                    'created_at'  => now(),
                ]
            );
        }

        return response()->json(['ok' => true]);
    }

    /* =========================================================
     * EXCEPTIONS (Vacation / Closed day / Special hours)
     * ========================================================= */

    public function listExceptions(Request $request)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        $data = DB::table('schedule_exceptions')
            ->where('business_id', $actor->business_id)
            ->orderByDesc('date')
            ->get();

        return response()->json(['data' => $data]);
    }

    public function createException(Request $request)
    {
        $request->merge([
            'start' => $this->normalizeTimeValue($request->input('start')),
            'end' => $this->normalizeTimeValue($request->input('end')),
            'break_start' => $this->normalizeTimeValue($request->input('break_start')),
            'break_end' => $this->normalizeTimeValue($request->input('break_end')),
        ]);

        $actor = $request->user();
        if (!$actor) abort(401);

        if (!in_array($actor->role, [User::ROLE_OWNER, User::ROLE_MANAGER], true)) {
            abort(403);
        }

        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'date' => ['required', 'date'],
            'is_closed' => ['required', 'boolean'],
            'start' => ['nullable', 'date_format:H:i'],
            'end' => ['nullable', 'date_format:H:i'],
            'break_start' => ['nullable', 'date_format:H:i'],
            'break_end' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // if user_id provided -> must belong to same business
        if (!empty($data['user_id'])) {
            $u = User::query()->select('id','business_id')->findOrFail((int) $data['user_id']);
            if ((int) $u->business_id !== (int) $actor->business_id) abort(404);
        }

        DB::table('schedule_exceptions')->updateOrInsert(
            [
                'business_id' => $actor->business_id,
                'user_id' => $data['user_id'] ?? null,
                'date' => $data['date'],
            ],
            [
                'is_closed' => (bool) $data['is_closed'],
                'start' => $data['start'] ?? null,
                'end' => $data['end'] ?? null,
                'break_start' => $data['break_start'] ?? null,
                'break_end' => $data['break_end'] ?? null,
                'note' => $data['note'] ?? null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json(['ok' => true]);
    }

    public function deleteException(Request $request, int $id)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        DB::table('schedule_exceptions')
            ->where('id', $id)
            ->where('business_id', $actor->business_id)
            ->delete();

        return response()->json(['ok' => true]);
    }

    private function normalizeTimeValue($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $time = trim((string) $value);
        if ($time === '') {
            return null;
        }

        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            return $time;
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            return substr($time, 0, 5);
        }

        return $time;
    }
}
