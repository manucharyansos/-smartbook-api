<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessCategory;
use App\Models\BusinessLocation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Support\BusinessVertical;

class BusinessSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->business_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $business = $user->business()->with(['locations', 'category', 'subscription.plan'])->firstOrFail();

        return response()->json([
            'data' => $this->serializeSettings($business),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->merge([
            'work_start' => $this->normalizeTimeInput($request->input('work_start')),
            'work_end' => $this->normalizeTimeInput($request->input('work_end')),
        ]);

        $user = $request->user();
        if (!$user || !$user->business_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if (!in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_SUPER_ADMIN], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'vertical' => ['nullable', 'string', Rule::in(BusinessVertical::values())],
            'business_category_id' => ['nullable', 'integer', 'exists:business_categories,id'],
            'custom_category_name' => ['nullable', 'string', 'max:120'],
            'short_description' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'logo_url' => ['nullable', 'string', 'max:2048'],
            'cover_url' => ['nullable', 'string', 'max:2048'],
            'timezone' => ['nullable', 'string', 'max:60', Rule::in(timezone_identifiers_list())],
            'instagram_url' => ['nullable', 'url:http,https', 'max:2048'],
            'facebook_url' => ['nullable', 'url:http,https', 'max:2048'],
            'website_url' => ['nullable', 'url:http,https', 'max:2048'],
            'messenger_url' => ['nullable', 'url:http,https', 'max:2048'],
            'whatsapp_url' => ['nullable', 'url:http,https', 'max:2048'],
            'slot_step_minutes' => ['nullable', 'integer', 'min:5', 'max:60'],
            'work_start' => ['nullable', 'date_format:H:i'],
            'work_end' => ['nullable', 'date_format:H:i', 'after:work_start'],
            'is_public_profile_enabled' => ['nullable', 'boolean'],
            'is_marketplace_visible' => ['nullable', 'boolean'],
            'show_logo' => ['nullable', 'boolean'],
            'show_cover' => ['nullable', 'boolean'],
            'show_staff' => ['nullable', 'boolean'],
            'show_services' => ['nullable', 'boolean'],
        ]);

        /** @var Business $business */
        $business = $user->business()->with(['locations', 'category', 'subscription.plan'])->firstOrFail();
        $nextVertical = array_key_exists('vertical', $data)
            ? BusinessVertical::normalize($data['vertical'])
            : $business->normalizedVertical();

        $updates = [
            'business_type' => $nextVertical,
            'vertical' => $nextVertical,
            'business_category_id' => array_key_exists('business_category_id', $data) ? $data['business_category_id'] : $business->business_category_id,
            'custom_category_name' => array_key_exists('custom_category_name', $data) ? $data['custom_category_name'] : $business->custom_category_name,
            'phone' => $data['phone'] ?? $business->phone,
            'address' => $data['address'] ?? $business->address,
            'short_description' => array_key_exists('short_description', $data) ? $data['short_description'] : $business->short_description,
            'description' => array_key_exists('description', $data) ? $data['description'] : $business->description,
            'logo_url' => array_key_exists('logo_url', $data) ? $data['logo_url'] : $business->logo_url,
            'cover_url' => array_key_exists('cover_url', $data) ? $data['cover_url'] : $business->cover_url,
            'timezone' => $data['timezone'] ?? $business->timezone,
            'instagram_url' => array_key_exists('instagram_url', $data) ? $data['instagram_url'] : $business->instagram_url,
            'facebook_url' => array_key_exists('facebook_url', $data) ? $data['facebook_url'] : $business->facebook_url,
            'website_url' => array_key_exists('website_url', $data) ? $data['website_url'] : $business->website_url,
            'messenger_url' => array_key_exists('messenger_url', $data) ? $data['messenger_url'] : $business->messenger_url,
            'whatsapp_url' => array_key_exists('whatsapp_url', $data) ? $data['whatsapp_url'] : $business->whatsapp_url,
            'slot_step_minutes' => $data['slot_step_minutes'] ?? $business->slot_step_minutes,
            'work_start' => $data['work_start'] ?? $business->work_start,
            'work_end' => $data['work_end'] ?? $business->work_end,
            'show_logo' => array_key_exists('show_logo', $data) ? (bool) $data['show_logo'] : (bool) ($business->show_logo ?? true),
            'show_cover' => array_key_exists('show_cover', $data) ? (bool) $data['show_cover'] : (bool) ($business->show_cover ?? true),
            'show_staff' => array_key_exists('show_staff', $data) ? (bool) $data['show_staff'] : (bool) ($business->show_staff ?? true),
            'show_services' => array_key_exists('show_services', $data) ? (bool) $data['show_services'] : (bool) ($business->show_services ?? true),
        ];

        $profileEnabled = array_key_exists('is_public_profile_enabled', $data)
            ? (bool) $data['is_public_profile_enabled']
            : (bool) ($business->is_public_profile_enabled ?? $business->is_public);
        $marketplaceVisible = array_key_exists('is_marketplace_visible', $data)
            ? (bool) $data['is_marketplace_visible']
            : (bool) ($business->is_marketplace_visible ?? $business->is_public);

        // Keep the old flag synchronized while the new flags separate public profile
        // visibility from homepage/marketplace visibility.
        $updates['is_public'] = $profileEnabled || $marketplaceVisible;
        if (Schema::hasColumn('businesses', 'is_public_profile_enabled')) {
            $updates['is_public_profile_enabled'] = $profileEnabled;
        }
        if (Schema::hasColumn('businesses', 'is_marketplace_visible')) {
            $updates['is_marketplace_visible'] = $marketplaceVisible;
        }

        $business->update($updates);

        if (array_key_exists('address', $data)) {
            $primary = $business->locations()->where('is_primary', true)->first();
            $address = trim((string) ($data['address'] ?? ''));

            if ($address !== '') {
                if ($primary) {
                    $primary->update([
                        'address' => $address,
                        'phone' => $business->phone,
                    ]);
                } else {
                    $business->locations()->create([
                        'name' => $business->name,
                        'address' => $address,
                        'phone' => $business->phone,
                        'is_primary' => true,
                        'is_active' => true,
                        'sort_order' => 1,
                    ]);
                }
            }
        }

        $business->refresh()->load(['locations', 'category', 'subscription.plan']);

        return response()->json([
            'ok' => true,
            'data' => $this->serializeSettings($business),
        ]);
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $business = $this->authorizedBusiness($request, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_SUPER_ADMIN]);
        $limit = $business->locationLimit();

        if ($business->locations()->count() >= $limit) {
            return response()->json([
                'message' => $limit > 1
                    ? "Քո պլանը թույլ է տալիս մինչև {$limit} հասցե։"
                    : 'Քո ընթացիկ պլանը թույլ է տալիս միայն 1 հասցե։',
            ], 422);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_primary' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (!empty($data['is_primary'])) {
            $business->locations()->update(['is_primary' => false]);
        }

        $location = $business->locations()->create([
            'name' => $data['name'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'district' => $data['district'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'phone' => $data['phone'] ?? $business->phone,
            'is_primary' => (bool) ($data['is_primary'] ?? ($business->locations()->count() === 0)),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => ((int) $business->locations()->max('sort_order')) + 1,
        ]);

        if ($location->is_primary || !$business->locations()->where('is_primary', true)->exists()) {
            $business->locations()->where('id', '!=', $location->id)->update(['is_primary' => false]);
            $location->forceFill(['is_primary' => true])->save();
            $business->syncPrimaryAddressFromLocations();
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'location' => $this->serializeLocation($location->fresh()),
                'locations' => $business->fresh()->locations()->orderBy('sort_order')->orderBy('id')->get()->map(fn (BusinessLocation $item) => $this->serializeLocation($item))->values(),
                'location_limit' => $limit,
            ],
        ]);
    }

    public function updateLocation(Request $request, BusinessLocation $location): JsonResponse
    {
        $business = $this->authorizedBusiness($request, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_SUPER_ADMIN]);
        $this->assertLocationBelongsToBusiness($location, $business);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_primary' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (!empty($data['is_primary'])) {
            $business->locations()->update(['is_primary' => false]);
        }

        $location->update([
            'name' => array_key_exists('name', $data) ? $data['name'] : $location->name,
            'address' => array_key_exists('address', $data) ? $data['address'] : $location->address,
            'city' => array_key_exists('city', $data) ? $data['city'] : $location->city,
            'district' => array_key_exists('district', $data) ? $data['district'] : $location->district,
            'latitude' => array_key_exists('latitude', $data) ? $data['latitude'] : $location->latitude,
            'longitude' => array_key_exists('longitude', $data) ? $data['longitude'] : $location->longitude,
            'phone' => $data['phone'] ?? $location->phone,
            'is_primary' => array_key_exists('is_primary', $data) ? (bool) $data['is_primary'] : $location->is_primary,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $location->is_active,
        ]);

        if (!$business->locations()->where('is_primary', true)->exists()) {
            $location->forceFill(['is_primary' => true])->save();
        }

        $business->syncPrimaryAddressFromLocations();

        return response()->json([
            'ok' => true,
            'data' => [
                'location' => $this->serializeLocation($location->fresh()),
                'locations' => $business->fresh()->locations()->orderBy('sort_order')->orderBy('id')->get()->map(fn (BusinessLocation $item) => $this->serializeLocation($item))->values(),
                'location_limit' => $business->locationLimit(),
            ],
        ]);
    }

    public function destroyLocation(Request $request, BusinessLocation $location): JsonResponse
    {
        $business = $this->authorizedBusiness($request, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_SUPER_ADMIN]);
        $this->assertLocationBelongsToBusiness($location, $business);

        if ($business->locations()->count() <= 1) {
            return response()->json(['message' => 'Առնվազն մեկ հասցե պետք է պահպանվի։'], 422);
        }

        $wasPrimary = $location->is_primary;
        $location->delete();

        if ($wasPrimary) {
            $fallback = $business->locations()->orderBy('sort_order')->orderBy('id')->first();
            if ($fallback) {
                $business->locations()->update(['is_primary' => false]);
                $fallback->forceFill(['is_primary' => true])->save();
            }
        }

        $business->syncPrimaryAddressFromLocations();

        return response()->json([
            'ok' => true,
            'data' => [
                'locations' => $business->fresh()->locations()->orderBy('sort_order')->orderBy('id')->get()->map(fn (BusinessLocation $item) => $this->serializeLocation($item))->values(),
                'location_limit' => $business->locationLimit(),
            ],
        ]);
    }

    public function updateWorkingHours(Request $request)
    {
        $normalizedWorkingHours = collect((array) $request->input('working_hours', []))
            ->map(function ($hours) {
                if (!is_array($hours)) {
                    return $hours;
                }

                $hours['start'] = $this->normalizeTimeInput($hours['start'] ?? null);
                $hours['end'] = $this->normalizeTimeInput($hours['end'] ?? null);
                $hours['break_start'] = $this->normalizeTimeInput($hours['break_start'] ?? null);
                $hours['break_end'] = $this->normalizeTimeInput($hours['break_end'] ?? null);

                return $hours;
            })
            ->all();

        $request->merge(['working_hours' => $normalizedWorkingHours]);

        $user = $request->user();
        if (!$user || !$user->business_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        if (!in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'working_hours' => ['required', 'array', 'size:7'],
            'working_hours.*.weekday' => ['required', 'integer', 'between:1,7'],
            'working_hours.*.is_closed' => ['required', 'boolean'],
            'working_hours.*.start' => ['required_if:is_closed,false', 'nullable', 'date_format:H:i'],
            'working_hours.*.end' => ['required_if:is_closed,false', 'nullable', 'date_format:H:i'],
            'working_hours.*.break_start' => ['nullable', 'date_format:H:i'],
            'working_hours.*.break_end' => ['nullable', 'date_format:H:i'],
        ]);

        $business = $user->business;
        $business->workingHours()->delete();
        foreach ($data['working_hours'] as $hours) {
            $business->workingHours()->create([
                'weekday' => $hours['weekday'],
                'is_closed' => $hours['is_closed'],
                'start' => $hours['start'] ?? null,
                'end' => $hours['end'] ?? null,
                'break_start' => $hours['break_start'] ?? null,
                'break_end' => $hours['break_end'] ?? null,
            ]);
        }
        return response()->json(['ok' => true]);
    }

    public function stats(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->business_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $business = $user->business;
        return response()->json(['data' => [
            'staff_count' => $business->activeSeatCount(),
            'services_count' => $business->activeServiceCount(),
            'clients_count' => $business->clients()->count(),
            'bookings_today' => $business->bookings()->whereDate('starts_at', today())->count(),
            'bookings_this_week' => $business->bookings()->whereBetween('starts_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'revenue_this_month' => $business->bookings()->whereIn('status', ['confirmed', 'done'])->whereMonth('starts_at', now()->month)->sum('final_price'),
        ]]);
    }

    private function authorizedBusiness(Request $request, array $roles): Business
    {
        $user = $request->user();
        if (!$user || !$user->business_id || !in_array($user->role, $roles, true)) {
            throw new HttpException(403, 'Forbidden');
        }

        return $user->business()->with(['locations', 'category', 'subscription.plan'])->firstOrFail();
    }

    private function assertLocationBelongsToBusiness(BusinessLocation $location, Business $business): void
    {
        if ((int) $location->business_id !== (int) $business->id) {
            throw new HttpException(404, 'Location not found');
        }
    }

    private function serializeSettings(Business $business): array
    {
        $workingHours = $business->workingHours()->orderBy('weekday')->get(['weekday', 'is_closed', 'start', 'end', 'break_start', 'break_end']);
        $locations = $business->locations()->orderBy('sort_order')->orderBy('id')->get()->map(fn (BusinessLocation $location) => $this->serializeLocation($location))->values();

        $serviceLimit = $business->serviceLimit();
        $staffLimit = $business->seatLimit();
        $locationLimit = $business->locationLimit();
        $servicesCount = $business->activeServiceCount();
        $staffCount = $business->activeSeatCount();
        $locationsCount = $business->locationCount();

        return [
            'id' => $business->id,
            'name' => $business->name,
            'slug' => $business->slug,
            'business_type' => $business->business_type,
            'vertical' => $business->normalizedVertical(),
            'business_category_id' => $business->business_category_id,
            'custom_category_name' => $business->custom_category_name,
            'category' => $business->category ? [
                'id' => $business->category->id,
                'slug' => $business->category->slug,
                'vertical' => $business->category->vertical,
                'name_hy' => $business->category->name_hy,
                'name_ru' => $business->category->name_ru,
                'name_en' => $business->category->name_en,
                'icon' => $business->category->icon,
            ] : null,
            'phone' => $business->phone,
            'address' => $business->address,
            'short_description' => $business->short_description,
            'description' => $business->description,
            'logo_url' => $business->logo_url,
            'cover_url' => $business->cover_url,
            'timezone' => $business->effectiveTimezone(),
            'instagram_url' => $business->instagram_url,
            'facebook_url' => $business->facebook_url,
            'website_url' => $business->website_url,
            'messenger_url' => $business->messenger_url,
            'whatsapp_url' => $business->whatsapp_url,
            'slot_step_minutes' => $business->slot_step_minutes ?? 15,
            'work_start' => $this->serializeTime($business->work_start),
            'work_end' => $this->serializeTime($business->work_end),
            'is_onboarding_completed' => $business->is_onboarding_completed,
            'is_public' => (bool) $business->is_public,
            'is_public_profile_enabled' => (bool) ($business->is_public_profile_enabled ?? $business->is_public),
            'is_marketplace_visible' => (bool) ($business->is_marketplace_visible ?? $business->is_public),
            'show_logo' => (bool) ($business->show_logo ?? true),
            'show_cover' => (bool) ($business->show_cover ?? true),
            'show_staff' => (bool) ($business->show_staff ?? true),
            'show_services' => (bool) ($business->show_services ?? true),
            'working_hours' => $workingHours->map(function ($hours) {
                return [
                    'weekday' => (int) $hours->weekday,
                    'is_closed' => (bool) $hours->is_closed,
                    'start' => $this->serializeTime($hours->start),
                    'end' => $this->serializeTime($hours->end),
                    'break_start' => $this->serializeTime($hours->break_start),
                    'break_end' => $this->serializeTime($hours->break_end),
                ];
            })->values(),
            'locations' => $locations,
            'location_limit' => $locationLimit,
            'service_limit' => $serviceLimit,
            'plan' => [
                'code' => $business->subscription?->plan?->code,
                'name' => $business->subscription?->plan?->name,
            ],
            'usage' => [
                'active_staff' => $staffCount,
                'staff_limit' => $staffLimit,
                'staff_remaining' => $staffLimit ? max($staffLimit - $staffCount, 0) : null,
                'services_count' => $servicesCount,
                'services_limit' => $serviceLimit,
                'services_remaining' => $serviceLimit ? max($serviceLimit - $servicesCount, 0) : null,
                'locations_count' => $locationsCount,
                'locations_limit' => $locationLimit,
                'locations_remaining' => max($locationLimit - $locationsCount, 0),
            ],
        ];
    }


    private function serializeTime($value): ?string
    {
        return $this->normalizeTimeInput($value);
    }

    private function normalizeTimeInput($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $time = trim((string) $value);
        if ($time == '') {
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

    private function serializeLocation(BusinessLocation $location): array
    {
        return [
            'id' => $location->id,
            'name' => $location->name,
            'address' => $location->address,
            'city' => $location->city,
            'district' => $location->district,
            'latitude' => $location->latitude !== null ? (float) $location->latitude : null,
            'longitude' => $location->longitude !== null ? (float) $location->longitude : null,
            'phone' => $location->phone,
            'is_primary' => (bool) $location->is_primary,
            'is_active' => (bool) $location->is_active,
            'sort_order' => (int) $location->sort_order,
        ];
    }
}
