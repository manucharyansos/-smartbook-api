<?php
// app/Http/Controllers/Api/Public/PublicBookingController.php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessCategory;
use App\Models\BusinessLocation;
use App\Models\Client;
use App\Models\Room;
use App\Models\Service;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\SmsService;
use App\Services\TelegramService;
use App\Services\ClientIdentityLinker;
use App\Services\GiftCardService;
use App\Services\LoyaltyService;
use Illuminate\Support\Facades\Mail;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Added imports for multi booking support
use App\Models\BookingItem;
use App\Models\BookingBlock;
use Illuminate\Support\Facades\DB;
use App\Support\BusinessVertical;
use App\Support\InteractsWithOptionalLocationColumns;
use App\Notifications\NewBookingNotification;
use App\Notifications\BookingRescheduledNotification;

class PublicBookingController extends Controller
{
    use InteractsWithOptionalLocationColumns;
    private function scopePublicTeamMembers($query)
    {
        if ($this->hasColumn('users', 'is_active')) {
            $query->where('is_active', true);
        }
        if ($this->hasColumn('users', 'show_in_public_team')) {
            $query->where('show_in_public_team', true);
        }

        return $query;
    }

    private function scopePublicBookableProviders($query)
    {
        if ($this->hasColumn('users', 'is_active')) {
            $query->where('is_active', true);
        }
        if ($this->hasColumn('users', 'is_bookable')) {
            $query->where('is_bookable', true);
        }

        return $query;
    }

    private function applyLocationCompatibility($query, ?int $locationId, string $table = 'services')
    {
        return $this->applyTableLocationCompatibility($query, $locationId, $table);
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasTable($table) && Schema::hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function getBusinessField(Business $business, string $field, mixed $fallback = null): mixed
    {
        return $this->hasColumn('businesses', $field) ? ($business->{$field} ?? $fallback) : $fallback;
    }

    private function applyPublicBusinessExclusions($query)
    {
        $excluded = array_values(array_filter((array) config('services.public_booking.excluded_slugs', [])));
        if ($excluded) {
            $query->whereNotIn('slug', $excluded);
        }

        foreach ((array) config('services.public_booking.excluded_slug_prefixes', []) as $prefix) {
            $prefix = trim((string) $prefix);
            if ($prefix !== '') {
                $query->where('slug', 'not like', $prefix . '%');
            }
        }

        return $query;
    }

    private function publicBusinessQuery(string $slug)
    {
        $query = Business::query()->where('slug', $slug);
        $this->applyPublicBusinessExclusions($query);

        if ($this->hasColumn('businesses', 'status')) {
            $query->where('status', 'active');
        }
        if ($this->hasColumn('businesses', 'is_onboarding_completed')) {
            $query->where('is_onboarding_completed', true);
        }
        if ($this->hasColumn('businesses', 'is_public_profile_enabled')) {
            $query->where('is_public_profile_enabled', true);
        } elseif ($this->hasColumn('businesses', 'is_public')) {
            $query->where('is_public', true);
        }

        return $query;
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:beauty,dental,salon,clinic,services,healthcare'],
            'vertical' => ['nullable', 'in:services,healthcare'],
            'category' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
            'featured' => ['nullable', 'boolean'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:0.5', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $perPage = min(100, max(1, (int) ($data['per_page'] ?? 24)));
            $hasCategories = $this->hasTable('business_categories');
            $hasLocations = $this->hasTable('business_locations');
            $hasServices = $this->hasTable('services');
            $hasUsers = $this->hasTable('users');

            $vertical = !empty($data['vertical']) ? BusinessVertical::normalize($data['vertical']) : null;
            if (!$vertical && !empty($data['type'])) {
                $vertical = BusinessVertical::normalize($data['type']);
            }

            $categoryId = $data['category_id'] ?? null;
            if (!$categoryId && !empty($data['category']) && $hasCategories) {
                $categoryId = BusinessCategory::query()
                    ->when($vertical && $this->hasColumn('business_categories', 'vertical'), fn ($q) => $q->forVertical($vertical))
                    ->where('slug', $data['category'])
                    ->value('id');
            }

            $lat = array_key_exists('lat', $data) ? (float) $data['lat'] : null;
            $lng = array_key_exists('lng', $data) ? (float) $data['lng'] : null;
            $radius = (float) ($data['radius'] ?? 10);

            $query = Business::query();
            $this->applyPublicBusinessExclusions($query);
            if ($hasLocations) {
                $query->with('locations');
            }
            if ($hasCategories && $this->hasColumn('businesses', 'business_category_id')) {
                $query->with('category');
            }

            if ($this->hasColumn('businesses', 'status')) {
                $query->where('status', 'active');
            }
            if ($this->hasColumn('businesses', 'is_onboarding_completed')) {
                $query->where('is_onboarding_completed', true);
            }
            if ($this->hasColumn('businesses', 'is_marketplace_visible')) {
                $query->where('is_marketplace_visible', true);
            } elseif ($this->hasColumn('businesses', 'is_public')) {
                $query->where('is_public', true);
            }
            if ($this->hasColumn('businesses', 'is_public_profile_enabled')) {
                $query->where('is_public_profile_enabled', true);
            }
            if ($vertical && $this->hasColumn('businesses', 'vertical')) {
                $query->where('vertical', $vertical);
            }
            if ($categoryId && $this->hasColumn('businesses', 'business_category_id')) {
                $query->where('business_category_id', $categoryId);
            }
            if (!empty($data['type']) && !$vertical && $this->hasColumn('businesses', 'business_type')) {
                $query->where('business_type', $data['type']);
            }
            if (!empty($data['search'])) {
                $search = trim($data['search']);
                $query->where(function ($inner) use ($search, $hasLocations) {
                    $first = true;
                    foreach (['name', 'address', 'slug', 'custom_category_name'] as $column) {
                        if ($this->hasColumn('businesses', $column)) {
                            $method = $first ? 'where' : 'orWhere';
                            $inner->{$method}($column, 'like', "%{$search}%");
                            $first = false;
                        }
                    }
                    if ($hasLocations) {
                        $inner->orWhereHas('locations', function ($locationQuery) use ($search) {
                            $locationQuery->where(function ($q) use ($search) {
                                foreach (['address', 'city', 'district'] as $index => $column) {
                                    if ($this->hasColumn('business_locations', $column)) {
                                        $index === 0
                                            ? $q->where($column, 'like', "%{$search}%")
                                            : $q->orWhere($column, 'like', "%{$search}%");
                                    }
                                }
                            });
                        });
                    }
                });
            }
            if ($hasLocations && $lat !== null && $lng !== null && $this->hasColumn('business_locations', 'latitude') && $this->hasColumn('business_locations', 'longitude')) {
                $query->whereHas('locations', function ($locationQuery) use ($lat, $lng, $radius) {
                    $distanceSql = '(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))))';
                    $locationQuery
                        ->whereNotNull('latitude')
                        ->whereNotNull('longitude')
                        ->whereRaw("{$distanceSql} <= ?", [$lat, $lng, $lat, $radius]);
                });
            }
            if ($hasServices && $this->hasColumn('services', 'business_id')) {
                $query->withCount(['services as services_count' => function ($q) {
                    if ($this->hasColumn('services', 'is_active')) {
                        $q->where('is_active', true);
                    }
                }]);
            }
            if ($hasUsers && $this->hasColumn('users', 'business_id')) {
                $query->withCount(['users as staff_count' => function ($q) {
                    $this->scopePublicTeamMembers($q);
                }]);
            }
            if ($this->hasColumn('businesses', 'created_at')) {
                $query->orderByDesc('created_at');
            } else {
                $query->orderByDesc('id');
            }

            $businesses = $query->limit($perPage)->get()
                ->map(fn (Business $business) => $this->serializePublicBusiness($business, $lat, $lng))
                ->values();

            if ($lat !== null && $lng !== null) {
                $businesses = $businesses->sortBy(fn ($business) => $business['distance_km'] ?? 999999)->values();
            }

            return response()->json([
                'data' => $businesses,
                'meta' => [
                    'total' => $businesses->count(),
                    'filters' => [
                        'type' => $data['type'] ?? null,
                        'vertical' => $vertical,
                        'category_id' => $categoryId,
                        'category' => $data['category'] ?? null,
                        'search' => $data['search'] ?? null,
                        'lat' => $lat,
                        'lng' => $lng,
                        'radius' => $lat !== null && $lng !== null ? $radius : null,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'message' => 'Business directory is temporarily unavailable.',
                'data' => [],
                'meta' => [
                    'total' => 0,
                    'error' => config('app.debug') ? $e->getMessage() : 'public_businesses_unavailable',
                ],
            ], 500);
        }
    }

    public function map(Request $request)
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:beauty,dental,salon,clinic,services,healthcare'],
            'vertical' => ['nullable', 'in:services,healthcare'],
            'category' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:120'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:0.5', 'max:100'],
        ]);

        try {
            if (!$this->hasTable('business_locations')) {
                return response()->json(['data' => [], 'meta' => ['total' => 0, 'reason' => 'business_locations_missing']]);
            }

            $hasCategories = $this->hasTable('business_categories');
            $vertical = !empty($data['vertical']) ? BusinessVertical::normalize($data['vertical']) : null;
            if (!$vertical && !empty($data['type'])) {
                $vertical = BusinessVertical::normalize($data['type']);
            }

            $categoryId = $data['category_id'] ?? null;
            if (!$categoryId && !empty($data['category']) && $hasCategories) {
                $categoryId = BusinessCategory::query()
                    ->when($vertical && $this->hasColumn('business_categories', 'vertical'), fn ($q) => $q->forVertical($vertical))
                    ->where('slug', $data['category'])
                    ->value('id');
            }

            $lat = array_key_exists('lat', $data) ? (float) $data['lat'] : null;
            $lng = array_key_exists('lng', $data) ? (float) $data['lng'] : null;
            $radius = (float) ($data['radius'] ?? 10);

            $businesses = Business::query();
            $this->applyPublicBusinessExclusions($businesses);
            $businesses
                ->with(['locations' => function ($q) {
                    if ($this->hasColumn('business_locations', 'is_active')) {
                        $q->where('is_active', true);
                    }
                }])
                ->when($hasCategories && $this->hasColumn('businesses', 'business_category_id'), fn ($q) => $q->with('category'));

            if ($this->hasColumn('businesses', 'status')) {
                $businesses->where('status', 'active');
            }
            if ($this->hasColumn('businesses', 'is_onboarding_completed')) {
                $businesses->where('is_onboarding_completed', true);
            }
            if ($this->hasColumn('businesses', 'is_marketplace_visible')) {
                $businesses->where('is_marketplace_visible', true);
            } elseif ($this->hasColumn('businesses', 'is_public')) {
                $businesses->where('is_public', true);
            }
            if ($this->hasColumn('businesses', 'is_public_profile_enabled')) {
                $businesses->where('is_public_profile_enabled', true);
            }
            if ($vertical && $this->hasColumn('businesses', 'vertical')) {
                $businesses->where('vertical', $vertical);
            }
            if ($categoryId && $this->hasColumn('businesses', 'business_category_id')) {
                $businesses->where('business_category_id', $categoryId);
            }
            if (!empty($data['search'])) {
                $search = trim($data['search']);
                $businesses->where(function ($inner) use ($search) {
                    $first = true;
                    foreach (['name', 'address', 'slug', 'custom_category_name'] as $column) {
                        if ($this->hasColumn('businesses', $column)) {
                            $method = $first ? 'where' : 'orWhere';
                            $inner->{$method}($column, 'like', "%{$search}%");
                            $first = false;
                        }
                    }
                    $inner->orWhereHas('locations', function ($locationQuery) use ($search) {
                        $locationQuery->where(function ($q) use ($search) {
                            foreach (['address', 'city', 'district'] as $index => $column) {
                                if ($this->hasColumn('business_locations', $column)) {
                                    $index === 0
                                        ? $q->where($column, 'like', "%{$search}%")
                                        : $q->orWhere($column, 'like', "%{$search}%");
                                }
                            }
                        });
                    });
                });
            }
            if ($this->hasColumn('business_locations', 'latitude') && $this->hasColumn('business_locations', 'longitude')) {
                $businesses->whereHas('locations', function ($q) use ($lat, $lng, $radius) {
                    if ($this->hasColumn('business_locations', 'is_active')) {
                        $q->where('is_active', true);
                    }
                    $q->whereNotNull('latitude')->whereNotNull('longitude');

                    if ($lat !== null && $lng !== null) {
                        $distanceSql = '(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))))';
                        $q->whereRaw("{$distanceSql} <= ?", [$lat, $lng, $lat, $radius]);
                    }
                });
            }

            $businesses = $businesses->limit(500)->get();

            $pins = $businesses->flatMap(function (Business $business) use ($lat, $lng) {
                $locations = $business->relationLoaded('locations') ? $business->locations : collect();
                return $locations
                    ->filter(fn (BusinessLocation $location) => $location->latitude !== null && $location->longitude !== null)
                    ->map(fn (BusinessLocation $location) => [
                        'business_id' => $business->id,
                        'name' => $business->name,
                        'slug' => $business->slug,
                        'vertical' => $business->normalizedVertical(),
                        'category_slug' => $business->relationLoaded('category') ? $business->category?->slug : null,
                        'category_name' => $business->relationLoaded('category') ? ($business->category?->name_hy ?: $business->custom_category_name) : $business->custom_category_name,
                        'icon' => $business->relationLoaded('category') ? $business->category?->icon : null,
                        'location_id' => $location->id,
                        'location_name' => $location->name,
                        'address' => $location->address,
                        'lat' => (float) $location->latitude,
                        'lng' => (float) $location->longitude,
                        'distance_km' => $lat !== null && $lng !== null ? $this->distanceKm($lat, $lng, (float) $location->latitude, (float) $location->longitude) : null,
                        'booking_url' => "/book/{$business->slug}?location_id={$location->id}",
                    ]);
            })->sortBy(fn ($pin) => $pin['distance_km'] ?? 999999)->values();

            return response()->json([
                'data' => $pins,
                'meta' => [
                    'total' => $pins->count(),
                    'vertical' => $vertical,
                    'category_id' => $categoryId,
                    'lat' => $lat,
                    'lng' => $lng,
                    'radius' => $lat !== null && $lng !== null ? $radius : null,
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'data' => [],
                'meta' => [
                    'total' => 0,
                    'error' => config('app.debug') ? $e->getMessage() : 'public_map_unavailable',
                ],
            ], 200);
        }
    }

    public function business(string $slug)
    {
        $query = $this->publicBusinessQuery($slug)
            ->with(['locations', 'category']);

        $business = $query->firstOrFail();

        $primaryLocation = $business->locations->firstWhere('is_primary', true) ?? $business->locations->first();

        return response()->json([
            'id' => $business->id,
            'name' => $business->name,
            'slug' => $business->slug,
            'business_type' => $business->business_type,
            'vertical' => $business->normalizedVertical(),
            'category' => $business->category ? [
                'id' => $business->category->id,
                'slug' => $business->category->slug,
                'name_hy' => $business->category->name_hy,
                'name_ru' => $business->category->name_ru,
                'name_en' => $business->category->name_en,
                'icon' => $business->category->icon,
            ] : null,
            'custom_category_name' => $business->custom_category_name,
            'address' => $primaryLocation?->address ?: $business->address,
            'locations' => $business->locations->map(fn ($location) => [
                'id' => $location->id,
                'name' => $location->name,
                'address' => $location->address,
                'city' => $location->city,
                'district' => $location->district,
                'lat' => $location->latitude !== null ? (float) $location->latitude : null,
                'lng' => $location->longitude !== null ? (float) $location->longitude : null,
                'phone' => $location->phone,
                'is_primary' => (bool) $location->is_primary,
            ])->values(),
            'phone' => $business->phone,
            'timezone' => $business->effectiveTimezone(),
            'work_start' => $business->work_start,
            'work_end' => $business->work_end,
            'short_description' => $business->short_description,
            'description' => $business->description,
            'cover_url' => ($business->show_cover ?? true) ? $business->cover_url : null,
            'logo_url' => ($business->show_logo ?? true) ? $business->logo_url : null,
            'show_logo' => (bool) ($business->show_logo ?? true),
            'show_cover' => (bool) ($business->show_cover ?? true),
            'show_staff' => (bool) ($business->show_staff ?? true),
            'show_services' => (bool) ($business->show_services ?? true),
            'instagram_url' => $business->instagram_url,
            'facebook_url' => $business->facebook_url,
            'website_url' => $business->website_url,
            'messenger_url' => $business->messenger_url,
            'whatsapp_url' => $business->whatsapp_url,
            'settings' => $business->settings ?? [
                    'has_rooms' => false,
                    'has_patients' => false,
                    'phone_verification' => false,
                ],
        ]);
    }

    public function services(string $slug, Request $request)
    {
        $business = $this->publicBusinessQuery($slug)->firstOrFail();

        $services = Service::query()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->when($request->filled('location_id'), fn ($q) => $this->applyLocationCompatibility($q, (int) $request->integer('location_id')))
            ->orderBy('id')
            ->get($this->filterColumnsForOptionalLocation('services', ['id', 'name', 'description', 'image_url', 'duration_minutes', 'price', 'currency', 'is_active', 'location_id']));

        return response()->json([
            'data' => $services,
            'meta' => ['business_type' => $business->business_type, 'vertical' => $business->normalizedVertical()],
        ]);
    }

    public function staff(string $slug, Request $request)
    {
        $business = $this->publicBusinessQuery($slug)->firstOrFail();
        $bookableOnly = $request->boolean('bookable_only');

        $staff = User::query()
            ->where('business_id', $business->id)
            ->when($request->filled('location_id'), fn ($q) => $this->applyLocationCompatibility($q, (int) $request->integer('location_id'), 'users'))
            ->tap(fn ($q) => $bookableOnly ? $this->scopePublicBookableProviders($q) : $this->scopePublicTeamMembers($q))
            ->orderBy('role')
            ->orderBy('name')
            ->get($this->filterColumnsForOptionalLocation('users', ['id', 'name', 'role', 'avatar_url', 'bio', 'is_bookable', 'location_id']));

        return response()->json([
            'data' => $staff,
            'meta' => [
                'business_type' => $business->business_type,
                'vertical' => $business->normalizedVertical(),
                'bookable_only' => $bookableOnly,
            ],
        ]);
    }

    /**
     * GET /api/public/businesses/{slug}/availability?service_id=..&date=YYYY-MM-DD&staff_id(optional)
     */
    public function availability(string $slug, Request $request, AvailabilityService $availability)
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'date'       => ['required', 'date_format:Y-m-d'],
            'staff_id'   => ['nullable', 'integer', 'exists:users,id'],
            'location_id'=> ['nullable', 'integer'],
        ]);

        $business = $this->publicBusinessQuery($slug)->firstOrFail();

        $service = Service::query()
            ->where('id', (int) $data['service_id'])
            ->where('business_id', $business->id)
            ->when(!empty($data['location_id']), fn ($q) => $this->applyLocationCompatibility($q, (int) $data['location_id']))
            ->firstOrFail();

        $requestedStaffId = (int) ($data['staff_id'] ?? 0);

        if ($requestedStaffId) {
            $staff = User::query()
                ->where('id', $requestedStaffId)
                ->where('business_id', $business->id)
                ->when(!empty($data['location_id']), fn ($q) => $this->applyLocationCompatibility($q, (int) $data['location_id'], 'users'))
                ->where('is_active', true)
                ->where('is_bookable', true)
                ->first();

            if (!$staff) {
                return response()->json(['data' => [], 'meta' => ['business_type' => $business->business_type]]);
            }

            $slots = $availability->slotsForDay(
                staffId: $staff->id,
                serviceId: $service->id,
                date: $data['date'],
                businessId: $business->id,
                locationId: !empty($data['location_id']) ? (int) $data['location_id'] : null,
            );
        } else {
            $staffMembers = User::query()
                ->where('business_id', $business->id)
                ->when(!empty($data['location_id']), fn ($q) => $this->applyLocationCompatibility($q, (int) $data['location_id'], 'users'))
                ->tap(fn ($q) => $this->scopePublicBookableProviders($q))
                ->orderBy('role')
                ->orderBy('name')
                ->get(['id', 'name']);

            $flattened = [];
            foreach ($staffMembers as $staff) {
                foreach ($availability->slotsForDay(
                    staffId: (int) $staff->id,
                    serviceId: $service->id,
                    date: $data['date'],
                    businessId: (int) $business->id,
                    locationId: !empty($data['location_id']) ? (int) $data['location_id'] : null,
                ) as $slot) {
                    $flattened[] = $slot;
                }
            }

            $slots = $this->dedupeSmartSlotsByTime($flattened);
        }

        $slots = $this->markRecommendedSlots($slots);

        return response()->json([
            'data' => $slots,
            'meta' => [
                'business_type' => $business->business_type,
                'has_rooms' => $business->isHealthcareVertical(),
                'smart_suggestions' => true,
            ],
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
          ->header('Pragma', 'no-cache');
    }

    /**
     * GET /api/public/businesses/{slug}/availability/multi
     * Returns available time slots for sequential multi-service bookings.
     * Accepts an array of service IDs and optional staff ID. Computes the total
     * duration of all services and generates start/end slots that do not overlap
     * with existing bookings or blocks. Uses the business working hours and
     * slot step configuration. The response structure mirrors the single
     * availability endpoint.
     */
    public function availabilityMulti(string $slug, Request $request)
    {
        $data = $request->validate([
            'service_ids'   => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'exists:services,id'],
            'date'          => ['required', 'date_format:Y-m-d'],
            'staff_id'      => ['nullable', 'integer', 'exists:users,id'],
            'location_id'   => ['nullable', 'integer'],
        ]);

        $business = $this->publicBusinessQuery($slug)->firstOrFail();

        $requestedIds = array_map('intval', $data['service_ids']);
        $services = Service::query()
            ->whereIn('id', $requestedIds)
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->when(!empty($data['location_id']), fn ($q) => $this->applyLocationCompatibility($q, (int) $data['location_id']))
            ->get()
            ->keyBy('id');

        if ($services->count() !== count($requestedIds)) {
            return response()->json(['data' => [], 'meta' => ['business_type' => $business->business_type]]);
        }

        $totalDuration = 0;
        foreach ($requestedIds as $sid) {
            $svc = $services->get($sid);
            $dur = (int) ($svc->duration_minutes ?? 0);
            if ($dur < 5 || $dur > 600) {
                return response()->json(['data' => [], 'meta' => ['business_type' => $business->business_type]]);
            }
            $totalDuration += $dur;
        }

        $requestedStaffId = (int) ($data['staff_id'] ?? 0);
        if ($requestedStaffId) {
            $staff = User::query()
                ->where('id', $requestedStaffId)
                ->where('business_id', $business->id)
                ->when(!empty($data['location_id']), fn ($q) => $this->applyLocationCompatibility($q, (int) $data['location_id'], 'users'))
                ->where('is_active', true)
                ->where('is_bookable', true)
                ->first();

            if (!$staff) {
                return response()->json(['data' => [], 'meta' => ['business_type' => $business->business_type]]);
            }

            $slots = $this->buildSmartMultiSlotsForStaff($business, $staff, $data['date'], $totalDuration);
        } else {
            $staffMembers = User::query()
                ->where('business_id', $business->id)
                ->when(!empty($data['location_id']), fn ($q) => $this->applyLocationCompatibility($q, (int) $data['location_id'], 'users'))
                ->tap(fn ($q) => $this->scopePublicBookableProviders($q))
                ->orderBy('role')
                ->orderBy('name')
                ->get(['id', 'name']);

            $flattened = [];
            foreach ($staffMembers as $staff) {
                foreach ($this->buildSmartMultiSlotsForStaff($business, $staff, $data['date'], $totalDuration) as $slot) {
                    $flattened[] = $slot;
                }
            }

            $slots = $this->dedupeSmartSlotsByTime($flattened);
        }

        $slots = $this->markRecommendedSlots($slots);

        return response()->json([
            'data' => $slots,
            'meta' => [
                'business_type' => $business->business_type,
                'has_rooms' => $business->isHealthcareVertical(),
                'smart_suggestions' => true,
            ],
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
          ->header('Pragma', 'no-cache');
    }

    /**
     * POST /api/public/businesses/{slug}/bookings/multi
     * Creates a booking containing multiple services (sequentially) for a single staff member.
     * The client information and selected start time apply to the entire sequence. Each
     * individual service is persisted as a BookingItem for later reference. The booking
     * remains in "pending" state until phone verification is completed via the normal
     * verification endpoint.
     */
    public function storeMulti(string $slug, Request $request)
    {
        $data = $request->validate([
            'service_ids'   => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'exists:services,id'],
            'staff_id'      => ['nullable', 'integer', 'exists:users,id'],
            'starts_at'     => ['required', 'date_format:Y-m-d H:i'],
            'client_name'   => ['required', 'string', 'min:2', 'max:120'],
            'client_phone'  => ['required', 'string', 'min:5', 'max:40'],
            'client_email'  => ['required','string','email','max:150'],
            'notes'         => ['nullable', 'string', 'max:2000'],
            'room_id'       => ['nullable', 'integer', 'exists:rooms,id'],
            'location_id'   => ['nullable', 'integer'],
            'source'        => ['nullable', 'in:website,instagram,facebook,whatsapp,widget,partner,qr'],
            'redeem_points' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'gift_card_code' => ['nullable', 'string', 'max:40'],
            'gift_card_amount' => ['nullable', 'integer', 'min:1', 'max:100000000'],
        ]);

        $business = $this->publicBusinessQuery($slug)->firstOrFail();

        // Resolve services belonging to business and keep order
        $requestedIds = array_map('intval', $data['service_ids']);
        $services = Service::query()
            ->whereIn('id', $requestedIds)
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->when(!empty($data['location_id']), fn ($q) => $this->applyLocationCompatibility($q, (int) $data['location_id']))
            ->get()
            ->keyBy('id');
        if ($services->count() !== count($requestedIds)) {
            return response()->json(['message' => 'Invalid service selection'], 422);
        }

        try {
            $this->assertSingleBookingServicesCompatible($services->values());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->validator->errors()->first() ?: 'Invalid service selection'], 422);
        }

        // Determine staff: if not provided, use first active staff
        $staffId = (int)($data['staff_id'] ?? 0);
        if (!$staffId) {
            $staffId = (int) User::query()
                ->where('business_id', $business->id)
                ->when(!empty($data['location_id']), fn ($q) => $this->applyLocationCompatibility($q, (int) $data['location_id'], 'users'))
                ->where('is_active', true)
                ->where('is_bookable', true)
                ->orderBy('id')
                ->value('id');
        }
        if (!$staffId) {
            return response()->json(['message' => 'No staff available.'], 422);
        }
        $staff = User::query()
            ->where('id', $staffId)
            ->where('business_id', $business->id)
            ->when(!empty($data['location_id']), fn ($q) => $this->applyLocationCompatibility($q, (int) $data['location_id'], 'users'))
            ->where('is_active', true)
            ->where('is_bookable', true)
            ->first();
        if (!$staff) {
            return response()->json(['message' => 'Invalid staff.'], 422);
        }

        try {
            $this->assertSingleBookingServicesCompatible($services->values(), $staff, true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->validator->errors()->first() ?: 'Invalid staff selection'], 422);
        }

        // Normalize phone number
        $phoneNorm = Phone::normalizeAM($data['client_phone']);
        if (!$phoneNorm) {
            return response()->json(['message' => 'Invalid phone number'], 422);
        }

        // Compute start & end times in business timezone
        $tz   = $business->effectiveTimezone();
        $step = max(5, min(60, (int)($business->slot_step_minutes ?? 15)));
        try {
            $startLocal = Carbon::createFromFormat('Y-m-d H:i', $data['starts_at'], $tz)->seconds(0);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Invalid starts_at'], 422);
        }
        $startLocal = $this->snapToStep($startLocal, $step);

        // Compute total duration, price and currency
        $totalDuration = 0;
        $totalPrice    = 0;
        $currency      = 'AMD';
        $priceIsNull   = false;

        foreach ($requestedIds as $sid) {
            $svc = $services->get($sid);
            $dur = (int)($svc->duration_minutes ?? 0);
            if ($dur < 5 || $dur > 600) {
                return response()->json(['message' => 'Invalid service duration'], 422);
            }
            $totalDuration += $dur;
            $currency = $svc->currency ?? $currency;
            if ($svc->price === null) {
                $priceIsNull = true;
            } else {
                $totalPrice += (int)$svc->price;
            }
        }

        $endLocal = $startLocal->copy()->addMinutes($totalDuration)->seconds(0);

        try {
            $this->assertWithinBusinessHours($business, $startLocal, $endLocal);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->validator->errors()->first() ?: 'Selected time is outside business hours.'], 422);
        }

        // Check overlaps and blocks in business local time
        try {
            $this->checkOverlap((int)$business->id, (int)$staff->id, $startLocal, $endLocal);
            $this->checkBlocked((int)$business->id, (int)$staff->id, $startLocal, $endLocal);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->validator->errors()->first('starts_at') ?? 'Time slot is not available.'], 422);
        }

        // Find or create client inside this business
        $client = Client::query()
            ->where('business_id', $business->id)
            ->where('phone', $phoneNorm)
            ->first();
        if (!$client) {
            $client = Client::query()->create([
                'business_id' => $business->id,
                'name'        => $data['client_name'],
                'phone'       => $phoneNorm,
                'email'       => Booking::normalizeContactEmail($data['client_email'] ?? null),
            ]);
        } else {
            $client->name = $data['client_name'];
            if (isset($data['client_email'])) {
                $client->email = Booking::normalizeContactEmail($data['client_email']);
            }
            $client->save();
        }

        app(ClientIdentityLinker::class)->linkClientProfile($client);
        $clientEmailSnapshot = Booking::normalizeContactEmail(
            $data['client_email'] ?? $client->email
        );

        // Generate phone verification code
        $code    = (string) random_int(1000, 9999);
        $expires = now()->addMinutes(10);

        $startUtc = $startLocal->copy()->setTimezone('UTC');
        $endUtc = $endLocal->copy()->setTimezone('UTC');

        // Persist booking and items in a transaction
        $booking = null;
        DB::transaction(function () use (
            &$booking,
            $business,
            $staff,
            $client,
            $clientEmailSnapshot,
            $data,
            $startUtc,
            $endUtc,
            $priceIsNull,
            $totalPrice,
            $currency,
            $requestedIds,
            $services,
            $code,
            $expires
        ) {
            $resolvedLocationId = $this->resolveBookingLocationId($business, !empty($data['location_id']) ? (int) $data['location_id'] : null, $services->get($requestedIds[0]), $staff);

            $bookingPayload = [
                'business_id'  => $business->id,
                'service_id'   => $services->get($requestedIds[0])->id,
                'staff_id'     => $staff->id,
                'location_id'  => $resolvedLocationId,
                'client_id'    => $client->id,
                'client_name'  => $data['client_name'],
                'client_phone' => $client->phone,
                'client_email' => $clientEmailSnapshot,
                'notes'        => $data['notes'] ?? null,
                'source'       => $data['source'] ?? 'website',
                'status'       => 'pending',
                'starts_at'    => $startUtc->format('Y-m-d H:i:s'),
                'ends_at'      => $endUtc->format('Y-m-d H:i:s'),
                'final_price'  => $priceIsNull ? null : $totalPrice,
                'currency'     => $currency,
                'booking_code' => strtoupper(Str::random(8)),
                'phone_verification_code_hash' => Hash::make($code),
                'phone_verification_expires_at' => $expires,
                'phone_verified_at' => null,
                'phone_verification_attempts' => 0,
                'room_id'      => ($business->isHealthcareVertical()) ? ($data['room_id'] ?? null) : null,
            ];

            $booking = Booking::query()->create($this->withoutUnavailableLocationAttribute($bookingPayload, 'bookings'));

            $this->applyPublicBookingBenefits($booking, $client, $data, $priceIsNull ? 0 : $totalPrice);

            // Create booking items for each service, preserving order
            foreach ($requestedIds as $idx => $sid) {
                $svc = $services->get($sid);
                BookingItem::create([
                    'booking_id'       => $booking->id,
                    'service_id'       => $svc->id,
                    'position'         => (int)$idx,
                    'duration_minutes' => (int)$svc->duration_minutes,
                    'price'            => $svc->price,
                    'currency'         => $svc->currency ?? $currency,
                ]);
            }
        });

        $this->sendVerificationNotifications($booking, $code, $expires, $booking->contactEmail());

        return response()->json([
            'data' => [
                'booking_code'              => $booking->booking_code,
                'needs_phone_verification'  => true,
                'phone'                     => $phoneNorm,
                'expires_at'                => $expires->toISOString(),
            ],
            'meta' => ['business_type' => $business->business_type, 'vertical' => $business->normalizedVertical()],
        ], 201);
    }

    /**
     * POST /api/public/businesses/{slug}/bookings/lines
     * Creates multiple bookings for different services where each line may have its own staff and start time.
     * This mirrors the admin multi-booking feature but is exposed publicly. All bookings share the same
     * client information and phone verification code. Each booking is created independently (not sequentially),
     * using the provided starts_at for each line. A common group_id binds them together for easier lookup.
     */
    public function storeLines(string $slug, Request $request)
    {
        $data = $request->validate([
            'lines'               => ['required', 'array', 'min:1'],
            'lines.*.service_id'  => ['required', 'integer', 'exists:services,id'],
            'lines.*.staff_id'    => ['nullable', 'integer', 'exists:users,id'],
            'lines.*.starts_at'   => ['required', 'date_format:Y-m-d H:i'],
            'client_name'         => ['required', 'string', 'min:2', 'max:120'],
            'client_phone'        => ['required', 'string', 'min:5', 'max:40'],
            'client_email'        => ['required','string','email','max:150'],
            'notes'               => ['nullable', 'string', 'max:2000'],
            'room_id'             => ['nullable', 'integer', 'exists:rooms,id'],
            'location_id'         => ['nullable', 'integer'],
            'source'              => ['nullable', 'in:website,instagram,facebook,whatsapp,widget,partner,qr'],
        ]);

        $business = $this->publicBusinessQuery($slug)->firstOrFail();

        $tz   = $business->effectiveTimezone();
        $step = max(5, min(60, (int)($business->slot_step_minutes ?? 15)));

        $linesData = [];
        // Resolve first available staff for default assignments
        $defaultStaffId = (int) User::query()
            ->where('business_id', $business->id)
            ->when(!empty($data['location_id']), fn ($q) => $this->applyLocationCompatibility($q, (int) $data['location_id'], 'users'))
            ->where('is_active', true)
            ->where('is_bookable', true)
            ->orderBy('id')
            ->value('id');

        foreach ($data['lines'] as $i => $line) {
            // Resolve service
            $service = Service::query()
                ->where('id', (int) $line['service_id'])
                ->where('business_id', $business->id)
                ->when(!empty($data['location_id']), fn ($q) => $this->applyLocationCompatibility($q, (int) $data['location_id']))
                ->first();
            if (!$service) {
                return response()->json(['message' => "Service {$line['service_id']} not found"], 422);
            }

            // Resolve staff (use provided or default)
            $staffId = (int)($line['staff_id'] ?? 0);
            if (!$staffId) {
                $staffId = $defaultStaffId;
            }
            if (!$staffId) {
                return response()->json(['message' => 'No staff available.'], 422);
            }
            $staff = User::query()
                ->where('id', $staffId)
                ->where('business_id', $business->id)
                ->when(!empty($data['location_id']), fn ($q) => $this->applyLocationCompatibility($q, (int) $data['location_id'], 'users'))
                ->where('is_active', true)
                ->where('is_bookable', true)
                ->first();
            if (!$staff) {
                return response()->json(['message' => 'Invalid staff selection'], 422);
            }

            try {
                $this->assertSingleBookingServicesCompatible(collect([$service]), $staff, true);
            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json(['message' => $e->validator->errors()->first() ?: 'Invalid staff selection', 'line' => $i], 422);
            }

            // Parse start time in business timezone and snap to step
            try {
                $startLocal = Carbon::createFromFormat('Y-m-d H:i', $line['starts_at'], $tz)->seconds(0);
            } catch (\Throwable $e) {
                return response()->json(['message' => "Invalid starts_at for line {$i}"], 422);
            }
            $startLocal = $this->snapToStep($startLocal, $step);

            // Compute end time using service duration
            $duration = (int)($service->duration_minutes ?? 0);
            if ($duration < 5 || $duration > 600) {
                return response()->json(['message' => "Invalid duration for service {$service->id}"], 422);
            }
            $endLocal = $startLocal->copy()->addMinutes($duration)->seconds(0);

            try {
                $this->assertWithinBusinessHours($business, $startLocal, $endLocal, "lines.$i.starts_at");
            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json(['message' => $e->validator->errors()->first() ?: 'Selected time is outside business hours.', 'line' => $i], 422);
            }

            // Check overlaps and blocks in business local time
            try {
                $this->checkOverlap((int)$business->id, (int)$staff->id, $startLocal, $endLocal);
                $this->checkBlocked((int)$business->id, (int)$staff->id, $startLocal, $endLocal);
            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json([
                    'message' => $e->validator->errors()->first('starts_at') ?? 'Selected time is not available.',
                    'line'    => $i,
                ], 422);
            }

            $linesData[] = [
                'service'    => $service,
                'staff'      => $staff,
                'location_id'=> $this->resolveBookingLocationId($business, !empty($data['location_id']) ? (int) $data['location_id'] : null, $service, $staff),
                'startLocal' => $startLocal,
                'endLocal'   => $endLocal,
                'startUtc'   => $startLocal->copy()->setTimezone('UTC'),
                'endUtc'     => $endLocal->copy()->setTimezone('UTC'),
            ];
        }

        try {
            $this->assertPreparedLinesDoNotOverlap($linesData);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->validator->errors()->first() ?: 'Overlapping staff times in request.'], 422);
        }

        // Normalize phone number and resolve client
        $phoneNorm = Phone::normalizeAM($data['client_phone']);
        if (!$phoneNorm) {
            return response()->json(['message' => 'Invalid phone number'], 422);
        }

        $client = Client::query()
            ->where('business_id', $business->id)
            ->where('phone', $phoneNorm)
            ->first();
        if (!$client) {
            $client = Client::query()->create([
                'business_id' => $business->id,
                'name'        => $data['client_name'],
                'phone'       => $phoneNorm,
                'email'       => Booking::normalizeContactEmail($data['client_email'] ?? null),
            ]);
        } else {
            // Update client name and email if provided
            $client->name = $data['client_name'];
            if (isset($data['client_email'])) {
                $client->email = Booking::normalizeContactEmail($data['client_email']);
            }
            $client->save();
        }

        app(ClientIdentityLinker::class)->linkClientProfile($client);
        $clientEmailSnapshot = Booking::normalizeContactEmail(
            $data['client_email'] ?? $client->email
        );

        // Generate a common verification code and group id
        $code    = (string) random_int(1000, 9999);
        $expires = now()->addMinutes(10);
        $groupId = (string) Str::uuid();

        $bookings = [];
        DB::transaction(function () use (
            &$bookings,
            $linesData,
            $business,
            $client,
            $clientEmailSnapshot,
            $data,
            $code,
            $expires,
            $groupId
        ) {
            foreach ($linesData as $idx => $row) {
                /** @var Service $svc */
                $svc  = $row['service'];
                /** @var User $stf */
                $stf  = $row['staff'];
                $startUtc = $row['startUtc'];
                $endUtc   = $row['endUtc'];
                $locationId = (int) ($row['location_id'] ?? 0) ?: null;

                $bookingPayload = [
                    'group_id'     => $groupId,
                    'business_id'  => $business->id,
                    'service_id'   => $svc->id,
                    'staff_id'     => $stf->id,
                    'location_id'  => $locationId,
                    'client_id'    => $client->id,
                    'client_name'  => $data['client_name'],
                    'client_phone' => $client->phone,
                    'client_email' => $clientEmailSnapshot,
                    'notes'        => $data['notes'] ?? null,
                    'source'       => $data['source'] ?? 'website',
                    'status'       => 'pending',
                    'starts_at'    => $startUtc->format('Y-m-d H:i:s'),
                    'ends_at'      => $endUtc->format('Y-m-d H:i:s'),
                    'final_price'  => $svc->price,
                    'currency'     => $svc->currency ?? 'AMD',
                    'booking_code' => strtoupper(Str::random(8)),
                    'phone_verification_code_hash' => Hash::make($code),
                    'phone_verification_expires_at' => $expires,
                    'phone_verified_at' => null,
                    'phone_verification_attempts' => 0,
                    'room_id'      => ($business->isHealthcareVertical()) ? ($data['room_id'] ?? null) : null,
                ];

                $booking = Booking::query()->create($this->withoutUnavailableLocationAttribute($bookingPayload, 'bookings'));

                BookingItem::create([
                    'booking_id'       => $booking->id,
                    'service_id'       => $svc->id,
                    'position'         => 0,
                    'duration_minutes' => (int) $svc->duration_minutes,
                    'price'            => $svc->price,
                    'currency'         => $svc->currency ?? 'AMD',
                ]);

                $bookings[] = $booking;
            }
        });

        $this->sendVerificationNotifications($bookings[0], $code, $expires, $bookings[0]->contactEmail());

        return response()->json([
            'data' => [
                'booking_code'             => $bookings[0]->booking_code ?? null,
                'group_id'                 => $groupId,
                'needs_phone_verification' => true,
                'phone'                    => $phoneNorm,
                'expires_at'               => $expires->toISOString(),
            ],
            'meta' => ['business_type' => $business->business_type, 'vertical' => $business->normalizedVertical()],
        ], 201);
    }

    /* ---------- Helper methods copied from BookingController for overlap and block checks ---------- */
    /**
     * Snap a Carbon instance to the nearest step interval (minutes).
     */
    private function snapToStep(Carbon $dt, int $stepMin = 15): Carbon
    {
        $stepMin = max(1, min(60, $stepMin));
        $m = (int)$dt->minute;
        $snapped = intdiv($m, $stepMin) * $stepMin;
        return $dt->copy()->minute($snapped)->second(0);
    }

    /**
     * Throws ValidationException if the given time range overlaps any existing bookings.
     */
    private function checkOverlap(int $businessId, int $staffId, Carbon $startLocal, Carbon $endLocal, ?int $ignoreBookingId = null): void
    {
        $startUtc = $startLocal->copy()->setTimezone('UTC')->seconds(0);
        $endUtc   = $endLocal->copy()->setTimezone('UTC')->seconds(0);

        $query = Booking::query()
            ->where('business_id', $businessId)
            ->where('staff_id', $staffId)
            ->whereNotIn('status', ['cancelled'])
            ->where(function ($q) {
                $q->where('status', 'confirmed')
                    ->orWhere(function ($pending) {
                        $pending->where('status', 'pending')
                            ->where(function ($verifiedOrFresh) {
                                $verifiedOrFresh->whereNotNull('phone_verified_at')
                                    ->orWhereNull('phone_verification_code_hash')
                                    ->orWhere(function ($fresh) {
                                        $fresh->whereNotNull('phone_verification_expires_at')
                                            ->where('phone_verification_expires_at', '>=', now());
                                    });
                            });
                    });
            })
            ->where('starts_at', '<', $endUtc->format('Y-m-d H:i:s'))
            ->where('ends_at',   '>', $startUtc->format('Y-m-d H:i:s'));
        if ($ignoreBookingId) {
            $query->where('id', '!=', $ignoreBookingId);
        }
        if ($query->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'starts_at' => ['This time slot is already booked'],
            ]);
        }
    }

    /**
     * Throws ValidationException if the given time range collides with any booking blocks.
     */
    private function checkBlocked(int $businessId, int $staffId, Carbon $startLocal, Carbon $endLocal): void
    {
        $startUtc = $startLocal->copy()->setTimezone('UTC')->seconds(0);
        $endUtc   = $endLocal->copy()->setTimezone('UTC')->seconds(0);

        $blocked = BookingBlock::query()
            ->where('business_id', $businessId)
            ->where(function ($q) use ($staffId) {
                $q->whereNull('staff_id')->orWhere('staff_id', $staffId);
            })
            ->where('starts_at', '<', $endUtc->format('Y-m-d H:i:s'))
            ->where('ends_at',   '>', $startUtc->format('Y-m-d H:i:s'))
            ->exists();

        if ($blocked) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'starts_at' => ['This time is blocked (break / day off).'],
            ]);
        }
    }

    /**
     * POST /api/public/businesses/{slug}/bookings
     * Creates booking in "pending" but hides from staff until phone verified.
     */
    public function store(string $slug, Request $request, AvailabilityService $availability, SmsService $sms)
    {
        $data = $request->validate([
            'service_id'    => ['required', 'integer', 'exists:services,id'],
            'staff_id'      => ['nullable', 'integer', 'exists:users,id'],
            'starts_at'     => ['required', 'date_format:Y-m-d H:i'],
            'client_name'   => ['required', 'string', 'min:2', 'max:120'],
            'client_phone'  => ['required', 'string', 'min:5', 'max:40'],
            'client_email'  => ['required', 'string', 'email', 'max:150'],
            'notes'         => ['nullable', 'string', 'max:2000'],
            'room_id'       => ['nullable', 'integer', 'exists:rooms,id'],
            'location_id'   => ['nullable', 'integer'],
            'source'        => ['nullable', 'in:website,instagram,facebook,whatsapp,widget,partner,qr'],
        ]);

        $business = $this->publicBusinessQuery($slug)->firstOrFail();

        $service = Service::query()
            ->where('id', (int)$data['service_id'])
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->when(!empty($data['location_id']), fn ($q) => $this->applyLocationCompatibility($q, (int) $data['location_id']))
            ->firstOrFail();

        // pick staff
        $staffId = (int)($data['staff_id'] ?? 0);
        if (!$staffId) {
            $staffId = (int) User::query()
                ->where('business_id', $business->id)
                ->when(!empty($data['location_id']), fn ($q) => $this->applyLocationCompatibility($q, (int) $data['location_id'], 'users'))
                ->where('is_active', true)
                ->where('is_bookable', true)
                ->orderBy('id')
                ->value('id');
        }
        if (!$staffId) return response()->json(['message' => 'No staff available.'], 422);

        $staff = User::query()
            ->where('id', $staffId)
            ->where('business_id', $business->id)
            ->when(!empty($data['location_id']), fn ($q) => $this->applyLocationCompatibility($q, (int) $data['location_id'], 'users'))
            ->where('is_active', true)
            ->where('is_bookable', true)
            ->first();
        if (!$staff) return response()->json(['message' => 'Invalid staff.'], 422);

        // normalize phone
        $phoneNorm = Phone::normalizeAM($data['client_phone']);
        if (!$phoneNorm) {
            return response()->json(['message' => 'Invalid phone number'], 422);
        }

        // check slot in business local time
        $tz = $business->effectiveTimezone();
        $step = max(5, min(60, (int)($business->slot_step_minutes ?? 15)));
        try {
            $startsAt = Carbon::createFromFormat('Y-m-d H:i', $data['starts_at'], $tz)->seconds(0);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Invalid starts_at'], 422);
        }
        $startsAt = $this->snapToStep($startsAt, $step);
        $date = $startsAt->format('Y-m-d');
        $time = $startsAt->format('H:i');

        $slots = $availability->slotsForDay(
            staffId: $staff->id,
            serviceId: $service->id,
            date: $date,
            businessId: $business->id,
            locationId: !empty($data['location_id']) ? (int) $data['location_id'] : null,
        );
        $ok = collect($slots)->contains(fn($s) => substr($s['starts_at'], 11, 5) === $time);
        if (!$ok) return response()->json(['message' => 'Selected time is not available. Please refresh available times and try again.'], 422);

        $endsAt = $startsAt->copy()->addMinutes((int)$service->duration_minutes);
        try {
            $this->assertWithinBusinessHours($business, $startsAt, $endsAt);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->validator->errors()->first() ?: 'Selected time is outside business hours.'], 422);
        }

        $startsAtUtc = $startsAt->copy()->setTimezone('UTC');
        $endsAtUtc = $endsAt->copy()->setTimezone('UTC');

        // create/find client inside this business
        $client = Client::query()
            ->where('business_id', $business->id)
            ->where('phone', $phoneNorm)
            ->first();
        if (!$client) {
            $client = Client::query()->create([
                'business_id' => $business->id,
                'name'        => $data['client_name'],
                'phone'       => $phoneNorm,
                'email'       => Booking::normalizeContactEmail($data['client_email'] ?? null),
            ]);
        } else {
            // Update client name and email if provided
            $client->name = $data['client_name'];
            if (isset($data['client_email'])) {
                $client->email = Booking::normalizeContactEmail($data['client_email']);
            }
            $client->save();
        }

        app(ClientIdentityLinker::class)->linkClientProfile($client);
        $clientEmailSnapshot = Booking::normalizeContactEmail(
            $data['client_email'] ?? $client->email
        );

        $code = (string)random_int(1000, 9999);
        $expires = now()->addMinutes(10);

        $resolvedLocationId = $this->resolveBookingLocationId($business, !empty($data['location_id']) ? (int) $data['location_id'] : null, $service, $staff);

        $bookingPayload = [
            'business_id'   => $business->id,
            'service_id'    => $service->id,
            'staff_id'      => $staff->id,
            'location_id'   => $resolvedLocationId,
            'client_id'     => $client->id,
            'room_id'       => ($business->isHealthcareVertical()) ? ($data['room_id'] ?? null) : null,
            'starts_at'     => $startsAtUtc->format('Y-m-d H:i:s'),
            'ends_at'       => $endsAtUtc->format('Y-m-d H:i:s'),
            'client_name'   => $data['client_name'],
            'client_phone'  => $phoneNorm,
            'client_email'  => $clientEmailSnapshot,
            'notes'         => $data['notes'] ?? null,
            'source'        => $data['source'] ?? 'website',
            'status'        => 'pending',
            'booking_code'  => strtoupper(Str::random(8)),
            'final_price'   => $service->price,
            'currency'      => $service->currency ?? 'AMD',

            'phone_verification_code_hash' => Hash::make($code),
            'phone_verification_expires_at' => $expires,
            'phone_verified_at' => null,
            'phone_verification_attempts' => 0,
        ];

        $booking = Booking::query()->create($this->withoutUnavailableLocationAttribute($bookingPayload, 'bookings'));

        $this->applyPublicBookingBenefits($booking, $client, $data, (int) ($service->price ?? 0));

        $this->sendVerificationNotifications($booking, $code, $expires, $booking->contactEmail());

        return response()->json([
            'data' => [
                'booking_code' => $booking->booking_code,
                'needs_phone_verification' => true,
                'phone' => $phoneNorm,
                'expires_at' => $expires->toISOString(),
            ],
            'meta' => ['business_type' => $business->business_type, 'vertical' => $business->normalizedVertical()],
        ], 201);
    }


    private function applyPublicBookingBenefits(Booking $booking, Client $client, array $payload, int $grossAmount): void
    {
        if ($grossAmount <= 0) {
            return;
        }

        $requestedPoints = (int) ($payload['redeem_points'] ?? 0);
        $giftCardCode = trim((string) ($payload['gift_card_code'] ?? ''));
        $requestedGiftAmount = (int) ($payload['gift_card_amount'] ?? 0);

        if ($requestedPoints <= 0 && $giftCardCode === '') {
            return;
        }

        $systemActor = User::query()
            ->where('business_id', $booking->business_id)
            ->whereIn('role', [User::ROLE_OWNER, User::ROLE_MANAGER])
            ->orderBy('id')
            ->first();
        if (!$systemActor) {
            return;
        }

        $netAmount = $grossAmount;
        $appliedPoints = 0;
        $loyaltyDiscount = 0;
        $giftDiscount = 0;

        if ($requestedPoints > 0) {
            $loyaltyService = app(LoyaltyService::class);
            $program = $loyaltyService->getOrCreateProgram((int) $booking->business_id);
            if ($giftCardCode !== '' && !$program->allow_gift_card_with_points) {
                throw \Illuminate\Validation\ValidationException::withMessages(['gift_card_code' => ['Միաժամանակ միավոր և նվերի քարտ օգտագործել չի թույլատրվում։']]);
            }
            $result = $loyaltyService->redeemForBooking($systemActor, $client, $booking, $requestedPoints, $grossAmount);
            $appliedPoints = (int) ($result['applied_points'] ?? 0);
            $loyaltyDiscount = (int) ($result['discount_amount'] ?? 0);
            $netAmount -= $loyaltyDiscount;
        }

        if ($giftCardCode !== '') {
            $giftService = app(GiftCardService::class);
            $giftCard = $giftService->lookupActiveByCode((int) $booking->business_id, $giftCardCode);
            $giftResult = $giftService->redeemForBooking($systemActor, $giftCard, $booking, $requestedGiftAmount > 0 ? $requestedGiftAmount : $netAmount);
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

    private function assertWithinBusinessHours(Business $business, Carbon $startLocal, Carbon $endLocal, string $field = 'starts_at'): void
    {
        $workStart = $business->work_start ?: '09:00';
        $workEnd = $business->work_end ?: '18:00';

        $windowStart = Carbon::parse($startLocal->format('Y-m-d') . ' ' . $workStart, $startLocal->getTimezone())->seconds(0);
        $windowEnd = Carbon::parse($startLocal->format('Y-m-d') . ' ' . $workEnd, $startLocal->getTimezone())->seconds(0);
        if ($windowEnd->lte($windowStart)) {
            $windowEnd = $windowEnd->addDay();
        }

        if ($startLocal->lt($windowStart) || $endLocal->gt($windowEnd)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                $field => ['Selected time is outside business working hours.'],
            ]);
        }
    }

    private function assertSingleBookingServicesCompatible($services, ?User $staff = null, bool $requireBookable = false): void
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
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'service_id' => ['Selected service is inactive.'],
                ]);
            }
        }

        if ($serviceLocationIds->count() > 1) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'service_id' => ['Selected services must belong to the same location.'],
            ]);
        }

        if ($staff) {
            if (!(bool) $staff->is_active) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'staff_id' => ['Selected staff member is inactive.'],
                ]);
            }

            if ($requireBookable && !(bool) $staff->is_bookable) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'staff_id' => ['Selected staff member is not bookable.'],
                ]);
            }

            if ($staff->location_id && $serviceLocationIds->count() === 1 && (int) $staff->location_id !== (int) $serviceLocationIds->first()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
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
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'lines' => ['One staff member has overlapping lines in the same booking request.'],
                    ]);
                }
            }
        }
    }

    private function resolveBookingLocationId(Business $business, ?int $requestedLocationId = null, ?Service $service = null, ?User $staff = null): ?int
    {
        $resolvedLocationId = $requestedLocationId
            ?: (int) ($service?->location_id ?? 0)
            ?: (int) ($staff?->location_id ?? 0)
            ?: (int) ($business->locations()->where('is_primary', true)->value('id') ?? 0)
            ?: (int) ($business->locations()->orderBy('sort_order')->orderBy('id')->value('id') ?? 0);

        if (!$resolvedLocationId) {
            return null;
        }

        $location = BusinessLocation::query()
            ->where('business_id', $business->id)
            ->find($resolvedLocationId);

        if (!$location) {
            throw \Illuminate\Validation\ValidationException::withMessages(['location_id' => ['Invalid location.']]);
        }

        if ($service && $service->location_id && (int) $service->location_id !== (int) $location->id) {
            throw \Illuminate\Validation\ValidationException::withMessages(['location_id' => ['Selected service belongs to another location.']]);
        }

        if ($staff && $staff->location_id && (int) $staff->location_id !== (int) $location->id) {
            throw \Illuminate\Validation\ValidationException::withMessages(['location_id' => ['Selected staff belongs to another location.']]);
        }

        return (int) $location->id;
    }

    /**
     * POST /api/public/bookings/{code}/verify
     */
    public function verifyPhone(string $code, Request $request)
    {
        $data = $request->validate([
            'otp' => ['required','string','min:4','max:8'],
        ]);

        $booking = Booking::query()->where('booking_code', $code)->firstOrFail();

        if ($booking->phone_verified_at) {
            $plainToken = $this->issueGuestAccessForBooking($booking);
            return response()->json([
                'ok' => true,
                'already' => true,
                'manage_token' => $plainToken,
                'manage_url' => $this->frontendManageUrl($booking, $plainToken),
                'data' => $this->publicBookingPayload($booking),
            ]);
        }

        if (!$booking->phone_verification_expires_at || now()->greaterThan($booking->phone_verification_expires_at)) {
            return response()->json(['message' => 'Code expired. Please request a new code.'], 422);
        }

        if ($booking->phone_verification_attempts >= 5) {
            return response()->json(['message' => 'Too many attempts.'], 429);
        }

        $booking->increment('phone_verification_attempts');

        if (!Hash::check($data['otp'], (string)$booking->phone_verification_code_hash)) {
            return response()->json(['message' => 'Invalid code'], 422);
        }

        $now = now();
        $query = Booking::query()->where('client_id', $booking->client_id);
        if ($booking->group_id) {
            $query->where('group_id', $booking->group_id);
        } else {
            $query->where('id', $booking->id);
        }

        $query->update([
            'phone_verified_at' => $now,
            'phone_verification_code_hash' => null,
            'phone_verification_expires_at' => null,
            'phone_verification_attempts' => 0,
            'status' => 'confirmed',
        ]);

        $booking->refresh();
        $plainToken = $this->issueGuestAccessForBooking($booking);
        $this->sendBookingConfirmedNotifications($booking, $plainToken);
        $this->sendBusinessBookingEmailNotifications($booking);
        $this->sendBookingTelegramNotifications($booking, 'confirmed');

        return response()->json([
            'ok' => true,
            'manage_token' => $plainToken,
            'manage_url' => $this->frontendManageUrl($booking, $plainToken),
            'data' => $this->publicBookingPayload($booking),
        ]);
    }

    public function resendCode(string $code, Request $request)
    {
        $booking = Booking::query()->where('booking_code', $code)->firstOrFail();

        if ($booking->phone_verified_at) {
            $plainToken = $this->issueGuestAccessForBooking($booking);
            return response()->json([
                'ok' => true,
                'already' => true,
                'manage_token' => $plainToken,
                'manage_url' => $this->frontendManageUrl($booking, $plainToken),
                'data' => $this->publicBookingPayload($booking),
            ]);
        }

        $codeValue = (string) random_int(1000, 9999);
        $expires = now()->addMinutes(10);

        $query = Booking::query()->where('client_id', $booking->client_id);
        if ($booking->group_id) {
            $query->where('group_id', $booking->group_id);
        } else {
            $query->where('id', $booking->id);
        }

        $query->update([
            'phone_verification_code_hash' => Hash::make($codeValue),
            'phone_verification_expires_at' => $expires,
            'phone_verification_attempts' => 0,
        ]);

        $booking->refresh();
        $email = $booking->contactEmail();
        $this->sendVerificationNotifications($booking, $codeValue, $expires, $email);

        return response()->json([
            'ok' => true,
            'expires_at' => $expires->toISOString(),
            'message' => 'A new verification code has been sent.',
        ]);
    }

    public function show(string $code, Request $request)
    {
        $booking = Booking::query()
            ->with(['business:id,name,slug,business_type,address,phone,timezone', 'service:id,name,duration_minutes,price,currency', 'staff:id,name', 'items.service:id,name,duration_minutes,price,currency', 'client:id,email'])
            ->where('booking_code', $code)
            ->firstOrFail();

        $this->assertGuestAccess($booking, (string) ($request->bearerToken() ?: $request->query('token') ?: $request->header('X-Guest-Token')));

        return response()->json([
            'data' => $this->publicBookingPayload($booking),
            'meta' => ['business_type' => $booking->business->business_type],
        ]);
    }

    public function rescheduleOptions(string $code, Request $request, AvailabilityService $availability)
    {
        $data = $request->validate([
            'booking_id' => ['nullable', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'staff_id' => ['nullable', 'integer'],
        ]);

        $booking = Booking::query()->where('booking_code', $code)->firstOrFail();
        $token = (string) ($request->bearerToken() ?: $request->query('token') ?: $request->header('X-Guest-Token'));
        $this->assertGuestAccess($booking, $token);

        $target = $this->managedPublicBooking($booking, isset($data['booking_id']) ? (int) $data['booking_id'] : null);
        if (!$this->canReschedulePublicBooking($target)) {
            return response()->json([
                'message' => 'This booking can no longer be rescheduled online.',
                'data' => [],
                'meta' => [
                    'booking_id' => $target->id,
                    'can_reschedule' => false,
                    'reschedule_cutoff_hours' => $this->rescheduleCutoffHours(),
                    'reschedule_deadline' => $this->rescheduleDeadline($target),
                ],
            ], 422);
        }

        $target->loadMissing(['business', 'service', 'staff', 'items.service']);
        $serviceIds = $this->managedBookingServiceIds($target);
        if (!$serviceIds) {
            return response()->json(['message' => 'The booking services are unavailable.'], 422);
        }

        $requestedStaffId = isset($data['staff_id']) ? (int) $data['staff_id'] : null;
        if ($requestedStaffId) {
            $staff = User::query()
                ->whereKey($requestedStaffId)
                ->where('business_id', $target->business_id)
                ->when($target->location_id, fn ($query) => $this->applyLocationCompatibility($query, (int) $target->location_id, 'users'))
                ->where('is_active', true)
                ->where('is_bookable', true)
                ->first();

            if (!$staff) {
                return response()->json(['message' => 'The selected specialist is unavailable.'], 422);
            }
        }

        $slots = $availability->slotsForSelection(
            serviceIds: $serviceIds,
            date: $data['date'],
            businessId: (int) $target->business_id,
            staffId: $requestedStaffId ?: null,
            locationId: $target->location_id ? (int) $target->location_id : null,
            excludeBookingIds: [(int) $target->id],
        );

        $timezone = $target->business?->effectiveTimezone() ?? 'Asia/Yerevan';
        $currentStart = $target->starts_at?->copy()->timezone($timezone)->format('Y-m-d H:i:s');
        $earliestStart = Carbon::now($timezone)->addHours($this->rescheduleCutoffHours());
        $slots = array_values(array_filter($slots, function (array $slot) use ($target, $currentStart, $timezone, $earliestStart) {
            try {
                $slotStart = Carbon::createFromFormat('Y-m-d H:i:s', (string) ($slot['starts_at'] ?? ''), $timezone);
            } catch (\Throwable $exception) {
                return false;
            }

            return $slotStart->gte($earliestStart) && !(
                (int) ($slot['staff_id'] ?? 0) === (int) $target->staff_id
                && (string) ($slot['starts_at'] ?? '') === (string) $currentStart
            );
        }));

        return response()->json([
            'data' => $slots,
            'meta' => [
                'booking_id' => $target->id,
                'can_reschedule' => true,
                'reschedule_cutoff_hours' => $this->rescheduleCutoffHours(),
                'reschedule_deadline' => $this->rescheduleDeadline($target),
            ],
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
          ->header('Pragma', 'no-cache');
    }

    public function reschedule(string $code, Request $request)
    {
        $data = $request->validate([
            'booking_id' => ['nullable', 'integer'],
            'staff_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date_format:Y-m-d H:i'],
        ]);

        $booking = Booking::query()->where('booking_code', $code)->firstOrFail();
        $token = (string) ($request->bearerToken() ?: $request->query('token') ?: $request->header('X-Guest-Token'));
        $this->assertGuestAccess($booking, $token);

        $target = $this->managedPublicBooking($booking, isset($data['booking_id']) ? (int) $data['booking_id'] : null);
        if (!$this->canReschedulePublicBooking($target)) {
            return response()->json([
                'message' => 'This booking can no longer be rescheduled online.',
                'data' => $this->publicBookingPayload($booking),
            ], 422);
        }

        $target->loadMissing(['business', 'service', 'staff', 'items.service']);
        $business = $target->business;
        abort_unless($business, 404);

        $staff = User::query()
            ->whereKey((int) $data['staff_id'])
            ->where('business_id', $business->id)
            ->when($target->location_id, fn ($query) => $this->applyLocationCompatibility($query, (int) $target->location_id, 'users'))
            ->where('is_active', true)
            ->where('is_bookable', true)
            ->first();
        if (!$staff) {
            return response()->json(['message' => 'The selected specialist is unavailable.'], 422);
        }

        $serviceIds = $this->managedBookingServiceIds($target);
        $services = Service::query()
            ->whereIn('id', $serviceIds)
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->get();
        if (!$serviceIds || $services->count() !== count($serviceIds)) {
            return response()->json(['message' => 'The booking services are unavailable.'], 422);
        }

        $this->assertSingleBookingServicesCompatible($services, $staff, true);

        $duration = $target->starts_at && $target->ends_at
            ? (int) $target->starts_at->diffInMinutes($target->ends_at)
            : (int) $services->sum(fn (Service $service) => (int) $service->duration_minutes);
        if ($duration < 5 || $duration > 600) {
            return response()->json(['message' => 'The booking duration is invalid.'], 422);
        }

        $timezone = $business->effectiveTimezone();
        $step = max(5, min(60, (int) ($business->slot_step_minutes ?? 15)));
        try {
            $startLocal = Carbon::createFromFormat('Y-m-d H:i', $data['starts_at'], $timezone)->seconds(0);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Invalid starts_at.'], 422);
        }
        if (((int) $startLocal->minute % $step) !== 0) {
            return response()->json(['message' => 'Choose one of the available time slots.'], 422);
        }
        $endLocal = $startLocal->copy()->addMinutes($duration)->seconds(0);

        if ($startLocal->lt(Carbon::now($timezone)->addHours($this->rescheduleCutoffHours()))) {
            return response()->json([
                'message' => 'Choose a time outside the online rescheduling cutoff.',
            ], 422);
        }

        $this->assertWithinBusinessHours($business, $startLocal, $endLocal);

        $startUtc = $startLocal->copy()->setTimezone('UTC');
        if (
            (int) $target->staff_id === (int) $staff->id
            && $target->starts_at?->equalTo($startUtc)
        ) {
            return response()->json([
                'unchanged' => true,
                'data' => $this->publicBookingPayload($booking),
            ]);
        }

        $oldStart = $target->starts_at?->copy();
        $oldEnd = $target->ends_at?->copy();
        $oldStaff = $target->staff;

        $updated = DB::transaction(function () use ($target, $business, $staff, $startLocal, $endLocal) {
            $locked = Booking::query()->lockForUpdate()->findOrFail($target->id);
            if (!$this->canReschedulePublicBooking($locked)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'starts_at' => ['This booking can no longer be rescheduled online.'],
                ]);
            }

            $this->checkOverlap((int) $business->id, (int) $staff->id, $startLocal, $endLocal, (int) $locked->id);
            $this->checkBlocked((int) $business->id, (int) $staff->id, $startLocal, $endLocal);
            $roomId = $this->resolveRescheduleRoomId($locked, $business, $startLocal, $endLocal);

            $locked->update([
                'staff_id' => $staff->id,
                'room_id' => $roomId,
                'starts_at' => $startLocal->copy()->setTimezone('UTC')->format('Y-m-d H:i:s'),
                'ends_at' => $endLocal->copy()->setTimezone('UTC')->format('Y-m-d H:i:s'),
            ]);

            return $locked->fresh(['business.owner', 'service', 'staff', 'client', 'items.service']);
        });

        $booking->refresh();
        if ($oldStart && $oldEnd) {
            $this->sendBookingRescheduledNotifications($booking, $updated, $oldStart, $oldEnd, $oldStaff, $token);
        }

        return response()->json([
            'data' => $this->publicBookingPayload($booking),
        ]);
    }

    public function cancel(string $code, Request $request)
    {
        $booking = Booking::query()->where('booking_code', $code)->firstOrFail();
        $this->assertGuestAccess($booking, (string) ($request->bearerToken() ?: $request->query('token') ?: $request->header('X-Guest-Token')));

        if (in_array($booking->status, ['cancelled', 'done', 'completed', 'no_show'], true)) {
            return response()->json(['data' => $this->publicBookingPayload($booking)]);
        }

        if (!$this->canCancelPublicBooking($booking)) {
            return response()->json([
                'message' => 'This booking can no longer be cancelled online.',
                'data' => $this->publicBookingPayload($booking),
            ], 422);
        }

        $query = Booking::query()->where('client_id', $booking->client_id);
        if ($booking->group_id) {
            $query->where('group_id', $booking->group_id);
        } else {
            $query->where('id', $booking->id);
        }
        $query->update(['status' => 'cancelled']);
        $booking->refresh();
        $this->sendBookingCancelledNotifications($booking);
        $this->sendBookingTelegramNotifications($booking, 'cancelled');

        return response()->json(['data' => $this->publicBookingPayload($booking)]);
    }

    private function issueGuestAccessForBooking(Booking $booking): string
    {
        $plainToken = Str::random(40);
        $expires = now()->addDays(7);

        $query = Booking::query()->where('client_id', $booking->client_id);
        if ($booking->group_id) {
            $query->where('group_id', $booking->group_id);
        } else {
            $query->where('id', $booking->id);
        }

        $query->update([
            'guest_access_token_hash' => Hash::make($plainToken),
            'guest_access_expires_at' => $expires,
        ]);

        return $plainToken;
    }

    private function assertGuestAccess(Booking $booking, string $token): void
    {
        abort_unless($booking->phone_verified_at, 403, 'Booking is not verified yet.');
        abort_unless($token !== '', 401, 'Guest access token is required.');
        abort_unless($booking->guest_access_expires_at && now()->lte($booking->guest_access_expires_at), 401, 'Guest access has expired.');
        abort_unless(Hash::check($token, (string) $booking->guest_access_token_hash), 401, 'Invalid guest access token.');
    }

    private function publicBookingPayload(Booking $booking): array
    {
        $booking->loadMissing(['business:id,name,slug,business_type,address,phone,timezone', 'service:id,name,duration_minutes,price,currency', 'staff:id,name', 'items.service:id,name,duration_minutes,price,currency', 'client:id,email']);

        $related = Booking::query()
            ->with(['service:id,name,duration_minutes,price,currency', 'staff:id,name', 'items.service:id,name,duration_minutes,price,currency'])
            ->where('client_id', $booking->client_id)
            ->when($booking->group_id, fn ($q) => $q->where('group_id', $booking->group_id), fn ($q) => $q->where('id', $booking->id))
            ->orderBy('starts_at')
            ->get();

        $totalPrice = $related->contains(fn (Booking $item) => $item->final_price === null)
            ? null
            : $related->sum(fn (Booking $item) => (int) ($item->final_price ?? 0));

        $publicBookings = $related->map(function (Booking $item) {
            $payload = $item
                ->makeHidden(['phone_verification_code_hash', 'guest_access_token_hash'])
                ->toArray();
            $payload['can_reschedule'] = $this->canReschedulePublicBooking($item);
            $payload['reschedule_deadline'] = $this->rescheduleDeadline($item);

            return $payload;
        })->values();

        return [
            'booking_code' => $booking->booking_code,
            'status' => $booking->status,
            'status_label' => $this->publicStatusLabel($booking->status),
            'client_name' => $booking->client_name,
            'client_phone' => $booking->client_phone,
            'client_email' => $booking->contactEmail(),
            'notes' => $booking->notes,
            'phone_verified_at' => $booking->phone_verified_at?->toISOString(),
            'guest_access_expires_at' => $booking->guest_access_expires_at?->toISOString(),
            'can_cancel' => $this->canCancelPublicBooking($related->first() ?: $booking, $related),
            'can_reschedule' => $related->contains(fn (Booking $item) => $this->canReschedulePublicBooking($item)),
            'reschedule_cutoff_hours' => $this->rescheduleCutoffHours(),
            'total_price' => $totalPrice,
            'currency' => $booking->currency ?? $related->first()?->currency,
            'business' => $booking->business,
            'primary_booking' => $publicBookings->first(),
            'bookings' => $publicBookings,
        ];
    }

    private function managedPublicBooking(Booking $booking, ?int $bookingId = null): Booking
    {
        $query = Booking::query()
            ->where('business_id', $booking->business_id)
            ->where('client_id', $booking->client_id)
            ->when(
                $booking->group_id,
                fn ($related) => $related->where('group_id', $booking->group_id),
                fn ($related) => $related->where('id', $booking->id),
            );

        if ($bookingId) {
            $query->whereKey($bookingId);
        }

        return $query->firstOrFail();
    }

    private function managedBookingServiceIds(Booking $booking): array
    {
        $booking->loadMissing(['items', 'service']);
        $ids = $booking->items
            ->pluck('service_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        if (!$ids && $booking->service_id) {
            $ids[] = (int) $booking->service_id;
        }

        return array_values(array_unique($ids));
    }

    private function rescheduleCutoffHours(): int
    {
        return max(0, (int) config('services.public_booking.reschedule_cutoff_hours', 12));
    }

    private function canReschedulePublicBooking(Booking $booking): bool
    {
        if (!in_array($booking->status, ['pending', 'confirmed'], true) || !$booking->starts_at) {
            return false;
        }

        return now()->addHours($this->rescheduleCutoffHours())->lte($booking->starts_at);
    }

    private function rescheduleDeadline(Booking $booking): ?string
    {
        if (!$booking->starts_at) {
            return null;
        }

        return $booking->starts_at
            ->copy()
            ->subHours($this->rescheduleCutoffHours())
            ->toISOString();
    }

    private function resolveRescheduleRoomId(Booking $booking, Business $business, Carbon $startLocal, Carbon $endLocal): ?int
    {
        if (!$business->isHealthcareVertical() || !$booking->room_id) {
            return $booking->room_id ? (int) $booking->room_id : null;
        }

        $startUtc = $startLocal->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');
        $endUtc = $endLocal->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');
        $busyRoomIds = Booking::query()
            ->where('business_id', $business->id)
            ->where('id', '!=', $booking->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereNotNull('room_id')
            ->where('starts_at', '<', $endUtc)
            ->where('ends_at', '>', $startUtc)
            ->pluck('room_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $currentRoomIsActive = Room::query()
            ->whereKey((int) $booking->room_id)
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->exists();

        if ($currentRoomIsActive && !$busyRoomIds->contains((int) $booking->room_id)) {
            return (int) $booking->room_id;
        }

        $replacementRoomId = Room::query()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->whereNotIn('id', $busyRoomIds)
            ->orderBy('id')
            ->value('id');

        if (!$replacementRoomId) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'starts_at' => ['No room is available at the selected time.'],
            ]);
        }

        return (int) $replacementRoomId;
    }

    private function canCancelPublicBooking(Booking $booking, $related = null): bool
    {
        if (!in_array($booking->status, ['pending', 'confirmed'], true)) {
            return false;
        }

        $items = $related instanceof \Illuminate\Support\Collection
            ? $related
            : Booking::query()
                ->where('client_id', $booking->client_id)
                ->when($booking->group_id, fn ($q) => $q->where('group_id', $booking->group_id), fn ($q) => $q->where('id', $booking->id))
                ->orderBy('starts_at')
                ->get();

        $firstUpcoming = $items->first();
        if (!$firstUpcoming || !$firstUpcoming->starts_at) {
            return false;
        }

        return now()->lt($firstUpcoming->starts_at);
    }

    private function sendBookingRescheduledNotifications(
        Booking $rootBooking,
        Booking $booking,
        Carbon $oldStart,
        Carbon $oldEnd,
        ?User $oldStaff,
        string $guestToken,
    ): void {
        $booking->loadMissing(['business.owner', 'service', 'staff', 'client', 'items.service']);
        $targetEmail = $booking->contactEmail();

        if ($targetEmail) {
            try {
                Mail::send('emails.public_booking_rescheduled', [
                    'booking' => $booking,
                    'oldStart' => $oldStart,
                    'oldEnd' => $oldEnd,
                    'oldStaff' => $oldStaff,
                    'manageLink' => $this->frontendManageUrl($rootBooking, $guestToken),
                ], function ($message) use ($targetEmail, $booking) {
                    $message
                        ->to($targetEmail)
                        ->subject('Ձեր ամրագրման ժամը փոխվել է • ' . ($booking->business?->name ?? 'Vizit'));
                });
            } catch (\Throwable $exception) {
                \Log::warning('Public booking reschedule customer email failed', [
                    'booking_id' => $booking->id,
                    'email' => $targetEmail,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        try {
            $recipients = collect([$booking->business?->owner, $oldStaff, $booking->staff])
                ->filter()
                ->unique('id');

            foreach ($recipients as $recipient) {
                $recipient->notifyNow(new BookingRescheduledNotification(
                    booking: $booking,
                    oldStart: $oldStart,
                    oldEnd: $oldEnd,
                    oldStaffName: $oldStaff?->name,
                ));
            }
        } catch (\Throwable $exception) {
            \Log::warning('Public booking reschedule business email failed', [
                'booking_id' => $booking->id,
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            /** @var TelegramService $telegram */
            $telegram = app(TelegramService::class);
            if (!$booking->business || !$telegram->enabled()) {
                return;
            }

            $timezone = $booking->business->effectiveTimezone();
            $oldTime = $oldStart->copy()->timezone($timezone)->format('d.m.Y H:i');
            $newTime = $booking->starts_at?->copy()->timezone($timezone)->format('d.m.Y H:i') ?? '—';
            $services = $booking->items->count()
                ? $booking->items->map(fn ($item) => $item->service?->name)->filter()->implode(', ')
                : ($booking->service?->name ?? 'Ծառայություն');

            $message = implode("\n", [
                '🔁 Հաճախորդը փոխել է ամրագրման ժամը',
                'Բիզնես՝ ' . $booking->business->name,
                'Կոդ՝ ' . ($rootBooking->booking_code ?: $booking->booking_code),
                'Հաճախորդ՝ ' . ($booking->client_name ?: '—'),
                'Ծառայություն՝ ' . $services,
                'Նախկին ժամ՝ ' . $oldTime,
                'Նոր ժամ՝ ' . $newTime,
                'Նախկին մասնագետ՝ ' . ($oldStaff?->name ?? '—'),
                'Նոր մասնագետ՝ ' . ($booking->staff?->name ?? '—'),
            ]);

            $businessChatIds = $telegram->bookingChatIdsForBusiness($booking->business);
            $telegram->sendToMany($businessChatIds, $message);

            $staffChatIds = collect([$oldStaff, $booking->staff])
                ->filter()
                ->flatMap(fn (User $staff) => $telegram->staffBookingChatIds($staff))
                ->diff($businessChatIds)
                ->unique()
                ->values()
                ->all();
            $telegram->sendToMany($staffChatIds, $message);
        } catch (\Throwable $exception) {
            \Log::warning('Public booking reschedule Telegram notification failed', [
                'booking_id' => $booking->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function sendBookingTelegramNotifications(Booking $booking, string $event): void
    {
        try {
            /** @var TelegramService $telegram */
            $telegram = app(TelegramService::class);
            $booking->loadMissing(['business']);

            if (!$booking->business || !$telegram->enabled()) {
                return;
            }

            $ownerChatIds = $telegram->bookingChatIdsForBusiness($booking->business);
            $ownerMessage = $event === 'cancelled'
                ? $telegram->bookingCancelledMessage($booking)
                : $telegram->bookingConfirmedMessage($booking);
            $telegram->sendToMany($ownerChatIds, $ownerMessage);

            $relatedBookings = Booking::query()
                ->with(['staff', 'service', 'items.service', 'business'])
                ->where('client_id', $booking->client_id)
                ->when($booking->group_id, fn ($q) => $q->where('group_id', $booking->group_id), fn ($q) => $q->where('id', $booking->id))
                ->whereNotNull('staff_id')
                ->orderBy('starts_at')
                ->get();

            $notifiedStaffIds = [];
            foreach ($relatedBookings->pluck('staff')->filter()->unique('id')->values() as $staff) {
                if (isset($notifiedStaffIds[$staff->id])) {
                    continue;
                }
                $notifiedStaffIds[$staff->id] = true;

                $staffChatIds = array_values(array_diff(
                    $telegram->staffBookingChatIds($staff),
                    $ownerChatIds
                ));

                if (empty($staffChatIds)) {
                    continue;
                }

                $staffMessage = $event === 'cancelled'
                    ? $telegram->staffBookingCancelledMessage($booking, $staff)
                    : $telegram->staffBookingConfirmedMessage($booking, $staff);

                $telegram->sendToMany($staffChatIds, $staffMessage);
            }
        } catch (\Throwable $ex) {
            \Log::warning('Telegram booking notification failed', [
                'booking_id' => $booking->id,
                'event' => $event,
                'error' => $ex->getMessage(),
            ]);
        }
    }

    private function frontendBaseUrl(): string
    {
        return rtrim((string) config('services.public_booking.frontend_url', 'https://vizit.am'), '/');
    }

    private function frontendManageUrl(Booking $booking, string $token): string
    {
        $base = $this->frontendBaseUrl();
        $slug = $booking->business?->slug ?: 'booking';
        return $base . '/book/' . $slug . '?booking=' . urlencode((string) $booking->booking_code) . '&token=' . urlencode($token);
    }

    private function sendBusinessBookingEmailNotifications(Booking $booking): void
    {
        try {
            $booking->loadMissing(['business.owner', 'staff', 'service', 'client', 'items.service']);
            $related = Booking::query()
                ->with('staff')
                ->where('client_id', $booking->client_id)
                ->when($booking->group_id, fn ($query) => $query->where('group_id', $booking->group_id), fn ($query) => $query->where('id', $booking->id))
                ->get();

            $recipients = collect([$booking->business?->owner])
                ->merge($related->pluck('staff'))
                ->filter()
                ->unique('id');

            foreach ($recipients as $recipient) {
                $recipient->notifyNow(new NewBookingNotification($booking));
            }
        } catch (\Throwable $ex) {
            \Log::warning('Public booking business email notification failed', [
                'booking_id' => $booking->id,
                'error' => $ex->getMessage(),
            ]);
        }
    }

    private function sendVerificationNotifications(Booking $booking, string $code, $expires, ?string $email = null): void
    {
        $booking->loadMissing(['business', 'service', 'staff', 'client', 'items.service']);
        $phone = (string) $booking->client_phone;
        $bookingPageUrl = $this->frontendBookingPageUrl($booking);
        $verifyMessage = $this->buildVerificationMessage($booking, $code, $expires, $bookingPageUrl);

        try {
            app(SmsService::class)->send($phone, $verifyMessage);
        } catch (\Throwable $ex) {
            \Log::warning('Public booking SMS verification delivery failed', [
                'booking_id' => $booking->id,
                'error' => $ex->getMessage(),
            ]);
        }

        try {
            app(SmsService::class)->send($phone, $verifyMessage, 'whatsapp');
        } catch (\Throwable $ex) {
            \Log::warning('Public booking WhatsApp verification delivery failed', [
                'booking_id' => $booking->id,
                'error' => $ex->getMessage(),
            ]);
        }

        $targetEmail = $email ?: $booking->contactEmail();
        if ($targetEmail) {
            try {
                Mail::send('emails.public_booking_verification', [
                    'booking' => $booking,
                    'code' => $code,
                    'expires' => $expires,
                    'manageLink' => $bookingPageUrl,
                ], function ($m) use ($targetEmail, $booking) {
                    $m->to($targetEmail)->subject('Հաստատեք ձեր ամրագրումը • ' . ($booking->business?->name ?? 'Vizit'));
                });
            } catch (\Throwable $ex) {
                \Log::warning('Public booking verification email delivery failed', [
                    'booking_id' => $booking->id,
                    'email' => $targetEmail,
                    'error' => $ex->getMessage(),
                ]);
            }
        }
    }

    private function sendBookingConfirmedNotifications(Booking $booking, string $plainToken): void
    {
        $booking->loadMissing(['business', 'service', 'staff', 'client', 'items.service']);
        $manageUrl = $this->frontendManageUrl($booking, $plainToken);
        $message = $this->buildConfirmedMessage($booking, $manageUrl);

        try {
            app(SmsService::class)->send((string) $booking->client_phone, $message);
        } catch (\Throwable $ex) {
            \Log::warning('Public booking confirmation SMS delivery failed', [
                'booking_id' => $booking->id,
                'error' => $ex->getMessage(),
            ]);
        }

        try {
            app(SmsService::class)->send((string) $booking->client_phone, $message, 'whatsapp');
        } catch (\Throwable $ex) {
            \Log::warning('Public booking confirmation WhatsApp delivery failed', [
                'booking_id' => $booking->id,
                'error' => $ex->getMessage(),
            ]);
        }

        $targetEmail = $booking->contactEmail();
        if ($targetEmail) {
            try {
                Mail::send('emails.public_booking_confirmed', [
                    'booking' => $booking,
                    'manageLink' => $manageUrl,
                ], function ($m) use ($targetEmail, $booking) {
                    $m->to($targetEmail)->subject('Ձեր ամրագրումը հաստատված է • ' . ($booking->business?->name ?? 'Vizit'));
                });
            } catch (\Throwable $ex) {
                \Log::warning('Public booking confirmation email delivery failed', [
                    'booking_id' => $booking->id,
                    'email' => $targetEmail,
                    'error' => $ex->getMessage(),
                ]);
            }
        }
    }

    private function sendBookingCancelledNotifications(Booking $booking): void
    {
        $booking->loadMissing(['business', 'service', 'staff', 'client', 'items.service']);
        $message = $this->buildCancelledMessage($booking);

        try {
            app(SmsService::class)->send((string) $booking->client_phone, $message);
        } catch (\Throwable $ex) {
            \Log::warning('Public booking cancellation SMS delivery failed', [
                'booking_id' => $booking->id,
                'error' => $ex->getMessage(),
            ]);
        }

        try {
            app(SmsService::class)->send((string) $booking->client_phone, $message, 'whatsapp');
        } catch (\Throwable $ex) {
            \Log::warning('Public booking cancellation WhatsApp delivery failed', [
                'booking_id' => $booking->id,
                'error' => $ex->getMessage(),
            ]);
        }

        $targetEmail = $booking->contactEmail();
        if ($targetEmail) {
            try {
                Mail::send('emails.public_booking_cancelled', [
                    'booking' => $booking,
                ], function ($m) use ($targetEmail, $booking) {
                    $m->to($targetEmail)->subject('Ամրագրումը չեղարկվել է • ' . ($booking->business?->name ?? 'Vizit'));
                });
            } catch (\Throwable $ex) {
            }
        }
    }

    private function frontendBookingPageUrl(Booking $booking): string
    {
        $base = $this->frontendBaseUrl();
        $slug = $booking->business?->slug ?: 'booking';
        return $base . '/book/' . $slug . '?booking=' . urlencode((string) $booking->booking_code);
    }

    private function bookingServicesLabel(Booking $booking): string
    {
        $booking->loadMissing(['service', 'items.service']);

        $names = $booking->items
            ->map(fn ($item) => $item->service?->name)
            ->filter()
            ->values();

        if ($names->isNotEmpty()) {
            return $names->implode(', ');
        }

        return $booking->service?->name ?? 'Ծառայություն';
    }

    private function bookingDateLabel(Booking $booking): string
    {
        $tz = $booking->business?->effectiveTimezone() ?? 'Asia/Yerevan';
        return $booking->starts_at?->copy()?->timezone($tz)?->format('d.m.Y H:i') ?? '—';
    }

    private function publicStatusLabel(string $status): string
    {
        return match ($status) {
            'confirmed' => 'Հաստատված',
            'cancelled' => 'Չեղարկված',
            'done', 'completed' => 'Ավարտված',
            default => 'Սպասման մեջ',
        };
    }

    private function buildVerificationMessage(Booking $booking, string $code, $expires, string $bookingPageUrl): string
    {
        $tz = $booking->business?->effectiveTimezone() ?? 'Asia/Yerevan';

        return implode("
", [
            ($booking->business?->name ?? 'Vizit') . ' • ամրագրման հաստատում',
            'Կոդ՝ ' . $code,
            'Վավեր է մինչև ' . $expires->copy()->timezone($tz)->format('H:i') . '։',
            'Ծառայություն՝ ' . $this->bookingServicesLabel($booking),
            'Ժամ՝ ' . $this->bookingDateLabel($booking),
            'Էջ՝ ' . $bookingPageUrl,
        ]);
    }

    private function buildConfirmedMessage(Booking $booking, string $manageUrl): string
    {
        return implode("
", [
            ($booking->business?->name ?? 'Vizit') . ' • ամրագրումը հաստատված է',
            'Ծառայություն՝ ' . $this->bookingServicesLabel($booking),
            'Ժամ՝ ' . $this->bookingDateLabel($booking),
            'Կառավարել ամրագրումը՝ ' . $manageUrl,
        ]);
    }

    private function buildCancelledMessage(Booking $booking): string
    {
        return implode("
", [
            ($booking->business?->name ?? 'Vizit') . ' • ամրագրումը չեղարկվել է',
            'Ծառայություն՝ ' . $this->bookingServicesLabel($booking),
            'Ժամ՝ ' . $this->bookingDateLabel($booking),
        ]);
    }

    private function dedupeSmartSlotsByTime(array $slots): array
    {
        usort($slots, function (array $a, array $b) {
            $scoreA = (int) ($a['smart_score'] ?? 0);
            $scoreB = (int) ($b['smart_score'] ?? 0);

            if ($scoreA === $scoreB) {
                return strcmp((string) $a['starts_at'], (string) $b['starts_at']);
            }

            return $scoreB <=> $scoreA;
        });

        $grouped = [];
        foreach ($slots as $slot) {
            $key = ($slot['starts_at'] ?? '') . '|' . ($slot['ends_at'] ?? '');
            if (!isset($grouped[$key])) {
                $grouped[$key] = $slot;
                continue;
            }

            $existingScore = (int) ($grouped[$key]['smart_score'] ?? 0);
            $candidateScore = (int) ($slot['smart_score'] ?? 0);
            if ($candidateScore > $existingScore) {
                $grouped[$key] = $slot;
            }
        }

        $result = array_values($grouped);
        usort($result, function (array $a, array $b) {
            $scoreA = (int) ($a['smart_score'] ?? 0);
            $scoreB = (int) ($b['smart_score'] ?? 0);
            if ($scoreA === $scoreB) {
                return strcmp((string) $a['starts_at'], (string) $b['starts_at']);
            }
            return $scoreB <=> $scoreA;
        });

        return $result;
    }

    private function markRecommendedSlots(array $slots, int $limit = 3): array
    {
        $rank = 0;
        foreach ($slots as &$slot) {
            $slot['is_recommended'] = $rank < $limit;
            $slot['recommendation_rank'] = $rank < $limit ? $rank + 1 : null;
            $rank++;
        }
        unset($slot);

        usort($slots, function (array $a, array $b) {
            return strcmp((string) $a['starts_at'], (string) $b['starts_at']);
        });

        return $slots;
    }

    private function buildSmartMultiSlotsForStaff(Business $business, User $staff, string $date, int $totalDuration): array
    {
        $step = max(5, min(60, (int) ($business->slot_step_minutes ?? 15)));
        $workStart = $business->work_start ?: '09:00';
        $workEnd = $business->work_end ?: '18:00';
        $tz = $business->effectiveTimezone();

        try {
            $dayStart = Carbon::parse($date . ' ' . $workStart, $tz)->seconds(0);
            $dayEnd = Carbon::parse($date . ' ' . $workEnd, $tz)->seconds(0);
        } catch (\Throwable $e) {
            return [];
        }

        if ($dayEnd->lte($dayStart)) {
            $dayEnd = $dayEnd->addDay();
        }

        $lastStart = $dayEnd->copy()->subMinutes($totalDuration);
        if ($lastStart->lt($dayStart)) {
            return [];
        }

        $now = Carbon::now($tz)->seconds(0);
        $isToday = $dayStart->toDateString() === $now->toDateString();
        if ($dayEnd->lt($now)) {
            return [];
        }

        $dayStartUtc = $dayStart->copy()->setTimezone('UTC');
        $dayEndUtc = $dayEnd->copy()->setTimezone('UTC');

        $busyBookings = Booking::query()
            ->where('business_id', $business->id)
            ->where('staff_id', $staff->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            ->where('starts_at', '<', $dayEndUtc->format('Y-m-d H:i:s'))
            ->where('ends_at', '>', $dayStartUtc->format('Y-m-d H:i:s'))
            ->get(['starts_at', 'ends_at', 'room_id']);

        $busyBlocks = BookingBlock::query()
            ->where('business_id', $business->id)
            ->where(function ($q) use ($staff) {
                $q->whereNull('staff_id')->orWhere('staff_id', $staff->id);
            })
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            ->where('starts_at', '<', $dayEndUtc->format('Y-m-d H:i:s'))
            ->where('ends_at', '>', $dayStartUtc->format('Y-m-d H:i:s'))
            ->get(['starts_at', 'ends_at', 'staff_id']);

        $intervals = [];
        foreach ($busyBookings as $booking) {
            $start = $booking->starts_at instanceof Carbon ? $booking->starts_at->copy()->setTimezone($tz) : Carbon::parse($booking->starts_at, 'UTC')->setTimezone($tz);
            $end = $booking->ends_at instanceof Carbon ? $booking->ends_at->copy()->setTimezone($tz) : Carbon::parse($booking->ends_at, 'UTC')->setTimezone($tz);
            if ($start->lt($dayEnd) && $end->gt($dayStart)) {
                $intervals[] = ['start' => $start, 'end' => $end];
            }
        }
        foreach ($busyBlocks as $block) {
            $start = $block->starts_at instanceof Carbon ? $block->starts_at->copy()->setTimezone($tz) : Carbon::parse($block->starts_at, 'UTC')->setTimezone($tz);
            $end = $block->ends_at instanceof Carbon ? $block->ends_at->copy()->setTimezone($tz) : Carbon::parse($block->ends_at, 'UTC')->setTimezone($tz);
            if ($start->lt($dayEnd) && $end->gt($dayStart)) {
                $intervals[] = ['start' => $start, 'end' => $end];
            }
        }

        usort($intervals, function (array $a, array $b) {
            return strcmp($a['start']->format('Y-m-d H:i:s'), $b['start']->format('Y-m-d H:i:s'));
        });
        $merged = [];
        foreach ($intervals as $interval) {
            if (!$merged) {
                $merged[] = $interval;
                continue;
            }
            $lastIndex = count($merged) - 1;
            if ($interval['start']->lte($merged[$lastIndex]['end'])) {
                if ($interval['end']->gt($merged[$lastIndex]['end'])) {
                    $merged[$lastIndex]['end'] = $interval['end'];
                }
            } else {
                $merged[] = $interval;
            }
        }

        $slots = [];
        for ($t = $dayStart->copy(); $t->lte($lastStart); $t->addMinutes($step)) {
            $start = $t->copy();
            $end = $t->copy()->addMinutes($totalDuration);

            if ($isToday && $start->lte($now->copy()->addMinutes(5))) {
                continue;
            }

            $collides = false;
            foreach ($merged as $interval) {
                if ($interval['start']->lt($end) && $interval['end']->gt($start)) {
                    $collides = true;
                    break;
                }
            }
            if ($collides) {
                continue;
            }

            $prevBoundary = $dayStart->copy();
            $nextBoundary = $dayEnd->copy();
            foreach ($merged as $interval) {
                if ($interval['end']->lte($start) && $interval['end']->gt($prevBoundary)) {
                    $prevBoundary = $interval['end']->copy();
                }
                if ($interval['start']->gte($end)) {
                    $nextBoundary = $interval['start']->copy();
                    break;
                }
            }

            $gapBefore = max(0, $prevBoundary->diffInMinutes($start, false));
            $gapAfter = max(0, $end->diffInMinutes($nextBoundary, false));

            $score = 50;
            if ($gapBefore === 0) $score += 18;
            elseif ($gapBefore <= $step) $score += 8;
            elseif ($gapBefore < $totalDuration) $score -= 10;
            else $score -= min(14, (int) floor($gapBefore / max(1, $step * 2)));

            if ($gapAfter === 0) $score += 18;
            elseif ($gapAfter <= $step) $score += 8;
            elseif ($gapAfter < $totalDuration) $score -= 10;
            else $score -= min(14, (int) floor($gapAfter / max(1, $step * 2)));

            if ($gapBefore === 0 && $gapAfter === 0) {
                $score += 16;
                $reason = 'Փակում է ազատ պատուհանը երկու կողմից';
            } elseif ($gapBefore === 0 || $gapAfter === 0) {
                $score += 8;
                $reason = 'Օգնում է օրացույցը պահել կոմպակտ';
            } elseif ($gapBefore < $totalDuration || $gapAfter < $totalDuration) {
                $reason = 'Մոտ է զբաղված հատվածին';
            } else {
                $reason = 'Սովորական ազատ ժամ';
            }

            $slot = [
                'starts_at' => $start->format('Y-m-d H:i:s'),
                'ends_at' => $end->format('Y-m-d H:i:s'),
                'staff_id' => (int) $staff->id,
                'staff_name' => $staff->name,
                'smart_score' => max(0, min(100, $score)),
                'smart_reason' => $reason,
                'gap_before_minutes' => $gapBefore,
                'gap_after_minutes' => $gapAfter,
            ];

            if (method_exists($business, 'isDental') && $business->isDental()) {
                $roomIds = collect($busyBookings)
                    ->whereNotNull('room_id')
                    ->filter(function ($booking) use ($start, $end, $tz) {
                        $bs = $booking->starts_at instanceof Carbon ? $booking->starts_at->copy() : Carbon::parse($booking->starts_at, $tz);
                        $be = $booking->ends_at instanceof Carbon ? $booking->ends_at->copy() : Carbon::parse($booking->ends_at, $tz);
                        return $bs->lt($end) && $be->gt($start);
                    })
                    ->pluck('room_id')
                    ->unique()
                    ->values()
                    ->toArray();
                $rooms = \App\Models\Room::where('business_id', $business->id)
                    ->where('is_active', true)
                    ->get(['id', 'name', 'type']);
                $slot['available_rooms'] = $rooms->whereNotIn('id', $roomIds)->values()->toArray();
            }

            $slots[] = $slot;
        }

        return $slots;
    }

    private function serializePublicBusiness(Business $business, ?float $lat = null, ?float $lng = null): array
    {
        $locations = ($this->hasTable('business_locations') && $business->relationLoaded('locations'))
            ? $business->locations
            : collect();
        $primaryLocation = $locations->firstWhere('is_primary', true) ?? $locations->first();
        $category = ($this->hasTable('business_categories') && $business->relationLoaded('category')) ? $business->category : null;

        $distance = ($lat !== null && $lng !== null && $primaryLocation?->latitude !== null && $primaryLocation?->longitude !== null)
            ? $this->distanceKm($lat, $lng, (float) $primaryLocation->latitude, (float) $primaryLocation->longitude)
            : null;

        $slug = (string) ($this->getBusinessField($business, 'slug', $business->id));

        return [
            'id' => $business->id,
            'name' => $this->getBusinessField($business, 'name', 'Vizit business'),
            'slug' => $slug,
            'business_type' => $this->getBusinessField($business, 'business_type', null),
            'vertical' => $business->normalizedVertical(),
            'category' => $category ? [
                'id' => $category->id,
                'slug' => $category->slug,
                'name' => $category->name_hy ?: $category->name_en ?: $category->slug,
                'name_hy' => $category->name_hy,
                'name_ru' => $category->name_ru,
                'name_en' => $category->name_en,
                'icon' => $category->icon,
            ] : null,
            'custom_category_name' => $this->getBusinessField($business, 'custom_category_name', null),
            'address' => $primaryLocation?->address ?: $this->getBusinessField($business, 'address', null),
            'distance_km' => $distance,
            'locations' => $locations->map(fn ($location) => [
                'id' => $location->id,
                'name' => $location->name,
                'address' => $location->address,
                'city' => $location->city,
                'district' => $location->district,
                'lat' => $location->latitude !== null ? (float) $location->latitude : null,
                'lng' => $location->longitude !== null ? (float) $location->longitude : null,
                'phone' => $location->phone,
                'is_primary' => (bool) $location->is_primary,
            ])->values(),
            'phone' => $this->getBusinessField($business, 'phone', null),
            'timezone' => method_exists($business, 'effectiveTimezone') ? $business->effectiveTimezone() : 'Asia/Yerevan',
            'work_start' => $this->getBusinessField($business, 'work_start', null),
            'work_end' => $this->getBusinessField($business, 'work_end', null),
            'short_description' => $this->getBusinessField($business, 'short_description', null),
            'cover_url' => $this->getBusinessField($business, 'cover_url', null),
            'logo_url' => $this->getBusinessField($business, 'logo_url', null),
            'instagram_url' => $this->getBusinessField($business, 'instagram_url', null),
            'facebook_url' => $this->getBusinessField($business, 'facebook_url', null),
            'website_url' => $this->getBusinessField($business, 'website_url', null),
            'messenger_url' => $this->getBusinessField($business, 'messenger_url', null),
            'whatsapp_url' => $this->getBusinessField($business, 'whatsapp_url', null),
            'services_count' => (int) ($business->services_count ?? 0),
            'staff_count' => (int) ($business->staff_count ?? 0),
            'is_featured' => (bool) (
                ((int) ($business->services_count ?? 0) >= 3) &&
                ((int) ($business->staff_count ?? 0) >= 1)
            ),
            'booking_url' => "/book/{$slug}" . ($primaryLocation ? "?location_id={$primaryLocation->id}" : ''),
        ];
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return round($earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }

}
