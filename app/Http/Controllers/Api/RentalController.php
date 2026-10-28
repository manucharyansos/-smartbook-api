<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingBlock;
use App\Models\BookingPayment;
use App\Models\Business;
use App\Services\RentalBookingService;
use App\Services\ScheduleWindowService;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RentalController extends Controller
{
    private function business(Request $request): Business
    {
        abort_unless(in_array($request->user()?->role, ['owner', 'manager'], true), 403);
        $business = $request->user()->business;
        abort_unless($business?->isRental(), 404);

        return $business;
    }

    private function range(Request $request, Business $business): array
    {
        $data = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $from = Carbon::createFromFormat('!Y-m-d', $data['from'], $business->effectiveTimezone());
        $to = Carbon::createFromFormat('!Y-m-d', $data['to'], $business->effectiveTimezone())->endOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Ընտրեք առավելագույնը մեկ տարվա միջակայք։');

        return [$from->utc(), $to->utc()];
    }

    public function index(Request $request)
    {
        $business = $this->business($request);
        [$from, $to] = $this->range($request, $business);
        $bookings = Booking::query()->where('business_id', $business->id)->with('payments')
            ->where('starts_at', '<=', $to)->where('ends_at', '>', $from)->orderBy('starts_at')->get();

        return response()->json(['data' => $bookings->map(fn ($booking) => $this->serialize($booking)), 'timezone' => $business->effectiveTimezone()]);
    }

    public function availability(Request $request, RentalBookingService $rentals)
    {
        $business = $this->business($request);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'duration_minutes' => ['required', 'integer'],
        ]);

        // Owner reservations remain available when the public profile is hidden.
        // The rental service applies the same duration, schedule and conflict rules.
        return response()->json([
            'data' => $rentals->slots($business, $data['date'], (int) $data['duration_minutes']),
            'timezone' => $business->effectiveTimezone(),
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, RentalBookingService $rentals)
    {
        $business = $this->business($request);
        $data = $request->validate([
            'starts_at' => ['required', 'date_format:Y-m-d H:i'], 'duration_minutes' => ['required', 'integer'],
            'client_name' => ['required', 'string', 'min:2', 'max:120'], 'client_phone' => ['required', 'string', 'max:40'],
            'client_email' => ['nullable', 'email', 'max:255'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['client_phone'] = Phone::normalizeAM($data['client_phone']);
        if (! $data['client_phone']) {
            throw ValidationException::withMessages(['client_phone' => 'Սխալ հեռախոսահամար։']);
        }
        $booking = $rentals->create($business, $data, ['source' => 'manual', 'phone_verified_at' => now()]);

        return response()->json(['data' => $this->serialize($booking)], 201);
    }

    public function update(Request $request, int $booking, RentalBookingService $rentals)
    {
        $business = $this->business($request);
        $item = Booking::query()->where('business_id', $business->id)->findOrFail($booking);
        $data = $request->validate([
            'status' => ['sometimes', 'in:confirmed,in_progress,completed,cancelled,no_show'],
            'starts_at' => ['sometimes', 'date_format:Y-m-d H:i'], 'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $item = DB::transaction(function () use ($business, $item, $data, $rentals) {
            Business::query()->lockForUpdate()->findOrFail($business->id);
            $item = Booking::query()->lockForUpdate()->findOrFail($item->id);
            if (isset($data['starts_at'])) {
                $item = $rentals->move($business, $item, $data['starts_at']);
            }
            if (isset($data['status']) && $data['status'] !== $item->status) {
                $allowed = [
                    'pending' => ['confirmed', 'cancelled'], 'confirmed' => ['in_progress', 'completed', 'cancelled', 'no_show'],
                    'in_progress' => ['completed', 'cancelled', 'no_show'],
                ];
                abort_unless(in_array($data['status'], $allowed[$item->status] ?? [], true), 422, 'Կարգավիճակի այս փոփոխությունը թույլատրված չէ։');
                if ($data['status'] === 'confirmed') {
                    $rentals->assertAvailable($business, $item->starts_at, $item->ends_at, $item->id);
                }
                $item->status = $data['status'];
                if ($item->status === 'confirmed') {
                    $item->phone_verified_at = now();
                }
            }
            if (array_key_exists('notes', $data)) {
                $item->notes = $data['notes'];
            }
            $item->save();

            return $item;
        }, 3);

        return response()->json(['data' => $this->serialize($item)]);
    }

    public function payment(Request $request, int $booking)
    {
        $business = $this->business($request);
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'operation' => ['required', 'in:payment,refund'], 'idempotency_key' => ['required', 'uuid'],
        ]);
        $payment = DB::transaction(function () use ($business, $booking, $data) {
            Business::query()->lockForUpdate()->findOrFail($business->id);
            $item = Booking::query()->where('business_id', $business->id)->lockForUpdate()->findOrFail($booking);
            $reference = 'rental-'.$data['idempotency_key'];
            $existing = BookingPayment::query()->where('reference', $reference)->first();
            if ($existing) {
                abort_unless((int) $existing->booking_id === (int) $item->id && (int) $existing->amount === (int) $data['amount']
                    && $existing->status === ($data['operation'] === 'refund' ? 'refunded' : 'paid'), 409);

                return $existing;
            }
            $balance = $this->balance($item);
            $refund = $data['operation'] === 'refund';
            abort_if($refund ? $data['amount'] > $balance : $data['amount'] > max(0, (int) $item->final_price - $balance), 422, 'Գումարը գերազանցում է հաշվարկային մնացորդը։');
            abort_if(! $refund && in_array($item->status, ['cancelled', 'no_show'], true), 422, 'Չեղարկված ամրագրման համար վճարում չի գրանցվում։');

            return $item->payments()->create([
                'business_id' => $business->id, 'provider' => 'manual', 'reference' => $reference,
                'amount' => $data['amount'], 'currency' => 'AMD', 'status' => $refund ? 'refunded' : 'paid',
                'return_url' => '', 'cancel_url' => '', 'paid_at' => $refund ? null : now(), 'refunded_at' => $refund ? now() : null,
            ]);
        }, 3);

        return response()->json(['data' => $payment], 201);
    }

    public function summary(Request $request, ScheduleWindowService $schedule)
    {
        $business = $this->business($request);
        [$from, $to] = $this->range($request, $business);
        $items = Booking::query()->where('business_id', $business->id)->with('payments')
            ->whereBetween('starts_at', [$from, $to])->get();
        $active = $items->filter(fn ($item) => in_array($item->status, ['confirmed', 'in_progress', 'done', 'completed'], true));
        $completed = $active->whereIn('status', ['done', 'completed']);
        $pending = $items->filter(fn ($item) => $item->status === 'pending' && (! $item->phone_verification_expires_at || $item->phone_verification_expires_at->isFuture()));
        $cancelled = $items->whereIn('status', ['cancelled', 'no_show']);
        $paid = $items->sum(fn ($item) => $this->balance($item));
        $gross = $items->sum(fn ($item) => $item->payments->filter(fn ($payment) => $payment->status === 'paid' || ($payment->status === 'refunded' && $payment->provider !== 'manual'))->sum('amount'));
        $refunded = $items->sum(fn ($item) => $item->payments->where('status', 'refunded')->sum('amount'));
        $occupied = $active->sum(fn ($item) => (int) $item->starts_at->diffInMinutes($item->ends_at));
        $occupancyMinutes = 0;
        $occupying = Booking::query()->where('business_id', $business->id)
            ->whereIn('status', ['confirmed', 'in_progress', 'done', 'completed'])
            ->where('starts_at', '<=', $to)->where('ends_at', '>', $from)->get(['starts_at', 'ends_at']);
        $available = 0;
        $timezone = $business->effectiveTimezone();
        $blocks = BookingBlock::query()->where('business_id', $business->id)->whereNull('staff_id')
            ->where('starts_at', '<=', $to)->where('ends_at', '>', $from)->orderBy('starts_at')->get();
        for ($day = $from->copy()->timezone($timezone)->startOfDay()->subDay(); $day->lte($to); $day->addDay()) {
            foreach ($schedule->intervalsFor($business, $day) as $window) {
                $windowStart = $window['start']->copy()->max($from);
                $windowEnd = $window['end']->copy()->min($to->copy()->addSecond()->startOfSecond());
                if ($windowEnd->lte($windowStart)) {
                    continue;
                }
                $available += (int) $windowStart->diffInMinutes($windowEnd);
                $cursor = $windowStart->copy();
                foreach ($blocks as $block) {
                    $a = $block->starts_at->copy()->max($cursor);
                    $b = $block->ends_at->copy()->min($windowEnd);
                    if ($b->gt($a)) {
                        $available -= (int) $a->diffInMinutes($b);
                        $cursor = $b;
                    }
                }
                foreach ($occupying as $booking) {
                    $a = $booking->starts_at->copy()->max($windowStart);
                    $b = $booking->ends_at->copy()->min($windowEnd);
                    if ($b->gt($a)) {
                        $occupancyMinutes += (int) $a->diffInMinutes($b);
                    }
                }
            }
        }

        return response()->json(['data' => [
            'bookings_count' => $active->count(), 'pending_count' => $pending->count(), 'cancelled_count' => $cancelled->count(),
            'booked_amount' => (int) $active->sum('final_price'), 'completed_amount' => (int) $completed->sum('final_price'),
            'received_amount' => $gross, 'refunded_amount' => $refunded, 'net_received' => $paid,
            'outstanding_amount' => $active->sum(fn ($item) => max(0, (int) $item->final_price - $this->balance($item))),
            'refund_due' => $cancelled->sum(fn ($item) => max(0, $this->balance($item))),
            'booked_minutes' => $occupied, 'available_minutes' => max(0, $available),
            'occupancy_percent' => $available > 0 ? min(100, round($occupancyMinutes / $available * 100, 1)) : 0,
            'currency' => 'AMD', 'monthly_subscription' => $business->subscription?->plan?->monthlyPrice(),
            'daily' => $active->groupBy(fn ($item) => $item->starts_at->copy()->timezone($timezone)->toDateString())
                ->map(fn ($group, $date) => ['date' => $date, 'count' => $group->count(), 'amount' => (int) $group->sum('final_price')])->values(),
        ]]);
    }

    private function balance(Booking $booking): int
    {
        $booking->loadMissing('payments');

        return (int) $booking->payments->where('status', 'paid')->sum('amount')
            - (int) $booking->payments->where('status', 'refunded')->where('provider', 'manual')->sum('amount');
    }

    private function serialize(Booking $booking): array
    {
        $balance = $this->balance($booking);

        return [
            'id' => $booking->id, 'booking_code' => $booking->booking_code, 'client_name' => $booking->client_name,
            'client_phone' => $booking->client_phone, 'client_email' => $booking->client_email, 'notes' => $booking->notes,
            'starts_at' => $booking->starts_at->toISOString(), 'ends_at' => $booking->ends_at->toISOString(),
            'status' => $booking->status, 'final_price' => (int) $booking->final_price, 'paid_amount' => $balance,
            'outstanding_amount' => max(0, (int) $booking->final_price - $balance), 'currency' => $booking->currency,
            'is_expired' => $booking->status === 'pending' && $booking->phone_verification_expires_at?->isPast(),
            'payments' => $booking->payments->map(fn ($payment) => [
                'id' => $payment->id, 'amount' => $payment->amount, 'status' => $payment->status,
                'provider' => $payment->provider, 'created_at' => $payment->created_at->toISOString(),
            ]),
        ];
    }
}
