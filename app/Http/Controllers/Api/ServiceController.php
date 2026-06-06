<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\Request;
use App\Support\InteractsWithOptionalLocationColumns;

class ServiceController extends Controller
{
    use InteractsWithOptionalLocationColumns;
    public function index(Request $request)
    {
        $actor = $request->user();
        if (!$actor) abort(401);

        $validated = $request->validate([
            'business_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $q = Service::query()->with('location');
        if (!$actor->isSuperAdmin()) {
            $q->where('business_id', $actor->business_id);
        } elseif (!empty($validated['business_id'])) {
            $q->where('business_id', (int) $validated['business_id']);
        }

        if (!empty($validated['location_id'])) {
            $this->applyTableLocationCompatibility($q, (int) $validated['location_id'], 'services');
        }

        return response()->json(['data' => $q->orderBy('id')->get()]);
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        if (!in_array($actor->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_SUPER_ADMIN], true)) {
            abort(403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:600'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
            'business_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $businessId = $actor->business_id;
        if ($actor->isSuperAdmin() && !empty($data['business_id'])) {
            $businessId = (int) $data['business_id'];
        }

        $business = Business::query()->with('subscription.plan')->findOrFail($businessId);
        $isActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;

        if ($isActive && !$business->hasAvailableServiceSlot()) {
            return response()->json([
                'message' => 'Active service limit reached. Upgrade the plan or deactivate/delete another active service.',
                'limit' => $business->serviceLimit(),
                'current' => $business->activeServiceCount(),
            ], 409);
        }

        $locationId = $this->resolveLocationId($businessId, $data['location_id'] ?? null, true);

        $servicePayload = [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'duration_minutes' => (int) $data['duration_minutes'],
            'price' => $data['price'] ?? null,
            'currency' => $data['currency'] ?? 'AMD',
            'image_url' => $data['image_url'] ?? null,
            'is_active' => $isActive,
            'business_id' => $businessId,
            'location_id' => $locationId,
        ];

        $service = Service::create($this->withoutUnavailableLocationAttribute($servicePayload, 'services'));

        return response()->json(['data' => $service->load('location')], 201);
    }

    public function update(Request $request, Service $service)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        if (!$actor->isSuperAdmin() && (int) $service->business_id !== (int) $actor->business_id) {
            abort(404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration_minutes' => ['sometimes', 'required', 'integer', 'min:5', 'max:600'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
            'location_id' => ['nullable', 'integer'],
        ]);

        if (array_key_exists('location_id', $data)) {
            $data['location_id'] = $this->resolveLocationId((int) $service->business_id, $data['location_id'], true);
        }

        $data = $this->withoutUnavailableLocationAttribute($data, 'services');

        $business = Business::query()->with('subscription.plan')->findOrFail((int) $service->business_id);
        $wantsActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $service->is_active;
        $currentlyActive = (bool) $service->is_active;

        if ($wantsActive && !$currentlyActive && !$business->hasAvailableServiceSlot()) {
            return response()->json([
                'message' => 'Active service limit reached. Upgrade the plan or deactivate/delete another active service.',
                'limit' => $business->serviceLimit(),
                'current' => $business->activeServiceCount(),
            ], 409);
        }

        $service->update($data);
        return response()->json(['data' => $service->fresh()->load('location')]);
    }

    public function destroy(Request $request, Service $service)
    {
        $actor = $request->user();
        if (!$actor) abort(401);
        if (!$actor->isSuperAdmin() && (int) $service->business_id !== (int) $actor->business_id) {
            abort(404);
        }

        $service->delete();
        return response()->json(['ok' => true]);
    }


    private function resolveLocationId(int $businessId, ?int $locationId, bool $requireSpecificWhenMultiple = false): ?int
    {
        if (!$this->servicesHaveLocationColumn()) {
            return null;
        }

        $locations = BusinessLocation::query()
            ->where('business_id', $businessId)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get(['id']);

        if (!$locationId) {
            if ($locations->count() === 1) {
                return (int) $locations->first()->id;
            }

            if ($requireSpecificWhenMultiple && $locations->count() > 1) {
                abort(422, 'Choose a specific location for this service.');
            }

            return null;
        }

        $exists = $locations->contains(fn ($location) => (int) $location->id === (int) $locationId);

        abort_unless($exists, 422, 'Invalid location');

        return (int) $locationId;
    }
}
