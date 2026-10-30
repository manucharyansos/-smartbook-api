<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminLog;
use App\Models\Business;
use App\Models\BusinessPricingOverride;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\BusinessPricingResolver;
use App\Support\BusinessVertical;
use Illuminate\Http\Request;

class BusinessManagementController extends Controller
{
    public function __construct(private BusinessPricingResolver $pricingResolver)
    {
    }

    public function index(Request $request)
    {
        $query = Business::with(['primaryLocation', 'category', 'subscription.plan', 'owner'])
            ->withCount(['users', 'bookings', 'locations'])
            ->withSum('bookings as total_revenue', 'final_price');

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('slug', 'like', '%' . $search . '%');
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('business_type')) {
            $vertical = BusinessVertical::normalize((string) $request->business_type);
            $legacyTypes = match ($vertical) {
                BusinessVertical::HEALTHCARE => ['healthcare', 'dental', 'clinic'],
                BusinessVertical::RENTALS => ['rentals'],
                default => ['services', 'beauty', 'salon'],
            };
            $query->where(function ($builder) use ($vertical, $legacyTypes) {
                $builder->where('vertical', $vertical)
                    ->orWhere(function ($legacyQuery) use ($legacyTypes) {
                        $legacyQuery->where(function ($emptyVertical) {
                            $emptyVertical->whereNull('vertical')->orWhere('vertical', '');
                        })->whereIn('business_type', $legacyTypes);
                    });
            });
        }

        // Newest first, so a business that just signed up is on the first page.
        $businesses = $query->latest('id')->paginate(max(1, min(100, $request->integer('per_page', 20))));

        $businesses->getCollection()->transform(function (Business $business) {
            $sub = $business->subscription;
            $owner = $business->owner;

            $business->setAttribute('owner_summary', $owner ? [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'phone' => $owner->phone,
            ] : null);
            $business->setAttribute('subscription_summary', $sub ? [
                'status' => $sub->computedStatus(),
                'billing_cycle' => $sub->billing_cycle,
                'trial_ends_at' => optional($sub->trial_ends_at)->toISOString(),
                'current_period_ends_at' => optional($sub->current_period_ends_at)->toISOString(),
                'plan' => $sub->plan ? ['id' => $sub->plan->id, 'code' => $sub->plan->code, 'name' => $sub->plan->name] : null,
            ] : null);
            $business->unsetRelation('subscription');
            $business->unsetRelation('owner');

            return $business;
        });

        return response()->json([
            'success' => true,
            'data' => $businesses,
        ]);
    }

    public function show(Business $business, Request $request)
    {
        $business->load([
            'subscription.plan',
            'locations',
            'primaryLocation',
            'category',
            'users' => fn($q) => $q->select('id', 'business_id', 'name', 'email', 'role', 'is_active', 'created_at'),
            'pricingOverrides.plan',
            'pricingOverrides.creator',
        ]);

        $usersTotal = $business->users->count();
        $usersActive = $business->users->where('is_active', true)->count();
        $staffActive = $business->users->where('is_active', true)->where('role', User::ROLE_STAFF)->count();

        $bookingsTotal = $business->bookings()->count();
        $bookingsConfirmedDone = $business->bookings()
            ->whereIn('status', ['confirmed', 'done'])
            ->count();

        $revenueAllTime = (float) $business->bookings()
            ->whereIn('status', ['confirmed', 'done'])
            ->sum('final_price');

        $sub = $business->subscription;
        $plan = $sub?->plan;
        $resolved = $plan ? $this->pricingResolver->resolve($business, $plan, (string) ($sub->billing_cycle ?? 'monthly')) : null;

        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Plan $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'description' => $item->description,
                'monthly_price' => $item->monthlyPrice(),
                'yearly_price' => $item->yearlyPrice(),
                'currency' => $item->currency,
                'staff_limit' => $item->staffLimit(),
                'locations' => $item->locations,
            ])
            ->values();

        $pricingOverrides = $business->pricingOverrides
            ->sortByDesc('id')
            ->map(function (BusinessPricingOverride $override) use ($business) {
                $preview = $override->plan ? $this->pricingResolver->previewOverride($business, $override) : null;

                return [
                    'id' => $override->id,
                    'plan_id' => $override->plan_id,
                    'plan' => $override->plan ? [
                        'id' => $override->plan->id,
                        'code' => $override->plan->code,
                        'name' => $override->plan->name,
                    ] : null,
                    'title' => 'Անհատական առաջարկ',
                    'custom_monthly_price' => $override->custom_monthly_price,
                    'custom_yearly_price' => $override->custom_yearly_price,
                    'effective_monthly_price' => $preview['monthly'] ?? $override->custom_monthly_price,
                    'effective_yearly_price' => $preview['yearly'] ?? $override->custom_yearly_price,
                    'discount_type' => $override->discount_type,
                    'discount_value' => $override->discount_value,
                    'billing_cycles_limit' => $override->billing_cycles_limit,
                    'used_billing_cycles' => $override->usedBillingCycles(),
                    'remaining_billing_cycles' => $override->remainingBillingCycles(),
                    'starts_at' => optional($override->starts_at)->toISOString(),
                    'ends_at' => optional($override->ends_at)->toISOString(),
                    'note' => $override->note,
                    'is_active' => $override->is_active,
                    'is_currently_active' => $override->isCurrentlyActive(),
                    'state' => $override->state(),
                    'created_at' => optional($override->created_at)->toISOString(),
                    'creator' => $override->creator ? [
                        'id' => $override->creator->id,
                        'name' => $override->creator->name,
                    ] : null,
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'business' => [
                    'id' => $business->id,
                    'name' => $business->name,
                    'slug' => $business->slug,
                    'business_type' => $business->business_type,
                    'vertical' => $business->vertical,
                    'category' => $business->category ? [
                        'id' => $business->category->id,
                        'slug' => $business->category->slug,
                        'name' => $business->category->name_hy ?? $business->category->name_en ?? $business->category->slug,
                        'vertical' => $business->category->vertical,
                    ] : null,
                    'custom_category_name' => $business->custom_category_name,
                    'phone' => $business->phone,
                    'address' => $business->address,
                    'primary_location' => $this->serializePrimaryLocation($business),
                    'locations' => $business->locations->map(fn ($location) => $this->serializeLocation($location))->values(),
                    'locations_count' => $business->locations->count(),
                    'map_locations_count' => $business->locations->filter(fn ($location) => $location->latitude !== null && $location->longitude !== null)->count(),
                    'status' => $business->status,
                    'is_onboarding_completed' => (bool) $business->is_onboarding_completed,
                    'work_start' => $business->work_start,
                    'work_end' => $business->work_end,
                    'slot_step_minutes' => $business->slot_step_minutes,
                    'timezone' => $business->timezone,
                    'billing_status' => $business->billing_status,
                    'is_public_profile_enabled' => (bool) $business->is_public_profile_enabled,
                    'is_marketplace_visible' => (bool) $business->is_marketplace_visible,
                    'created_at' => $business->created_at?->toISOString(),
                ],
                'subscription' => $sub ? [
                    'status' => $sub->status,
                    'billing_cycle' => $sub->billing_cycle ?? 'monthly',
                    'trial_ends_at' => optional($sub->trial_ends_at)->toISOString(),
                    'current_period_starts_at' => optional($sub->current_period_starts_at)->toISOString(),
                    'current_period_ends_at' => optional($sub->current_period_ends_at)->toISOString(),
                    'is_active' => $sub->isActive(),
                    'plan' => $plan ? [
                        'id' => $plan->id,
                        'code' => $plan->code,
                        'name' => $plan->name,
                        'monthly_price' => $plan->monthlyPrice(),
                        'yearly_price' => $plan->yearlyPrice(),
                        'currency' => $plan->currency,
                        'staff_limit' => $plan->staffLimit(),
                        'locations' => $plan->locations,
                    ] : null,
                    'pricing' => $resolved ? [
                        'effective_monthly_price' => $resolved['effective_monthly_price'],
                        'effective_yearly_price' => $resolved['effective_yearly_price'],
                        'discount_amount' => $resolved['discount_amount'],
                        'has_override' => $resolved['has_override'],
                    ] : null,
                ] : null,
                'seats' => [
                    'active' => $business->activeSeatCount(),
                    'limit' => $business->seatLimit(),
                    'has_available' => $business->hasAvailableSeat(),
                    'owners_unlimited' => true,
                    'managers_unlimited' => true,
                ],
                'stats' => [
                    'users_total' => $usersTotal,
                    'users_active' => $usersActive,
                    'staff_active' => $staffActive,
                    'bookings_total' => $bookingsTotal,
                    'bookings_confirmed_done' => $bookingsConfirmedDone,
                    'revenue_all_time' => $revenueAllTime,
                    'locations_total' => $business->locations->count(),
                    'locations_with_coordinates' => $business->locations->filter(fn ($location) => $location->latitude !== null && $location->longitude !== null)->count(),
                    'currency' => 'AMD',
                ],
                'available_plans' => $plans,
                'pricing_overrides' => $pricingOverrides,
                'team' => $business->users->sortBy(fn ($user) => [$user->role !== User::ROLE_OWNER, $user->id])->values()
                    ->map(fn ($user) => $user->only(['id', 'name', 'email', 'role', 'is_active', 'created_at'])),
                'billing' => $this->billingSnapshot($business),
                'rental' => $business->isRental() ? $this->rentalSnapshot($business) : null,
            ],
        ]);
    }

    /** What the business has paid Vizit, and its latest invoices with their payment attempts. */
    private function billingSnapshot(Business $business): array
    {
        $invoices = \App\Models\Invoice::query()->withoutGlobalScopes()->where('business_id', $business->id);

        return [
            'paid_total' => (int) (clone $invoices)->where('status', 'approved')->sum('amount'),
            'pending_total' => (int) (clone $invoices)->where('status', 'pending')->sum('amount'),
            'billing_status' => $business->billing_status,
            'invoices' => (clone $invoices)->with(['plan:id,name,code', 'paymentTransactions' => fn ($q) => $q->latest('id')])
                ->orderByDesc('id')->limit(10)->get()->map(fn ($invoice) => [
                    'id' => $invoice->id, 'status' => $invoice->status, 'amount' => (int) $invoice->amount,
                    'billing_cycle' => $invoice->billing_cycle ?: 'monthly', 'payment_method' => $invoice->payment_method,
                    'plan' => $invoice->plan?->name, 'created_at' => optional($invoice->created_at)->toISOString(),
                    'paid_at' => optional($invoice->paid_at)->toISOString(),
                    'transaction' => ($tx = $invoice->paymentTransactions->first()) ? [
                        'reference' => $tx->provider_transaction_id, 'status' => $tx->status, 'provider' => $tx->provider,
                        'requires_manual_review' => $tx->requires_manual_review,
                    ] : null,
                ])->values(),
        ];
    }

    private function rentalSnapshot(Business $business): array
    {
        $rentals = app(\App\Services\RentalBookingService::class);
        $active = $business->bookings()->whereNotIn('status', ['cancelled', 'no_show']);

        return [
            'hourly_price' => $business->rental_hourly_price,
            'min_minutes' => $business->rental_min_minutes,
            'max_minutes' => $business->rental_max_minutes,
            'buffer_minutes' => $business->rental_buffer_minutes,
            'allow_overnight' => (bool) $business->rental_allow_overnight,
            'duration_discounts' => $rentals->durationDiscounts($business),
            'gallery_count' => $business->gallery()->count(),
            'bookings_total' => (clone $active)->count(),
            'bookings_upcoming' => (clone $active)->where('starts_at', '>', now())->count(),
            'booked_amount' => (int) (clone $active)->sum('final_price'),
            'last_booking_at' => optional((clone $active)->latest('starts_at')->first(['starts_at'])?->starts_at)->toISOString(),
        ];
    }


    private function serializeLocation($location): array
    {
        return [
            'id' => $location->id,
            'name' => $location->name,
            'address' => $location->address,
            'city' => $location->city,
            'district' => $location->district,
            'latitude' => $location->latitude !== null ? (float) $location->latitude : null,
            'longitude' => $location->longitude !== null ? (float) $location->longitude : null,
            'lat' => $location->latitude !== null ? (float) $location->latitude : null,
            'lng' => $location->longitude !== null ? (float) $location->longitude : null,
            'phone' => $location->phone,
            'is_primary' => (bool) $location->is_primary,
            'is_active' => (bool) $location->is_active,
            'sort_order' => (int) $location->sort_order,
            'has_coordinates' => $location->latitude !== null && $location->longitude !== null,
        ];
    }

    private function serializePrimaryLocation(Business $business): ?array
    {
        $location = $business->primaryLocation ?: $business->locations->first();
        return $location ? $this->serializeLocation($location) : null;
    }

    public function purgePreview(Business $business, \App\Services\BusinessPurgeService $purger)
    {
        return response()->json(['data' => $purger->preview($business)]);
    }

    /** Permanent deletion of a test business. The admin must retype the slug. */
    public function purge(Business $business, Request $request, \App\Services\BusinessPurgeService $purger)
    {
        $request->validate(['confirm_slug' => ['required', 'string', 'in:'.$business->slug]], [
            'confirm_slug.in' => 'Հաստատման համար ճիշտ գրիր բիզնեսի slug-ը։',
        ]);
        $summary = $purger->purge($business);
        AdminLog::create([
            'admin_id' => $request->user()->id, 'action' => 'purge_business', 'model_type' => Business::class,
            'model_id' => $summary['business']['id'], 'old_values' => $summary, 'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        return response()->json(['data' => $summary]);
    }

    public function suspend(Business $business, Request $request)
    {
        $business->update([
            'status' => 'suspended',
            'suspended_at' => now(),
        ]);

        AdminLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'suspend_business',
            'model_type' => Business::class,
            'model_id' => $business->id,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Business suspended']);
    }

    public function restore(Business $business, Request $request)
    {
        $business->update([
            'status' => 'active',
            'suspended_at' => null,
        ]);

        AdminLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'restore_business',
            'model_type' => Business::class,
            'model_id' => $business->id,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Business restored']);
    }

    public function updatePlan(Request $request, Business $business)
    {
        $request->validate([
            'plan_code' => 'required|string|exists:plans,code',
        ]);

        $plan = Plan::where('code', $request->plan_code)->firstOrFail();

        $subscription = $business->subscription;
        if (!$subscription) {
            return response()->json(['message' => 'Business has no subscription'], 404);
        }

        $subscription->applyPlanSnapshot($plan);
        $subscription->status = $subscription->status ?: 'active';
        $subscription->billing_cycle = $subscription->billing_cycle ?: 'monthly';
        $subscription->save();

        AdminLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'update_business_plan',
            'model_type' => Business::class,
            'model_id' => $business->id,
            'new_values' => ['plan_code' => $plan->code],
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Plan updated successfully']);
    }

    public function extendTrial(Request $request, Business $business)
    {
        $request->validate([
            'days' => 'required|integer|min:1|max:90',
        ]);

        $subscription = $business->subscription;
        if (!$subscription) {
            return response()->json(['message' => 'Business has no subscription'], 404);
        }

        $newTrialEnd = $subscription->trial_ends_at
            ? $subscription->trial_ends_at->copy()->addDays((int) $request->days)
            : now()->addDays((int) $request->days);

        $subscription->update([
            'trial_ends_at' => $newTrialEnd,
            'status' => 'trialing',
        ]);

        AdminLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'extend_trial',
            'model_type' => Business::class,
            'model_id' => $business->id,
            'new_values' => ['trial_days_added' => $request->days],
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Trial extended successfully']);
    }

    public function storePricingOverride(Request $request, Business $business)
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'custom_monthly_price' => ['nullable', 'integer', 'min:0'],
            'custom_yearly_price' => ['nullable', 'integer', 'min:0'],
            'discount_type' => ['nullable', 'in:percent,fixed,extra_trial_days'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'billing_cycles_limit' => ['nullable', 'integer', 'min:1', 'max:24'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'note' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data = $this->normalizeOverrideDates($data);
        $this->ensureOverrideHasPrice(Plan::findOrFail($data['plan_id']), $data);

        $override = BusinessPricingOverride::create([
            'business_id' => $business->id,
            'plan_id' => $data['plan_id'],
            'custom_monthly_price' => $data['custom_monthly_price'] ?? null,
            'custom_yearly_price' => $data['custom_yearly_price'] ?? null,
            'discount_type' => $data['discount_type'] ?? null,
            'discount_value' => $data['discount_value'] ?? null,
            'billing_cycles_limit' => $data['billing_cycles_limit'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'note' => $data['note'] ?? null,
            'created_by_admin_id' => $request->user()->id,
            'is_active' => $data['is_active'] ?? true,
        ]);

        AdminLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'create_business_pricing_override',
            'model_type' => BusinessPricingOverride::class,
            'model_id' => $override->id,
            'new_values' => $override->toArray(),
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Pricing override created successfully',
            'data' => $override->load(['plan', 'creator']),
        ], 201);
    }

    public function updatePricingOverride(Request $request, Business $business, BusinessPricingOverride $override)
    {
        abort_unless((int) $override->business_id === (int) $business->id, 404);

        $data = $request->validate([
            'plan_id' => ['sometimes', 'integer', 'exists:plans,id'],
            'custom_monthly_price' => ['nullable', 'integer', 'min:0'],
            'custom_yearly_price' => ['nullable', 'integer', 'min:0'],
            'discount_type' => ['nullable', 'in:percent,fixed,extra_trial_days'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'billing_cycles_limit' => ['nullable', 'integer', 'min:1', 'max:24'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'note' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data = $this->normalizeOverrideDates($data);
        $merged = array_merge($override->only([
            'plan_id', 'custom_monthly_price', 'custom_yearly_price', 'discount_type', 'discount_value',
        ]), $data);
        $this->ensureOverrideHasPrice(Plan::findOrFail($merged['plan_id']), $merged);

        $override->update($data);

        AdminLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'update_business_pricing_override',
            'model_type' => BusinessPricingOverride::class,
            'model_id' => $override->id,
            'new_values' => $data,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Pricing override updated successfully',
            'data' => $override->fresh()->load(['plan', 'creator']),
        ]);
    }

    public function destroyPricingOverride(Request $request, Business $business, BusinessPricingOverride $override)
    {
        abort_unless((int) $override->business_id === (int) $business->id, 404);

        $override->delete();

        AdminLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'delete_business_pricing_override',
            'model_type' => BusinessPricingOverride::class,
            'model_id' => $override->id,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Pricing override deleted successfully']);
    }

    /**
     * A date-only end means "through that whole day", not its first second.
     */
    private function normalizeOverrideDates(array $data): array
    {
        if (!empty($data['ends_at']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['ends_at'])) {
            $data['ends_at'] = \Illuminate\Support\Carbon::parse($data['ends_at'])->endOfDay();
        }

        return $data;
    }

    /**
     * Refuse an offer that would bill 0 ֏ by mistake, e.g. the Custom plan (base price 0)
     * saved without its own monthly price. Free time is given with extra trial days instead.
     */
    private function ensureOverrideHasPrice(Plan $plan, array $data): void
    {
        if (($data['discount_type'] ?? null) === 'extra_trial_days') {
            return;
        }

        $monthly = isset($data['custom_monthly_price']) && $data['custom_monthly_price'] !== null
            ? (int) $data['custom_monthly_price']
            : $plan->monthlyPrice();

        if (($data['discount_type'] ?? null) === 'percent') {
            $monthly = (int) round($monthly * (1 - min(100, (float) ($data['discount_value'] ?? 0)) / 100));
        } elseif (($data['discount_type'] ?? null) === 'fixed') {
            $monthly -= (int) ($data['discount_value'] ?? 0);
        }

        if ($monthly <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'custom_monthly_price' => $plan->monthlyPrice() <= 0
                    ? 'Այս պլանի բազային գինը 0 է։ Նշեք ամսական գինը։'
                    : 'Առաջարկի ամսական գինը 0 ֏ է ստացվում։ Ստուգեք գինը կամ զեղչը։',
            ]);
        }
    }
}
