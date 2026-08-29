<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Service;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\WaitlistService;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class WaitlistController extends Controller
{
    public function publicStore(string $slug, Request $request)
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer'],
            'staff_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'customer_name' => ['required', 'string', 'min:2', 'max:120'],
            'customer_phone' => ['required', 'string', 'min:5', 'max:40'],
            'customer_email' => ['required', 'email', 'max:150'],
            'desired_date' => ['required', 'date_format:Y-m-d'],
            'window_start' => ['nullable', 'date_format:H:i'],
            'window_end' => ['nullable', 'date_format:H:i', 'after:window_start'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'source' => ['nullable', 'in:website,widget,instagram,facebook,whatsapp,qr'],
        ]);

        $business = $this->publicBusiness($slug);
        $service = Service::query()
            ->whereKey((int) $data['service_id'])
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->firstOrFail();

        $desiredDate = Carbon::createFromFormat('Y-m-d', $data['desired_date'], $business->effectiveTimezone())->startOfDay();
        if ($desiredDate->lt(Carbon::now($business->effectiveTimezone())->startOfDay())) {
            return response()->json(['message' => 'The requested date is in the past.'], 422);
        }

        $staff = null;
        if (!empty($data['staff_id'])) {
            $staff = User::query()
                ->whereKey((int) $data['staff_id'])
                ->where('business_id', $business->id)
                ->where('is_active', true)
                ->where('is_bookable', true)
                ->first();
            if (!$staff) {
                return response()->json(['message' => 'Invalid specialist.'], 422);
            }
        }

        $location = null;
        if (!empty($data['location_id'])) {
            $location = BusinessLocation::query()
                ->whereKey((int) $data['location_id'])
                ->where('business_id', $business->id)
                ->where('is_active', true)
                ->first();
            if (!$location) {
                return response()->json(['message' => 'Invalid location.'], 422);
            }
        }

        $resolvedLocationId = $location?->id ?: $service->location_id ?: $staff?->location_id;
        if ($resolvedLocationId && $service->location_id && (int) $resolvedLocationId !== (int) $service->location_id) {
            return response()->json(['message' => 'The service is not available at this location.'], 422);
        }
        if ($resolvedLocationId && $staff?->location_id && (int) $resolvedLocationId !== (int) $staff->location_id) {
            return response()->json(['message' => 'The specialist is not available at this location.'], 422);
        }
        $partySize = max(1, (int) ($data['party_size'] ?? 1));
        if (($service->booking_mode ?? 'individual') !== 'group' && $partySize > 1) {
            return response()->json(['message' => 'This service is booked for one customer at a time.'], 422);
        }
        if ($partySize > max(1, (int) $service->capacity)) {
            return response()->json(['message' => 'The group size exceeds the service capacity.'], 422);
        }

        $phone = Phone::normalizeAM($data['customer_phone']);
        if (!$phone) {
            return response()->json(['message' => 'Invalid phone number.'], 422);
        }

        $entry = WaitlistEntry::query()->create([
            'business_id' => $business->id,
            'location_id' => $resolvedLocationId ?: null,
            'service_id' => $service->id,
            'staff_id' => $staff?->id,
            'customer_name' => $data['customer_name'],
            'customer_phone' => $phone,
            'customer_email' => mb_strtolower(trim($data['customer_email'])),
            'desired_date' => $data['desired_date'],
            'window_start' => $data['window_start'] ?? null,
            'window_end' => $data['window_end'] ?? null,
            'party_size' => $partySize,
            'status' => 'waiting',
            'source' => $data['source'] ?? 'website',
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['data' => $this->payload($entry->load(['business', 'service', 'staff']))], 201);
    }

    public function publicOffer(string $slug, WaitlistEntry $entry, Request $request)
    {
        $business = $this->publicBusiness($slug);
        abort_unless((int) $entry->business_id === (int) $business->id, 404);
        $token = (string) $request->query('token', '');
        abort_unless($token !== '' && hash_equals((string) $entry->offer_token_hash, hash('sha256', $token)), 404);
        if ($entry->status !== 'offered' || !$entry->offer_expires_at || $entry->offer_expires_at->isPast()) {
            if ($entry->status === 'offered') {
                $entry->update(['status' => 'expired', 'offer_token_hash' => null]);
            }
            abort(410, 'This waiting-list offer has expired.');
        }

        return response()->json(['data' => $this->payload($entry->load(['service', 'offeredStaff', 'business']))]);
    }

    public function publicAccept(string $slug, WaitlistEntry $entry, Request $request, WaitlistService $service)
    {
        $business = $this->publicBusiness($slug);
        abort_unless((int) $entry->business_id === (int) $business->id, 404);
        $data = $request->validate(['token' => ['required', 'string', 'max:100']]);
        $accepted = $service->acceptOffer($entry, $data['token']);
        $booking = $accepted['booking'];

        return response()->json(['data' => [
            'booking_code' => $booking->booking_code,
            'guest_token' => $accepted['guest_token'],
            'starts_at' => $booking->starts_at?->timezone($business->effectiveTimezone())->format('Y-m-d H:i:s'),
            'status' => $booking->status,
        ]], 201);
    }

    public function index(Request $request)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        $data = $request->validate([
            'status' => ['nullable', 'in:waiting,offered,booked,cancelled,expired'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        WaitlistEntry::query()
            ->where('business_id', $actor->business_id)
            ->where('status', 'offered')
            ->where('offer_expires_at', '<=', now())
            ->update(['status' => 'expired', 'offer_token_hash' => null]);

        $query = WaitlistEntry::query()
            ->with(['business', 'service', 'staff', 'offeredStaff', 'location'])
            ->where('business_id', $actor->business_id)
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['date'] ?? null, fn ($q, $date) => $q->whereDate('desired_date', $date))
            ->orderByRaw("CASE status WHEN 'waiting' THEN 0 WHEN 'offered' THEN 1 ELSE 2 END")
            ->orderBy('desired_date')
            ->orderBy('created_at');

        return response()->json(['data' => $query->limit(500)->get()->map(fn ($entry) => $this->payload($entry))]);
    }

    public function offer(Request $request, WaitlistEntry $entry, WaitlistService $service)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        abort_unless((int) $entry->business_id === (int) $actor->business_id, 404);
        abort_unless($entry->status === 'waiting', 422, 'Only waiting entries can receive an offer.');
        $data = $request->validate([
            'staff_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date_format:Y-m-d H:i'],
        ]);
        $entry->loadMissing('business');
        $start = Carbon::createFromFormat('Y-m-d H:i', $data['starts_at'], $entry->business->effectiveTimezone())->seconds(0);
        $offered = $service->offerEntry($entry, (int) $data['staff_id'], $start);

        return response()->json(['data' => $this->payload($offered)]);
    }

    public function update(Request $request, WaitlistEntry $entry)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        abort_unless((int) $entry->business_id === (int) $actor->business_id, 404);
        $data = $request->validate([
            'status' => ['required', 'in:waiting,cancelled'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $entry->update([
            'status' => $data['status'],
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $entry->notes,
            'offer_token_hash' => null,
            'offer_expires_at' => null,
            'offered_staff_id' => null,
            'offered_starts_at' => null,
            'offered_ends_at' => null,
            'notified_at' => null,
        ]);

        return response()->json(['data' => $this->payload($entry->fresh(['service', 'staff', 'offeredStaff', 'location']))]);
    }

    private function publicBusiness(string $slug): Business
    {
        return Business::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->where('is_onboarding_completed', true)
            ->where('is_public_profile_enabled', true)
            ->firstOrFail();
    }

    private function payload(WaitlistEntry $entry): array
    {
        $timezone = $entry->business?->effectiveTimezone() ?? 'Asia/Yerevan';
        return [
            'id' => $entry->id,
            'business_id' => $entry->business_id,
            'location_id' => $entry->location_id,
            'service_id' => $entry->service_id,
            'staff_id' => $entry->staff_id,
            'customer_name' => $entry->customer_name,
            'customer_phone' => $entry->customer_phone,
            'customer_email' => $entry->customer_email,
            'desired_date' => $entry->desired_date?->format('Y-m-d'),
            'window_start' => $entry->window_start,
            'window_end' => $entry->window_end,
            'party_size' => (int) $entry->party_size,
            'status' => $entry->status,
            'offered_starts_at' => $entry->offered_starts_at?->timezone($timezone)->format('Y-m-d H:i:s'),
            'offered_ends_at' => $entry->offered_ends_at?->timezone($timezone)->format('Y-m-d H:i:s'),
            'offer_expires_at' => $entry->offer_expires_at?->toISOString(),
            'notes' => $entry->notes,
            'service' => $entry->service ? [
                'id' => $entry->service->id,
                'name' => $entry->service->name,
                'booking_mode' => $entry->service->booking_mode ?? 'individual',
                'capacity' => (int) ($entry->service->capacity ?? 1),
            ] : null,
            'staff' => $entry->staff ? ['id' => $entry->staff->id, 'name' => $entry->staff->name] : null,
            'offered_staff' => $entry->offeredStaff ? ['id' => $entry->offeredStaff->id, 'name' => $entry->offeredStaff->name] : null,
            'created_at' => $entry->created_at?->toISOString(),
        ];
    }
}
