<?php
// app/Http/Controllers/Api/BookingController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\BookingBlock;
use App\Models\BookingItem;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Services\GiftCardService;
use App\Services\LoyaltyService;
use App\Notifications\NewBookingNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    /**
     * GET /api/bookings
     * ✅ All DB datetimes are treated as UTC.
     * ✅ All incoming "date/from/to/week_start" are treated as BUSINESS LOCAL dates, then converted to UTC for DB query.
     */
    public function index(Request $request)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        $business = $actor->business;
        $tz = $business?->effectiveTimezone() ?? 'Asia/Yerevan';

        $q = Booking::query()->with(['service', 'staff', 'business', 'location', 'items.service']);

        if ($actor->isSuperAdmin()) {
            if ($request->filled('business_id')) {
                $q->where('business_id', (int)$request->integer('business_id'));
            }
        } else {
            $q->where('business_id', (int)$actor->business_id);

            if ($actor->role === User::ROLE_STAFF) {
                $q->where('staff_id', (int)$actor->id);
            }
        }

        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }

        if ($request->filled('staff_id')) {
            $q->where('staff_id', (int)$request->integer('staff_id'));
        }

        if ($request->filled('location_id')) {
            $locationId = (int) $request->integer('location_id');
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

        if ($request->filled('source')) {
            $q->where('source', $request->string('source'));
        }

        /**
         * ✅ date=YYYY-MM-DD (business local day -> UTC range)
         * (Don't use whereDate on UTC columns, it breaks around timezone boundaries)
         */
        if ($request->filled('date')) {
            [$fromUtc, $toUtc] = $this->localDayToUtcRange($request->string('date'), $tz);
            $q->whereBetween('starts_at', [$fromUtc, $toUtc]);
        }

        /**
         * ✅ week_start=YYYY-MM-DD (business local week -> UTC range)
         */
        if ($request->filled('week_start')) {
            $wsLocal = Carbon::createFromFormat('Y-m-d', $request->string('week_start'), $tz)
                ->startOfWeek(Carbon::MONDAY)
                ->startOfDay();

            $weLocal = $wsLocal->copy()->addDays(7)->endOfDay(); // inclusive range end

            $fromUtc = $wsLocal->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');
            $toUtc   = $weLocal->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');

            $q->whereBetween('starts_at', [$fromUtc, $toUtc]);
        }

        /**
         * ✅ Calendar range: from/to (YYYY-MM-DD) as business local dates -> UTC range
         */
        if ($request->filled('from') && $request->filled('to')) {
            $fromLocal = Carbon::createFromFormat('Y-m-d', $request->string('from'), $tz)->startOfDay();
            $toLocal   = Carbon::createFromFormat('Y-m-d', $request->string('to'), $tz)->endOfDay();

            $fromUtc = $fromLocal->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');
            $toUtc   = $toLocal->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');

            $q->whereBetween('starts_at', [$fromUtc, $toUtc]);
        } elseif ($request->filled('days')) {
            // ✅ days range is "recent days" relative to business timezone, converted to UTC
            $days = max(1, min(365, (int)$request->integer('days')));

            $fromLocal = now($tz)->subDays($days)->startOfDay();
            $toLocal   = now($tz)->endOfDay();

            $fromUtc = $fromLocal->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');
            $toUtc   = $toLocal->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');

            $q->whereBetween('starts_at', [$fromUtc, $toUtc]);
        }

        if (!$request->boolean("include_unverified")) {
            $q->where(function ($qq) {
                $qq->whereNull("phone_verification_code_hash")
                    ->orWhereNotNull("phone_verified_at");
            });
        }

        return response()->json([
            'data' => BookingResource::collection($q->orderByDesc('starts_at')->get())
        ]);
    }

    /**
     * GET /api/bookings/{booking}
     */
    public function show(Request $request, Booking $booking)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        if (!$actor->isSuperAdmin() && (int)$booking->business_id !== (int)$actor->business_id) {
            abort(404);
        }

        return response()->json([
            'data' => new BookingResource(
                $booking->load(['service', 'staff', 'business', 'location', 'client', 'room', 'items.service'])
            )
        ]);
    }

    /**
     * POST /api/bookings
     * ✅ Single booking may contain BookingItem[] (multi-service), one staff for all.
     * ✅ Saves UTC in DB.
     */
    public function store(Request $request)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        $data = $request->validate([
            'service_id'    => ['nullable', 'integer', 'exists:services,id'],
            'service_ids'   => ['nullable', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'exists:services,id'],

            'staff_id'      => ['required', 'integer', 'exists:users,id'],
            'location_id'   => ['nullable', 'integer', 'min:1'],
            'starts_at'     => ['required', 'date_format:Y-m-d H:i'],

            'client_name'   => ['required', 'string', 'max:120'],
            'client_phone'  => ['required', 'string', 'max:40'],
            'client_email'  => ['nullable', 'email', 'max:150'],
            'client_id'     => ['nullable', 'integer', 'exists:clients,id'],

            'notes'         => ['nullable', 'string', 'max:2000'],
            'status'        => ['nullable', 'in:pending,confirmed'],
            'room_id'       => ['nullable', 'integer', 'exists:rooms,id'],
            'source'        => ['nullable', 'in:website,instagram,facebook,whatsapp,admin,partner,widget,qr,returning_client'],
            'redeem_points' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'gift_card_code' => ['nullable', 'string', 'max:40'],
            'gift_card_amount' => ['nullable', 'integer', 'min:1', 'max:100000000'],
        ]);

        $actorBusiness = $actor->business;


        // Resolve serviceIds (keep order)
        $serviceIds = [];
        if (!empty($data['service_ids']) && is_array($data['service_ids'])) {
            $serviceIds = array_values(array_map('intval', $data['service_ids']));
        } elseif (!empty($data['service_id'])) {
            $serviceIds = [(int)$data['service_id']];
        }
        $serviceIds = array_values(array_filter($serviceIds, fn($x) => $x > 0));
        if (!$serviceIds) {
            throw ValidationException::withMessages(['service_id' => ['At least one service is required.']]);
        }

        $servicesById = Service::query()->whereIn('id', $serviceIds)->get()->keyBy('id');
        $orderedServices = collect($serviceIds)->map(fn($id) => $servicesById->get($id))->filter();
        if ($orderedServices->count() !== count($serviceIds)) abort(404);

        /** @var Service $primaryService */
        $primaryService = $orderedServices->first();

        foreach ($orderedServices as $svc) {
            if (!$actor->isSuperAdmin() && (int)$svc->business_id !== (int)$actor->business_id) abort(404);
        }

        $staff = User::query()->findOrFail((int)$data['staff_id']);
        if (!$actor->isSuperAdmin() && (int)$staff->business_id !== (int)$actor->business_id) abort(404);
        if ($actor->role === User::ROLE_STAFF && (int)$staff->id !== (int)$actor->id) abort(403);

        $this->assertSingleBookingServicesCompatible($orderedServices, $staff);

        $bookingBusinessId = (int) ($actor->isSuperAdmin() ? $primaryService->business_id : $actor->business_id);
        /** @var Business|null $business */
        $business = $actor->isSuperAdmin()
            ? Business::query()->find($bookingBusinessId)
            : $actorBusiness;

        $tz = $business?->effectiveTimezone() ?? 'Asia/Yerevan';
        $step = (int) ($business?->slot_step_minutes ?? 15);

        $resolvedLocationId = $this->resolveBookingLocationId(
            businessId: $bookingBusinessId,
            requestedLocationId: $data['location_id'] ?? null,
            service: $primaryService,
            staff: $staff,
        );

        // Compute ends_at from sum(duration) + SNAP (local) -> then convert to UTC for DB
        $startLocal = Carbon::createFromFormat('Y-m-d H:i', $data['starts_at'], $tz)->seconds(0);
        $startLocal = $this->snapToStep($startLocal, $step);

        $totalDuration = 0;
        $totalPrice = 0;
        $currency = 'AMD';
        $priceIsNull = false;

        foreach ($orderedServices as $svc) {
            $dur = (int)($svc->duration_minutes ?? 0);
            if ($dur < 5 || $dur > 600) {
                throw ValidationException::withMessages(['service_id' => ['Invalid service duration.']]);
            }
            $totalDuration += $dur;
            $currency = $svc->currency ?? $currency;

            if ($svc->price === null) $priceIsNull = true;
            else $totalPrice += (int)$svc->price;
        }

        $endLocal = $startLocal->copy()->addMinutes($totalDuration)->seconds(0);

        $this->assertWithinBusinessHours($business, $startLocal, $endLocal);

        $startUtc = $startLocal->copy()->setTimezone('UTC');
        $endUtc   = $endLocal->copy()->setTimezone('UTC');

        // overlap + blocked checks MUST use UTC because DB stores UTC
        $this->checkOverlap($bookingBusinessId, (int) $staff->id, $startUtc, $endUtc);
        $this->checkBlocked($bookingBusinessId, (int) $staff->id, $startUtc, $endUtc);

        // Resolve client once
        $clientId = $this->resolveClientId(
            $actor,
            $primaryService,
            $data['client_phone'],
            $data['client_name'],
            $data['client_id'] ?? null,
            $data['client_email'] ?? null
        );
        $clientEmailSnapshot = Booking::normalizeContactEmail(
            $data['client_email'] ?? Client::query()->whereKey($clientId)->value('email')
        );

        $booking = null;

        DB::transaction(function () use (
            &$booking,
            $actor,
            $business,
            $primaryService,
            $staff,
            $data,
            $clientId,
            $clientEmailSnapshot,
            $startUtc,
            $endUtc,
            $priceIsNull,
            $totalPrice,
            $currency,
            $orderedServices,
            $resolvedLocationId,
            $bookingBusinessId
        ) {
            $bookingData = [
                'business_id'  => $bookingBusinessId,
                'location_id'  => $resolvedLocationId,
                'service_id'   => (int)$primaryService->id,
                'staff_id'     => (int)$staff->id,

                'client_id'    => $clientId,
                'client_name'  => $data['client_name'],
                'client_phone' => $data['client_phone'],
                'client_email' => $clientEmailSnapshot,
                'notes'        => $data['notes'] ?? null,
                'source'       => $data['source'] ?? 'admin',
                'status'       => $data['status'] ?? 'pending',

                // ✅ store UTC
                'starts_at'    => $startUtc->format('Y-m-d H:i:s'),
                'ends_at'      => $endUtc->format('Y-m-d H:i:s'),

                'final_price'  => $priceIsNull ? null : $totalPrice,
                'currency'     => $currency,
                'booking_code' => $this->generateBookingCode(),
            ];

            if ($business?->isDental() && !empty($data['room_id'])) {
                $bookingData['room_id'] = (int)$data['room_id'];
            }

            $booking = Booking::create($bookingData);

            foreach ($orderedServices as $idx => $svc) {
                BookingItem::create([
                    'booking_id'       => $booking->id,
                    'service_id'       => (int)$svc->id,
                    'position'         => (int)$idx,
                    'duration_minutes' => (int)$svc->duration_minutes,
                    'price'            => $svc->price,
                    'currency'         => $svc->currency ?? $currency,
                ]);
            }

            $this->applyBookingBenefitsFromPayload($actor, $booking, $data, $clientId, $priceIsNull ? null : $totalPrice);
        });

        $this->safeNotifyNewBooking($booking);

        return response()->json([
            'data' => new BookingResource($booking->fresh()->load(['service', 'staff', 'business', 'location', 'items.service']))
        ], 201);
    }

    /**
     * POST /api/bookings/multi
     * ✅ Sequential bookings, each line can have different staff/service.
     * ✅ Saves UTC in DB.
     */
    public function storeMulti(Request $request)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        $data = $request->validate([
            'starts_at'    => ['required', 'date_format:Y-m-d H:i'],
            'client_name'  => ['required', 'string', 'max:120'],
            'client_phone' => ['required', 'string', 'max:40'],
            'client_email' => ['nullable', 'email', 'max:150'],
            'notes'        => ['nullable', 'string', 'max:2000'],
            'status'       => ['nullable', 'in:pending,confirmed'],

            'lines'               => ['required', 'array', 'min:1'],
            'lines.*.service_id'  => ['required', 'integer', 'exists:services,id'],
            'lines.*.staff_id'    => ['required', 'integer', 'exists:users,id'],
            'location_id'         => ['nullable', 'integer', 'min:1'],
            'source'              => ['nullable', 'in:website,instagram,facebook,whatsapp,admin,partner,widget,qr,returning_client'],
        ]);

        $contextService = Service::query()->findOrFail((int) $data['lines'][0]['service_id']);
        $primaryBusinessId = (int) ($actor->isSuperAdmin() ? $contextService->business_id : $actor->business_id);

        /** @var Business|null $business */
        $business = $actor->isSuperAdmin()
            ? Business::query()->find($primaryBusinessId)
            : $actor->business;

        $tz = $business?->effectiveTimezone() ?? 'Asia/Yerevan';
        $step = (int) ($business?->slot_step_minutes ?? 15);

        $clientId = $this->resolveClientId($actor, $contextService, $data['client_phone'], $data['client_name'], null, $data['client_email'] ?? null);
        $clientEmailSnapshot = Booking::normalizeContactEmail(
            $data['client_email'] ?? Client::query()->whereKey($clientId)->value('email')
        );
        $groupId = (string) Str::uuid();

        $cursorLocal = Carbon::createFromFormat('Y-m-d H:i', $data['starts_at'], $tz)->seconds(0);
        $cursorLocal = $this->snapToStep($cursorLocal, $step);

        $created = [];

        DB::transaction(function () use (
            $actor,
            $primaryBusinessId,
            $clientId,
            $clientEmailSnapshot,
            $groupId,
            $data,
            &$cursorLocal,
            &$created,
            $tz
        ) {
            foreach ($data['lines'] as $i => $line) {
                $service = Service::query()->findOrFail((int)$line['service_id']);
                $staff   = User::query()->findOrFail((int)$line['staff_id']);

                if ((int) $service->business_id !== $primaryBusinessId) abort(404);
                if ((int) $staff->business_id !== $primaryBusinessId) abort(404);
                if ($actor->role === User::ROLE_STAFF && (int)$staff->id !== (int)$actor->id) abort(403);

                $this->assertSingleBookingServicesCompatible(collect([$service]), $staff);

                $dur = (int)($service->duration_minutes ?? 0);
                if ($dur < 5 || $dur > 600) {
                    throw ValidationException::withMessages(["lines.$i.service_id" => ["Invalid service duration"]]);
                }

                $startLocal = $cursorLocal->copy()->seconds(0);
                $endLocal   = $cursorLocal->copy()->addMinutes($dur)->seconds(0);

                $this->assertWithinBusinessHours($business, $startLocal, $endLocal);

                $startUtc = $startLocal->copy()->setTimezone('UTC');
                $endUtc   = $endLocal->copy()->setTimezone('UTC');

                $this->checkOverlap($primaryBusinessId, (int)$staff->id, $startUtc, $endUtc);
                $this->checkBlocked($primaryBusinessId, (int)$staff->id, $startUtc, $endUtc);

                $resolvedLocationId = $this->resolveBookingLocationId(
                    businessId: $primaryBusinessId,
                    requestedLocationId: $data['location_id'] ?? null,
                    service: $service,
                    staff: $staff,
                );

                $booking = Booking::create([
                    'group_id'     => $groupId,
                    'business_id'  => $primaryBusinessId,
                    'location_id'  => $resolvedLocationId,
                    'service_id'   => (int)$service->id,
                    'staff_id'     => (int)$staff->id,

                    'client_id'    => $clientId,
                    'client_name'  => $data['client_name'],
                    'client_phone' => $data['client_phone'],
                    'client_email' => $clientEmailSnapshot,
                    'notes'        => $data['notes'] ?? null,
                    'source'       => $data['source'] ?? 'admin',
                    'status'       => $data['status'] ?? 'confirmed',

                    // ✅ store UTC
                    'starts_at'    => $startUtc->format('Y-m-d H:i:s'),
                    'ends_at'      => $endUtc->format('Y-m-d H:i:s'),

                    'final_price'  => $service->price,
                    'currency'     => $service->currency ?? 'AMD',
                    'booking_code' => $this->generateBookingCode(),
                ]);

                BookingItem::create([
                    'booking_id'       => $booking->id,
                    'service_id'       => (int)$service->id,
                    'position'         => 0,
                    'duration_minutes' => (int)$service->duration_minutes,
                    'price'            => $service->price,
                    'currency'         => $service->currency ?? 'AMD',
                ]);

                $created[] = $booking;

                $cursorLocal = $endLocal->copy(); // move forward in LOCAL time
            }

            $this->applyBookingBenefitsFromPayload($actor, $created[0], $data, $clientId, collect($created)->sum(fn ($item) => (int) ($item->final_price ?? 0)));
        });

        $this->safeNotifyNewGroup($created);

        return response()->json([
            'data' => BookingResource::collection(
                collect($created)->map(fn($b) => $b->fresh()->load(['service', 'staff', 'business', 'client', 'items.service']))
            ),
            'group_id' => $groupId,
        ], 201);
    }

    /**
     * POST /api/bookings/lines
     * Group booking where each line has its own service, staff and start time.
     */
    public function storeLines(Request $request)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        $data = $request->validate([
            'lines'               => ['required', 'array', 'min:1'],
            'lines.*.service_id'  => ['required', 'integer', 'exists:services,id'],
            'lines.*.staff_id'    => ['required', 'integer', 'exists:users,id'],
            'lines.*.starts_at'   => ['required', 'date_format:Y-m-d H:i'],
            'client_name'         => ['required', 'string', 'max:120'],
            'client_phone'        => ['required', 'string', 'max:40'],
            'client_email'        => ['nullable', 'email', 'max:150'],
            'client_id'           => ['nullable', 'integer', 'exists:clients,id'],
            'notes'               => ['nullable', 'string', 'max:2000'],
            'status'              => ['nullable', 'in:pending,confirmed'],
            'location_id'         => ['nullable', 'integer', 'min:1'],
            'source'              => ['nullable', 'in:website,instagram,facebook,whatsapp,admin,partner,widget,qr,returning_client'],
        ]);

        $contextService = Service::query()->findOrFail((int) $data['lines'][0]['service_id']);
        $businessId = (int) ($actor->isSuperAdmin() ? $contextService->business_id : $actor->business_id);

        /** @var Business|null $business */
        $business = $actor->isSuperAdmin()
            ? Business::query()->find($businessId)
            : $actor->business;

        $tz = $business?->effectiveTimezone() ?? 'Asia/Yerevan';
        $step = (int) ($business?->slot_step_minutes ?? 15);

        $prepared = [];
        foreach ($data['lines'] as $i => $line) {
            $service = Service::query()->findOrFail((int)$line['service_id']);
            $staff = User::query()->findOrFail((int)$line['staff_id']);

            if ((int) $service->business_id !== $businessId) abort(404);
            if ((int) $staff->business_id !== $businessId) abort(404);
            if ($actor->role === User::ROLE_STAFF && (int)$staff->id !== (int)$actor->id) abort(403);

            $this->assertSingleBookingServicesCompatible(collect([$service]), $staff);

            $duration = (int)($service->duration_minutes ?? 0);
            if ($duration < 5 || $duration > 600) {
                throw ValidationException::withMessages(["lines.$i.service_id" => ['Invalid service duration.']]);
            }

            $startLocal = Carbon::createFromFormat('Y-m-d H:i', $line['starts_at'], $tz)->seconds(0);
            $startLocal = $this->snapToStep($startLocal, $step);
            $endLocal = $startLocal->copy()->addMinutes($duration)->seconds(0);

            $this->assertWithinBusinessHours($business, $startLocal, $endLocal, "lines.$i.starts_at");

            $startUtc = $startLocal->copy()->setTimezone('UTC');
            $endUtc = $endLocal->copy()->setTimezone('UTC');

            $this->checkOverlap($businessId, (int)$staff->id, $startUtc, $endUtc);
            $this->checkBlocked($businessId, (int)$staff->id, $startUtc, $endUtc);

            $prepared[] = [
                'service' => $service,
                'staff' => $staff,
                'location_id' => $this->resolveBookingLocationId(
                    businessId: $businessId,
                    requestedLocationId: $data['location_id'] ?? null,
                    service: $service,
                    staff: $staff,
                ),
                'startUtc' => $startUtc,
                'endUtc' => $endUtc,
            ];
        }

        $this->assertPreparedLinesDoNotOverlap($prepared);

        /** @var Service $primaryService */
        $primaryService = $prepared[0]['service'];
        $clientId = $this->resolveClientId(
            $actor,
            $primaryService,
            $data['client_phone'],
            $data['client_name'],
            $data['client_id'] ?? null,
            $data['client_email'] ?? null
        );
        $clientEmailSnapshot = Booking::normalizeContactEmail(
            $data['client_email'] ?? Client::query()->whereKey($clientId)->value('email')
        );
        $groupId = (string) Str::uuid();
        $created = [];

        DB::transaction(function () use (&$created, $prepared, $groupId, $businessId, $clientId, $clientEmailSnapshot, $data) {
            foreach ($prepared as $row) {
                /** @var Service $service */
                $service = $row['service'];
                /** @var User $staff */
                $staff = $row['staff'];
                /** @var Carbon $startUtc */
                $startUtc = $row['startUtc'];
                /** @var Carbon $endUtc */
                $endUtc = $row['endUtc'];

                $booking = Booking::create([
                    'group_id'     => $groupId,
                    'business_id'  => $businessId,
                    'location_id'  => $row['location_id'],
                    'service_id'   => (int)$service->id,
                    'staff_id'     => (int)$staff->id,
                    'client_id'    => $clientId,
                    'client_name'  => $data['client_name'],
                    'client_phone' => $data['client_phone'],
                    'client_email' => $clientEmailSnapshot,
                    'notes'        => $data['notes'] ?? null,
                    'source'       => $data['source'] ?? 'admin',
                    'status'       => $data['status'] ?? 'confirmed',
                    'starts_at'    => $startUtc->format('Y-m-d H:i:s'),
                    'ends_at'      => $endUtc->format('Y-m-d H:i:s'),
                    'final_price'  => $service->price,
                    'currency'     => $service->currency ?? 'AMD',
                    'booking_code' => $this->generateBookingCode(),
                ]);

                BookingItem::create([
                    'booking_id'       => $booking->id,
                    'service_id'       => (int)$service->id,
                    'position'         => 0,
                    'duration_minutes' => (int)$service->duration_minutes,
                    'price'            => $service->price,
                    'currency'         => $service->currency ?? 'AMD',
                ]);

                $created[] = $booking;
            }
        });

        $this->safeNotifyNewGroup($created);

        return response()->json([
            'data' => BookingResource::collection(
                collect($created)->map(fn($b) => $b->fresh()->load(['service', 'staff', 'business', 'client', 'items.service']))
            ),
            'group_id' => $groupId,
        ], 201);
    }

    /**
     * PUT /api/bookings/{booking}
     * ✅ Any start/end changes are computed in LOCAL, then stored as UTC
     */
    public function update(Request $request, Booking $booking)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        if (!$actor->isSuperAdmin() && (int)$booking->business_id !== (int)$actor->business_id) abort(404);
        if ($actor->role === User::ROLE_STAFF && (int)$booking->staff_id !== (int)$actor->id) abort(403);

        $data = $request->validate([
            'client_name'  => ['sometimes', 'string', 'max:120'],
            'client_phone' => ['sometimes', 'string', 'max:40'],
            'client_email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'client_id'    => ['nullable', 'integer', 'exists:clients,id'],

            'starts_at'    => ['sometimes', 'date_format:Y-m-d H:i'],

            'service_id'    => ['nullable', 'integer', 'exists:services,id'],
            'service_ids'   => ['nullable', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'exists:services,id'],

            'staff_id'     => ['nullable', 'integer', 'exists:users,id'],
            'location_id'  => ['nullable', 'integer', 'min:1'],
            'status'       => ['sometimes', 'in:pending,confirmed,cancelled,done,no_show'],
            'notes'        => ['nullable', 'string', 'max:2000'],
            'room_id'      => ['nullable', 'integer', 'exists:rooms,id'],
        ]);

        /** @var Business|null $business */
        $business = $booking->relationLoaded('business')
            ? $booking->business
            : Business::query()->find((int) $booking->business_id);

        $bookingBusinessId = (int) $booking->business_id;
        $tz = $business?->effectiveTimezone() ?? 'Asia/Yerevan';
        $step = (int) ($business?->slot_step_minutes ?? 15);

        $serviceIds = null;
        if (!empty($data['service_ids']) && is_array($data['service_ids'])) {
            $serviceIds = array_values(array_map('intval', $data['service_ids']));
        } elseif (!empty($data['service_id'])) {
            $serviceIds = [(int)$data['service_id']];
        }

        // If services changed -> recompute ends + replace items
        if ($serviceIds !== null) {
            $serviceIds = array_values(array_filter($serviceIds, fn($x) => $x > 0));
            if (!$serviceIds) throw ValidationException::withMessages(['service_id' => ['At least one service is required.']]);

            $servicesById = Service::query()->whereIn('id', $serviceIds)->get()->keyBy('id');
            $orderedServices = collect($serviceIds)->map(fn($id) => $servicesById->get($id))->filter();
            if ($orderedServices->count() !== count($serviceIds)) abort(404);

            foreach ($orderedServices as $svc) {
                if ((int) $svc->business_id !== $bookingBusinessId) abort(404);
            }

            $effectiveStaff = !empty($data['staff_id'])
                ? User::query()->findOrFail((int) $data['staff_id'])
                : ($booking->staff_id ? User::query()->findOrFail((int) $booking->staff_id) : null);

            $this->assertSingleBookingServicesCompatible($orderedServices, $effectiveStaff);

            $startsInput = $data['starts_at']
                ?? Carbon::parse($booking->starts_at, 'UTC')->setTimezone($tz)->format('Y-m-d H:i');

            $startLocal = Carbon::createFromFormat('Y-m-d H:i', $startsInput, $tz)->seconds(0);
            $startLocal = $this->snapToStep($startLocal, $step);

            $totalDuration = 0;
            $totalPrice = 0;
            $currency = 'AMD';
            $priceIsNull = false;

            foreach ($orderedServices as $svc) {
                $dur = (int)($svc->duration_minutes ?? 0);
                if ($dur < 5 || $dur > 600) throw ValidationException::withMessages(['service_id' => ['Invalid service duration.']]);

                $totalDuration += $dur;
                $currency = $svc->currency ?? $currency;

                if ($svc->price === null) $priceIsNull = true;
                else $totalPrice += (int)$svc->price;
            }

            $endLocal = $startLocal->copy()->addMinutes($totalDuration)->seconds(0);

            $this->assertWithinBusinessHours($business, $startLocal, $endLocal);

            $startUtc = $startLocal->copy()->setTimezone('UTC');
            $endUtc   = $endLocal->copy()->setTimezone('UTC');

            $staffIdForCheck = (int)($data['staff_id'] ?? $booking->staff_id);
            if (!$staffIdForCheck) throw ValidationException::withMessages(['staff_id' => ['Booking must have staff assigned.']]);

            $this->checkOverlap($bookingBusinessId, $staffIdForCheck, $startUtc, $endUtc, (int) $booking->id);
            $this->checkBlocked($bookingBusinessId, $staffIdForCheck, $startUtc, $endUtc);

            $data['service_id']   = (int)$orderedServices->first()->id;
            $data['starts_at']    = $startUtc->format('Y-m-d H:i:s'); // ✅ store UTC
            $data['ends_at']      = $endUtc->format('Y-m-d H:i:s');   // ✅ store UTC
            $data['final_price']  = $priceIsNull ? null : $totalPrice;
            $data['currency']     = $currency;

            DB::transaction(function () use ($booking, $orderedServices, $currency) {
                $booking->items()->delete();
                foreach ($orderedServices as $idx => $svc) {
                    BookingItem::create([
                        'booking_id'       => $booking->id,
                        'service_id'       => (int)$svc->id,
                        'position'         => (int)$idx,
                        'duration_minutes' => (int)$svc->duration_minutes,
                        'price'            => $svc->price,
                        'currency'         => $svc->currency ?? $currency,
                    ]);
                }
            });
        }

        // If time changed (without service change) -> recompute ends from current duration, snap start
        if (!empty($data['starts_at']) && $serviceIds === null) {
            $duration = $booking->items()->count()
                ? (int)$booking->items()->sum('duration_minutes')
                : (int)($booking->service?->duration_minutes ?? 0);

            $startLocal = Carbon::createFromFormat('Y-m-d H:i', $data['starts_at'], $tz)->seconds(0);
            $startLocal = $this->snapToStep($startLocal, $step);

            $endLocal = ($duration >= 5 && $duration <= 600)
                ? $startLocal->copy()->addMinutes($duration)->seconds(0)
                : Carbon::parse($booking->ends_at, 'UTC')->setTimezone($tz)->seconds(0);

            $this->assertWithinBusinessHours($business, $startLocal, $endLocal);

            $startUtc = $startLocal->copy()->setTimezone('UTC');
            $endUtc   = $endLocal->copy()->setTimezone('UTC');

            $staffIdForCheck = (int)($data['staff_id'] ?? $booking->staff_id);
            if (!$staffIdForCheck) throw ValidationException::withMessages(['staff_id' => ['Booking must have staff assigned.']]);

            $this->checkOverlap($bookingBusinessId, $staffIdForCheck, $startUtc, $endUtc, (int) $booking->id);
            $this->checkBlocked($bookingBusinessId, $staffIdForCheck, $startUtc, $endUtc);

            $data['starts_at'] = $startUtc->format('Y-m-d H:i:s'); // ✅ UTC
            $data['ends_at']   = $endUtc->format('Y-m-d H:i:s');   // ✅ UTC
        }

        // Staff change validation + checks (use UTC)
        if (!empty($data['staff_id'])) {
            $staff = User::query()->findOrFail((int)$data['staff_id']);
            if ((int) $staff->business_id !== $bookingBusinessId) abort(404);
            if ($actor->role === User::ROLE_STAFF && (int)$staff->id !== (int)$actor->id) abort(403);

            $effectiveServices = $serviceIds !== null
                ? $orderedServices
                : collect([$resolvedService ?: $booking->service])->filter();
            $this->assertSingleBookingServicesCompatible($effectiveServices, $staff);

            $startUtc = Carbon::parse($data['starts_at'] ?? $booking->starts_at, 'UTC')->seconds(0);
            $endUtc   = Carbon::parse($data['ends_at'] ?? $booking->ends_at, 'UTC')->seconds(0);

            $this->checkOverlap($bookingBusinessId, (int) $staff->id, $startUtc, $endUtc, (int) $booking->id);
            $this->checkBlocked($bookingBusinessId, (int) $staff->id, $startUtc, $endUtc);
        }

        $resolvedService = null;
        if (!empty($data['service_id'])) {
            $resolvedService = Service::query()->find((int) $data['service_id']);
        } elseif ($booking->service_id) {
            $resolvedService = Service::query()->find((int) $booking->service_id);
        }

        $resolvedStaff = null;
        if (!empty($data['staff_id'])) {
            $resolvedStaff = User::query()->find((int) $data['staff_id']);
        } elseif ($booking->staff_id) {
            $resolvedStaff = User::query()->find((int) $booking->staff_id);
        }

        if (array_key_exists('location_id', $data) || $serviceIds !== null || !empty($data['staff_id'])) {
            $data['location_id'] = $this->resolveBookingLocationId(
                businessId: (int) $booking->business_id,
                requestedLocationId: $data['location_id'] ?? $booking->location_id,
                service: $resolvedService,
                staff: $resolvedStaff,
            );
        }

        if (array_key_exists('client_email', $data)) {
            $data['client_email'] = Booking::normalizeContactEmail($data['client_email']);
        }

        $booking->update($data);

        return response()->json([
            'data' => new BookingResource($booking->fresh()->load(['service', 'staff', 'room', 'business', 'location', 'items.service']))
        ]);
    }

    public function confirm(Request $request, Booking $booking)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        if (!$actor->isSuperAdmin() && (int)$booking->business_id !== (int)$actor->business_id) abort(404);
        if ($actor->role === User::ROLE_STAFF && (int)$booking->staff_id !== (int)$actor->id) abort(403);

        if (!in_array($booking->status, ['pending'], true)) {
            return response()->json(['message' => 'Only pending bookings can be confirmed'], 422);
        }

        $booking->update(['status' => 'confirmed']);
        return response()->json([
            'ok' => true,
            'data' => new BookingResource(
                $booking->fresh()->load(['service', 'staff', 'business', 'location', 'client', 'room', 'items.service'])
            ),
        ]);
    }

    public function noShow(Request $request, Booking $booking)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        if (!$actor->isSuperAdmin() && (int) $booking->business_id !== (int) $actor->business_id) {
            abort(404);
        }

        if (!in_array($booking->status, ['pending', 'confirmed'], true)) {
            return response()->json(['message' => 'Only pending or confirmed bookings can be marked as no-show'], 422);
        }

        $booking->update(['status' => 'no_show']);

        try {
            app(LoyaltyService::class)->reverseRedeemedForBooking($actor, $booking);
            app(GiftCardService::class)->restoreForBooking($actor, $booking);
        } catch (\Throwable $e) {}

        return response()->json(['data' => new BookingResource($booking->load(['service', 'staff', 'business', 'location', 'client', 'room', 'items.service']))]);
    }

    public function cancel(Request $request, Booking $booking)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        if (!$actor->isSuperAdmin() && (int)$booking->business_id !== (int)$actor->business_id) abort(404);
        if ($actor->role === User::ROLE_STAFF && (int)$booking->staff_id !== (int)$actor->id) abort(403);

        if (!in_array($booking->status, ['pending', 'confirmed'], true)) {
            return response()->json(['message' => 'Only pending or confirmed bookings can be cancelled'], 422);
        }

        $booking->update(['status' => 'cancelled']);

        try {
            app(LoyaltyService::class)->reverseRedeemedForBooking($actor, $booking);
            app(GiftCardService::class)->restoreForBooking($actor, $booking);
        } catch (\Throwable $e) {}

        return response()->json([
            'ok' => true,
            'cancelled_booking_id' => (int) $booking->id,
            'data' => new BookingResource(
                $booking->fresh()->load(['service', 'staff', 'business', 'location', 'client', 'room', 'items.service'])
            ),
        ]);
    }

    public function done(Request $request, Booking $booking)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        if (!$actor->isSuperAdmin() && (int)$booking->business_id !== (int)$actor->business_id) abort(404);
        if ($actor->role === User::ROLE_STAFF && (int)$booking->staff_id !== (int)$actor->id) abort(403);

        if (!in_array($booking->status, ['confirmed'], true)) {
            return response()->json(['message' => 'Only confirmed bookings can be marked as done'], 422);
        }

        $booking->update(['status' => 'done']);

        try {
            $sub = $booking->business?->subscription ?? $actor->business?->subscription;
            if ($sub && $sub->hasFeature('loyalty')) {
                app(\App\Services\LoyaltyService::class)->awardForBookingDone($actor, $booking);
            }
        } catch (\Throwable $e) {}

        return response()->json([
            'ok' => true,
            'data' => new BookingResource(
                $booking->fresh()->load(['service', 'staff', 'business', 'location', 'client', 'room', 'items.service'])
            ),
        ]);
    }

    /**
     * PATCH /api/bookings/{booking}/time
     * (drag & drop)
     * ✅ Incoming times are business local, stored as UTC
     */
    public function updateTime(Request $request, Booking $booking)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        if (!$actor->isSuperAdmin() && (int)$booking->business_id !== (int)$actor->business_id) abort(404);
        if ($actor->role === User::ROLE_STAFF && (int)$booking->staff_id !== (int)$actor->id) abort(403);

        if (!$booking->staff_id) {
            throw ValidationException::withMessages(['staff_id' => ['Booking has no staff assigned.']]);
        }

        $data = $request->validate([
            'starts_at' => ['required', 'date_format:Y-m-d H:i:s'],
            'ends_at'   => ['required', 'date_format:Y-m-d H:i:s', 'after:starts_at'],
        ]);

        /** @var Business|null $business */
        $business = $booking->relationLoaded('business')
            ? $booking->business
            : Business::query()->find((int) $booking->business_id);

        $tz = $business?->effectiveTimezone() ?? 'Asia/Yerevan';
        $step = (int) ($business?->slot_step_minutes ?? 15);

        $startsLocal = Carbon::parse($data['starts_at'], $tz)->seconds(0);
        $endsLocal   = Carbon::parse($data['ends_at'], $tz)->seconds(0);

        $durationMin = max(1, (int)ceil($startsLocal->diffInSeconds($endsLocal) / 60));
        $startsLocal = $this->snapToStep($startsLocal, $step);
        $endsLocal   = $startsLocal->copy()->addMinutes($durationMin)->seconds(0);

        $this->assertWithinBusinessHours($business, $startsLocal, $endsLocal);

        $startsUtc = $startsLocal->copy()->setTimezone('UTC');
        $endsUtc   = $endsLocal->copy()->setTimezone('UTC');

        $this->checkOverlap((int) $booking->business_id, (int) $booking->staff_id, $startsUtc, $endsUtc, (int) $booking->id);
        $this->checkBlocked((int) $booking->business_id, (int) $booking->staff_id, $startsUtc, $endsUtc);

        $booking->update([
            'starts_at' => $startsUtc->format('Y-m-d H:i:s'),
            'ends_at'   => $endsUtc->format('Y-m-d H:i:s'),
        ]);

        return response()->json([
            'ok' => true,
            'data' => new BookingResource($booking->fresh()->load(['service', 'staff', 'business', 'location', 'items.service']))
        ]);
    }

    /**
     * ========= Helpers =========
     */

    private function localDayToUtcRange(string $ymd, string $tz): array
    {
        $fromLocal = Carbon::createFromFormat('Y-m-d', $ymd, $tz)->startOfDay();
        $toLocal   = Carbon::createFromFormat('Y-m-d', $ymd, $tz)->endOfDay();

        $fromUtc = $fromLocal->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');
        $toUtc   = $toLocal->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');

        return [$fromUtc, $toUtc];
    }

    private function snapToStep(Carbon $dt, int $stepMin = 15): Carbon
    {
        $stepMin = max(1, min(60, $stepMin));
        $m = (int)$dt->minute;
        $snapped = intdiv($m, $stepMin) * $stepMin;

        return $dt->copy()->minute($snapped)->second(0);
    }

    private function resolveClientId($actor, ?Service $primaryService, string $phone, string $name, $clientId = null, ?string $email = null): int
    {
        $email = Booking::normalizeContactEmail($email);

        if (!empty($clientId)) {
            $client = Client::query()->findOrFail((int)$clientId);
            if (!$actor->isSuperAdmin() && (int)$client->business_id !== (int)$actor->business_id) abort(404);
            $updates = [];
            if (!empty($name) && $client->name !== $name) $updates['name'] = $name;
            if (!empty($email) && $client->email !== $email) $updates['email'] = $email;
            if ($updates) $client->update($updates);
            return (int)$client->id;
        }

        $businessId = $actor->isSuperAdmin()
            ? (int)($primaryService?->business_id ?? $actor->business_id)
            : (int)$actor->business_id;

        $client = Client::query()
            ->where('business_id', $businessId)
            ->where('phone', $phone)
            ->first();

        if (!$client) {
            $client = Client::create([
                'business_id' => $businessId,
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
            ]);
        } else {
            $updates = [];
            if (!empty($name) && $client->name !== $name) $updates['name'] = $name;
            if (!empty($email) && $client->email !== $email) $updates['email'] = $email;
            if ($updates) $client->update($updates);
        }

        return (int)$client->id;
    }

    private function safeNotifyNewBooking(Booking $booking): void
    {
        try {
            $booking->loadMissing(['business.owner', 'staff', 'service', 'client', 'items.service']);

            $recipients = collect([$booking->business?->owner, $booking->staff])
                ->filter()
                ->unique('id');

            foreach ($recipients as $recipient) {
                $recipient->notifyNow(new NewBookingNotification($booking));
            }

            $this->sendAdminCreatedBookingEmailToClient($booking);

            try {
                if (!empty($booking->client_phone)) {
                    $twilio = app(\App\Services\TwilioService::class);
                    $message = "Նոր ամրագրում {$booking->starts_at}";
                    $twilio->sendSMS($booking->client_phone, $message);
                    $twilio->sendWhatsApp($booking->client_phone, $message);
                }
            } catch (\Throwable $e) {
                Log::warning("Twilio send failed", ["booking_id" => $booking->id, "err" => $e->getMessage()]);
            }
        } catch (\Throwable $e) {
            Log::warning("Booking notify failed", ["booking_id" => $booking->id, "err" => $e->getMessage()]);
        }
    }

    private function safeNotifyNewGroup(array $created): void
    {
        try {
            $first = $created[0] ?? null;
            if (!$first) return;

            $first->loadMissing(['business.owner', 'service', 'client', 'staff', 'items.service']);
            $recipients = collect([$first->business?->owner])
                ->merge(User::query()->whereIn('id', collect($created)->pluck('staff_id')->filter()->unique()->values())->get())
                ->filter()
                ->unique('id');

            foreach ($recipients as $recipient) {
                $recipient->notifyNow(new NewBookingNotification($first));
            }

            $this->sendAdminCreatedBookingEmailToClient($first);
        } catch (\Throwable $e) {
            Log::warning("Multi booking notify failed", ["err" => $e->getMessage()]);
        }
    }

    private function sendAdminCreatedBookingEmailToClient(Booking $booking): void
    {
        $booking->loadMissing(['business', 'service', 'staff', 'client']);
        $email = trim((string) ($booking->contactEmail() ?? ''));
        if ($email === '') return;

        Mail::send('emails.admin_booking_created_for_client', ['booking' => $booking], function ($message) use ($email, $booking) {
            $message->to($email)->subject('Ձեզ համար նոր ամրագրում է ստեղծվել • ' . ($booking->business?->name ?? 'Vizit'));
        });
    }

    private function checkOverlap(int $businessId, int $staffId, Carbon $startUtc, Carbon $endUtc, ?int $ignoreBookingId = null): void
    {
        // DB is UTC
        $startUtc = $startUtc->copy()->setTimezone('UTC')->seconds(0);
        $endUtc   = $endUtc->copy()->setTimezone('UTC')->seconds(0);

        $query = Booking::query()
            ->where('business_id', $businessId)
            ->where('staff_id', $staffId)
            ->whereNotIn('status', ['cancelled'])
            ->where('starts_at', '<', $endUtc->format('Y-m-d H:i:s'))
            ->where('ends_at', '>', $startUtc->format('Y-m-d H:i:s'));

        if ($ignoreBookingId) {
            $query->where('id', '!=', $ignoreBookingId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'starts_at' => ['This time slot is already booked'],
            ]);
        }
    }

    private function checkBlocked(int $businessId, int $staffId, Carbon $startUtc, Carbon $endUtc): void
    {
        $startUtc = $startUtc->copy()->setTimezone('UTC')->seconds(0);
        $endUtc   = $endUtc->copy()->setTimezone('UTC')->seconds(0);

        $blocked = BookingBlock::query()
            ->where('business_id', $businessId)
            ->where(function ($q) use ($staffId) {
                $q->whereNull('staff_id')->orWhere('staff_id', $staffId);
            })
            ->where('starts_at', '<', $endUtc->format('Y-m-d H:i:s'))
            ->where('ends_at', '>', $startUtc->format('Y-m-d H:i:s'))
            ->exists();

        if ($blocked) {
            throw ValidationException::withMessages([
                'starts_at' => ['This time is blocked (break / day off).'],
            ]);
        }
    }

    private function assertWithinBusinessHours(?Business $business, Carbon $startLocal, Carbon $endLocal, string $field = 'starts_at'): void
    {
        $workStart = $business?->work_start ?: '09:00';
        $workEnd = $business?->work_end ?: '18:00';

        $windowStart = Carbon::parse($startLocal->format('Y-m-d') . ' ' . $workStart, $startLocal->getTimezone())->seconds(0);
        $windowEnd = Carbon::parse($startLocal->format('Y-m-d') . ' ' . $workEnd, $startLocal->getTimezone())->seconds(0);
        if ($windowEnd->lte($windowStart)) {
            $windowEnd = $windowEnd->addDay();
        }

        if ($startLocal->lt($windowStart) || $endLocal->gt($windowEnd)) {
            throw ValidationException::withMessages([
                $field => ['Selected time is outside business working hours.'],
            ]);
        }
    }

    private function assertSingleBookingServicesCompatible($services, ?User $staff = null): void
    {
        $services = collect($services)->filter();
        if ($services->isEmpty()) {
            return;
        }

        $serviceLocationIds = $services
            ->map(fn (Service $service) => (int) ($service->location_id ?? 0))
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        foreach ($services as $service) {
            if (!(bool) $service->is_active) {
                throw ValidationException::withMessages([
                    'service_id' => ['Selected service is inactive.'],
                ]);
            }
        }

        if ($serviceLocationIds->count() > 1) {
            throw ValidationException::withMessages([
                'service_id' => ['Selected services must belong to the same location.'],
            ]);
        }

        if ($staff) {
            if (!(bool) $staff->is_active) {
                throw ValidationException::withMessages([
                    'staff_id' => ['Selected staff member is inactive.'],
                ]);
            }

            if ($staff->location_id && $serviceLocationIds->count() === 1 && (int) $staff->location_id !== (int) $serviceLocationIds->first()) {
                throw ValidationException::withMessages([
                    'staff_id' => ['Selected staff member does not work at the service location.'],
                ]);
            }
        }
    }

    private function assertPreparedLinesDoNotOverlap(array $prepared): void
    {
        foreach ($prepared as $index => $current) {
            for ($j = $index + 1; $j < count($prepared); $j++) {
                $other = $prepared[$j];
                if ((int) $current['staff']->id !== (int) $other['staff']->id) {
                    continue;
                }

                if ($current['startUtc']->lt($other['endUtc']) && $current['endUtc']->gt($other['startUtc'])) {
                    throw ValidationException::withMessages([
                        'lines' => ['One staff member has overlapping lines in the same booking request.'],
                    ]);
                }
            }
        }
    }

    private function applyBookingBenefitsFromPayload($actor, Booking $booking, array $payload, ?int $clientId, ?int $grossAmount): void
    {
        if ($grossAmount === null || $grossAmount <= 0) {
            return;
        }

        $requestedPoints = (int) ($payload['redeem_points'] ?? 0);
        $giftCardCode = trim((string) ($payload['gift_card_code'] ?? ''));
        $requestedGiftAmount = (int) ($payload['gift_card_amount'] ?? 0);

        if ($requestedPoints <= 0 && $giftCardCode === '') {
            return;
        }

        $netAmount = $grossAmount;
        $appliedPoints = 0;
        $loyaltyDiscount = 0;
        $giftDiscount = 0;

        if ($requestedPoints > 0) {
            if (!$clientId) {
                throw ValidationException::withMessages(['redeem_points' => ['Միավորներ կիրառելու համար պետք է հաճախորդը կապվի booking-ին։']]);
            }
            $client = Client::query()->findOrFail($clientId);
            $loyaltyService = app(LoyaltyService::class);
            $program = $loyaltyService->getOrCreateProgram((int) $booking->business_id);
            if ($giftCardCode !== '' && !$program->allow_gift_card_with_points) {
                throw ValidationException::withMessages(['gift_card_code' => ['Տվյալ loyalty ծրագրում չի թույլատրվում միաժամանակ օգտագործել միավորներ և նվերի քարտ։']]);
            }
            $loyaltyResult = $loyaltyService->redeemForBooking($actor, $client, $booking, $requestedPoints, $grossAmount);
            $appliedPoints = (int) ($loyaltyResult['applied_points'] ?? 0);
            $loyaltyDiscount = (int) ($loyaltyResult['discount_amount'] ?? 0);
            $netAmount -= $loyaltyDiscount;
        }

        if ($giftCardCode !== '') {
            $giftCardService = app(GiftCardService::class);
            $giftCard = $giftCardService->lookupActiveByCode((int) $booking->business_id, $giftCardCode);
            $giftResult = $giftCardService->redeemForBooking($actor, $giftCard, $booking, $requestedGiftAmount > 0 ? $requestedGiftAmount : $netAmount);
            $giftDiscount = (int) ($giftResult['amount'] ?? 0);
            $netAmount -= $giftDiscount;
        }

        $booking->final_price = max(0, $netAmount);
        $booking->source_meta = array_merge((array) ($booking->source_meta ?? []), [
            'gross_amount' => $grossAmount,
            'loyalty_applied_points' => $appliedPoints,
            'loyalty_discount_amount' => $loyaltyDiscount,
            'gift_card_code' => $giftCardCode !== '' ? strtoupper($giftCardCode) : null,
            'gift_card_discount_amount' => $giftDiscount,
        ]);
        $booking->save();
    }

    private function generateBookingCode(): string
    {
        do {
            $code = 'BK-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
        } while (Booking::where('booking_code', $code)->exists());

        return $code;
    }

    private function resolveBookingLocationId(int $businessId, ?int $requestedLocationId = null, ?Service $service = null, ?User $staff = null): ?int
    {
        $resolvedLocationId = $requestedLocationId
            ?: (int) ($service?->location_id ?? 0)
            ?: (int) ($staff?->location_id ?? 0)
            ?: (int) (BusinessLocation::query()->where('business_id', $businessId)->where('is_primary', true)->value('id') ?? 0)
            ?: (int) (BusinessLocation::query()->where('business_id', $businessId)->orderBy('sort_order')->orderBy('id')->value('id') ?? 0);

        if (!$resolvedLocationId) {
            return null;
        }

        $location = BusinessLocation::query()
            ->where('business_id', $businessId)
            ->find($resolvedLocationId);

        if (!$location) {
            throw ValidationException::withMessages(['location_id' => ['Invalid location.']]);
        }

        if ($service && $service->location_id && (int) $service->location_id !== (int) $location->id) {
            throw ValidationException::withMessages(['location_id' => ['Selected service belongs to another location.']]);
        }

        if ($staff && $staff->location_id && (int) $staff->location_id !== (int) $location->id) {
            throw ValidationException::withMessages(['location_id' => ['Selected staff belongs to another location.']]);
        }

        return (int) $location->id;
    }
}
