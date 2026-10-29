<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    private const AUDIENCE_TYPES = [
        'services' => ['beauty'],
        'healthcare' => ['dental'],
        'rentals' => ['rentals'],
        'all' => ['beauty', 'dental'],
    ];

    public function index(Request $request)
    {
        $query = Plan::query();

        $showHidden = $request->boolean('show_hidden', false);
        if (!$showHidden) {
            $query->where('is_visible', true);
        }

        $plans = $query->orderBy('sort_order')->orderBy('id')->withCount('subscriptions')->get()
            ->each(fn (Plan $plan) => $plan->setAttribute('audience', $plan->audience()));

        return response()->json(['success' => true, 'data' => $plans]);
    }

    /**
     * Copies a plan for another business area so it can get its own price and limits.
     * With detach_source, a shared plan stops being offered to that area.
     */
    public function copy(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'audience' => ['required', 'in:services,healthcare,rentals'],
            'detach_source' => ['sometimes', 'boolean'],
        ]);
        abort_if($plan->audience() === $data['audience'], 422, 'The plan already belongs to this business area.');

        $suffix = ['services' => 'services', 'healthcare' => 'health', 'rentals' => 'space'][$data['audience']];
        $code = $plan->code.'-'.$suffix;
        for ($n = 2; Plan::where('code', $code)->exists(); $n++) {
            $code = $plan->code.'-'.$suffix.'-'.$n;
        }

        $copy = $plan->replicate(['subscriptions_count', 'audience']);
        $copy->fill([
            'code' => $code,
            'version' => 1,
            'allowed_business_types' => self::AUDIENCE_TYPES[$data['audience']],
            'business_type' => ['services' => 'beauty', 'healthcare' => 'dental'][$data['audience']] ?? null,
            // A copy stays hidden until its price and limits are reviewed.
            'is_visible' => false,
        ]);
        if ($data['audience'] === 'rentals') {
            $copy->fill(['staff_limit' => 0, 'seats' => 0]);
        }
        $copy->save();

        if (($data['detach_source'] ?? false) && $plan->audience() === 'all') {
            $remaining = $data['audience'] === 'healthcare' ? 'services' : 'healthcare';
            $plan->update(['allowed_business_types' => self::AUDIENCE_TYPES[$remaining],
                'business_type' => ['services' => 'beauty', 'healthcare' => 'dental'][$remaining]]);
        }

        $copy->setAttribute('audience', $copy->audience());

        return response()->json(['success' => true, 'data' => $copy], 201);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:plans,code',
            'business_type' => 'nullable|in:salon,clinic,beauty,dental,rentals,services,healthcare',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'monthly_price' => 'nullable|numeric|min:0',
            'yearly_price' => 'nullable|numeric|min:0',
            'price_beauty' => 'nullable|numeric|min:0',
            'price_dental' => 'nullable|numeric|min:0',
            'currency' => 'required|string|max:10',
            'seats' => 'nullable|integer|min:'.($request->input('business_type') === 'rentals' ? '0' : '1'),
            'staff_limit' => 'nullable|integer|min:'.($request->input('business_type') === 'rentals' ? '0' : '1'),
            'duration_days' => 'required|integer|min:1',
            'locations' => 'nullable|integer|min:1',
            'features' => 'nullable|array',
            'features.staff_limit' => 'nullable|integer|min:'.($request->input('business_type') === 'rentals' ? '0' : '1'),
            'features.services_limit' => 'nullable|integer|min:1',
            'features.priority_support' => 'nullable|boolean',
            'features.custom_pricing' => 'nullable|boolean',
            'features.partner_terms' => 'nullable|boolean',
            'services_limit' => 'nullable|integer|min:1',
            'is_active' => 'sometimes|boolean',
            'is_visible' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
        ]);

        $validated = $this->normalizePlanPayload($validated);

        $plan = Plan::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Plan created successfully',
            'data' => $plan
        ], 201);
    }

    public function show(Plan $plan)
    {
        return response()->json(['success' => true, 'data' => $plan]);
    }

    public function update(Request $request, Plan $plan)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => ['sometimes', 'string', Rule::unique('plans', 'code')->ignore($plan->id)],
            'business_type' => 'nullable|in:salon,clinic,beauty,dental,rentals,services,healthcare',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'monthly_price' => 'nullable|numeric|min:0',
            'yearly_price' => 'nullable|numeric|min:0',
            'price_beauty' => 'nullable|numeric|min:0',
            'price_dental' => 'nullable|numeric|min:0',
            'currency' => 'sometimes|string|max:10',
            'seats' => 'nullable|integer|min:' . ($plan->isRentalPlan() || $request->input('business_type') === 'rentals' ? '0' : '1'),
            'staff_limit' => 'nullable|integer|min:' . ($plan->isRentalPlan() || $request->input('business_type') === 'rentals' ? '0' : '1'),
            'duration_days' => 'sometimes|integer|min:1',
            'locations' => 'nullable|integer|min:1',
            'features' => 'nullable|array',
            'features.staff_limit' => 'nullable|integer|min:' . ($plan->isRentalPlan() || $request->input('business_type') === 'rentals' ? '0' : '1'),
            'features.services_limit' => 'nullable|integer|min:' . ($plan->isRentalPlan() || $request->input('business_type') === 'rentals' ? '0' : '1'),
            'features.priority_support' => 'nullable|boolean',
            'features.custom_pricing' => 'nullable|boolean',
            'features.partner_terms' => 'nullable|boolean',
            'services_limit' => 'nullable|integer|min:1',
            'is_active' => 'sometimes|boolean',
            'is_visible' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
        ]);

        $validated = $this->normalizePlanPayload($validated, $plan);
        $validated['version'] = max(1, (int) ($plan->version ?? 1)) + 1;

        $plan->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Plan updated successfully',
            'data' => $plan
        ]);
    }


    private function normalizePlanPayload(array $validated, ?Plan $plan = null): array
    {
        $features = is_array($validated['features'] ?? null)
            ? $validated['features']
            : (is_array($plan?->features) ? $plan->features : []);

        $staffLimit = (int) ($validated['staff_limit']
            ?? ($validated['features']['staff_limit'] ?? null)
            ?? $validated['seats']
            ?? ($plan?->staff_limit ?? ($plan?->features['staff_limit'] ?? $plan?->seats ?? 1)));

        $features['staff_limit'] = $staffLimit;
        $validated['staff_limit'] = $staffLimit;
        $validated['seats'] = $staffLimit;

        $servicesLimit = $validated['services_limit']
            ?? ($validated['features']['services_limit'] ?? null)
            ?? ($plan?->features['services_limit'] ?? null);

        if ($servicesLimit !== null && $servicesLimit !== '') {
            $features['services_limit'] = max(1, (int) $servicesLimit);
        } else {
            unset($features['services_limit']);
        }

        // These delivery products are not currently offered. Keep plan edits
        // from accidentally exposing them before a production provider exists.
        $features['sms_reminders'] = false;
        $features['whatsapp_notifications'] = false;

        // services_limit lives in the versioned feature snapshot, not in a
        // standalone plans table column.
        unset($validated['services_limit']);

        $validated['features'] = $features;

        $validated['locations'] = max(1, (int) (
            $validated['locations']
            ?? $plan?->locations
            ?? 1
        ));

        if (array_key_exists('business_type', $validated)) {
            $audience = match ($validated['business_type']) {
                'beauty', 'salon', 'services' => 'services',
                'dental', 'clinic', 'healthcare' => 'healthcare',
                'rentals' => 'rentals',
                default => $plan?->isRentalPlan() ? 'rentals' : 'all',
            };
            $validated['allowed_business_types'] = self::AUDIENCE_TYPES[$audience];
            // Legacy single-type column kept in step for older readers.
            $validated['business_type'] = ['services' => 'beauty', 'healthcare' => 'dental'][$audience] ?? null;
            if ($audience === 'rentals') {
                $validated['staff_limit'] = 0;
                $validated['seats'] = 0;
                $validated['features']['staff_limit'] = 0;
                unset($validated['features']['services_limit']);
            }
        }

        $monthly = $validated['monthly_price']
            ?? $validated['price']
            ?? $validated['price_beauty']
            ?? $validated['price_dental']
            ?? $plan?->monthly_price
            ?? $plan?->price
            ?? $plan?->price_beauty
            ?? $plan?->price_dental
            ?? 0;

        $monthly = (int) $monthly;
        $validated['monthly_price'] = $monthly;
        $validated['price'] = $monthly;
        $validated['price_beauty'] = $monthly;
        $validated['price_dental'] = $monthly;

        $yearly = $validated['yearly_price'] ?? $plan?->yearly_price;
        $validated['yearly_price'] = $yearly !== null ? (int) $yearly : ($monthly > 0 ? $monthly * 10 : 0);

        return $validated;
    }

    public function destroy(Plan $plan)
    {
        if ($plan->subscriptions()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete plan with active subscriptions. Deactivate it instead.'
            ], 422);
        }

        $plan->delete();

        return response()->json(['success' => true, 'message' => 'Plan deleted successfully']);
    }

    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'plans' => 'required|array',
            'plans.*.id' => 'required|exists:plans,id',
            'plans.*.sort_order' => 'required|integer',
        ]);

        foreach ($validated['plans'] as $item) {
            Plan::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json(['success' => true]);
    }

    public function toggleActive(Plan $plan)
    {
        $plan->update(['is_active' => !$plan->is_active]);

        return response()->json([
            'success' => true,
            'is_active' => $plan->is_active
        ]);
    }

    public function toggleVisible(Plan $plan)
    {
        $plan->update(['is_visible' => !$plan->is_visible]);

        return response()->json([
            'success' => true,
            'is_visible' => $plan->is_visible
        ]);
    }
}
