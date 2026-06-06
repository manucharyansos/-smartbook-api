<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingBlock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class CalendarBlockController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'staff_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $from = Carbon::createFromFormat('Y-m-d', $validated['from'])->startOfDay();
        $to = Carbon::createFromFormat('Y-m-d', $validated['to'])->endOfDay();

        $q = BookingBlock::query()
            ->where('business_id', $user->business_id)
            ->where(function ($qq) use ($from, $to) {
                // overlap with [from, to]
                $qq->where('starts_at', '<', $to)
                    ->where('ends_at', '>', $from);
            })
            ->orderBy('starts_at');

        if (!empty($validated['staff_id'])) {
            $q->where('staff_id', (int) $validated['staff_id']);
        }

        if (!empty($validated['location_id'])) {
            $locationId = (int) $validated['location_id'];
            $q->where(function ($qq) use ($locationId) {
                $qq->whereNull('staff_id')
                    ->orWhereHas('staff', fn ($staffQ) => $staffQ->where('location_id', $locationId));
            });
        }

        return response()->json([
            'data' => $q->get(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            // Accept "Y-m-d H:i" from frontend (we send that)
            'starts_at' => ['required', 'string'],
            'ends_at'   => ['required', 'string'],
            'reason'    => ['nullable', 'string', 'max:190'],
            'staff_id'  => ['nullable', 'integer'],
        ]);

        $start = $this->parseDt($validated['starts_at']);
        $end   = $this->parseDt($validated['ends_at']);

        if (!$start || !$end) {
            throw ValidationException::withMessages([
                'starts_at' => ['Սխալ datetime format'],
            ]);
        }

        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages([
                'ends_at' => ['ends_at պետք է լինի starts_at-ից մեծ'],
            ]);
        }

        // Optional: prevent too huge blocks (like years)
        if ($start->diffInDays($end) > 14) {
            throw ValidationException::withMessages([
                'ends_at' => ['Շատ մեծ փակ ժամանակահատված է (max 14 օր)'],
            ]);
        }

        // Optional: block overlap merge prevention
        $overlap = BookingBlock::query()
            ->where('business_id', $user->business_id)
            ->when(!empty($validated['staff_id']), fn($q) => $q->where('staff_id', (int)$validated['staff_id']))
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'starts_at' => ['Արդեն կա փակ interval, որը համընկնում է'],
            ]);
        }

        $block = BookingBlock::create([
            'business_id' => $user->business_id,
            'staff_id' => $validated['staff_id'] ?? null,
            'starts_at' => $start,
            'ends_at' => $end,
            'reason' => $validated['reason'] ?? null,
        ]);

        return response()->json(['data' => $block], 201);
    }

    public function destroy(Request $request, BookingBlock $block)
    {
        $user = $request->user();

        if ((int)$block->business_id !== (int)$user->business_id) {
            abort(403);
        }

        $block->delete();

        return response()->json(['ok' => true]);
    }

    private function parseDt(string $dt): ?Carbon
    {
        $dt = trim($dt);

        // accept "Y-m-d H:i" or "Y-m-d H:i:s" or ISO
        try {
            if (str_contains($dt, 'T')) {
                return Carbon::parse($dt);
            }

            // "Y-m-d H:i"
            if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $dt)) {
                return Carbon::createFromFormat('Y-m-d H:i', $dt);
            }

            // "Y-m-d H:i:s"
            if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $dt)) {
                return Carbon::createFromFormat('Y-m-d H:i:s', $dt);
            }

            // fallback parse
            return Carbon::parse($dt);
        } catch (\Throwable) {
            return null;
        }
    }
}
