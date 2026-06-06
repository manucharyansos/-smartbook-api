<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    public function index(Request $request)
    {
        $query = Plan::query();

        $showHidden = $request->boolean('show_hidden', false);
        if (!$showHidden) {
            $query->where('is_visible', true);
        }

        $plans = $query->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['success' => true, 'data' => $plans]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:plans,code',
            'business_type' => 'nullable|in:salon,clinic,beauty,dental',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'monthly_price' => 'nullable|numeric|min:0',
            'yearly_price' => 'nullable|numeric|min:0',
            'price_beauty' => 'nullable|numeric|min:0',
            'price_dental' => 'nullable|numeric|min:0',
            'currency' => 'required|string|max:10',
            'seats' => 'nullable|integer|min:1',
            'staff_limit' => 'nullable|integer|min:1',
            'duration_days' => 'required|integer|min:1',
            'locations' => 'nullable|integer|min:1',
            'features' => 'nullable|array',
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
            'business_type' => 'nullable|in:salon,clinic,beauty,dental',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'monthly_price' => 'nullable|numeric|min:0',
            'yearly_price' => 'nullable|numeric|min:0',
            'price_beauty' => 'nullable|numeric|min:0',
            'price_dental' => 'nullable|numeric|min:0',
            'currency' => 'sometimes|string|max:10',
            'seats' => 'nullable|integer|min:1',
            'staff_limit' => 'nullable|integer|min:1',
            'duration_days' => 'sometimes|integer|min:1',
            'locations' => 'nullable|integer|min:1',
            'features' => 'nullable|array',
            'services_limit' => 'nullable|integer|min:1',
            'is_active' => 'sometimes|boolean',
            'is_visible' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
        ]);

        $validated = $this->normalizePlanPayload($validated, $plan);

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
            $validated['services_limit'] = $features['services_limit'];
        } else {
            unset($features['services_limit']);
            $validated['services_limit'] = null;
        }

        $validated['features'] = $features;

        $validated['locations'] = max(1, (int) (
            $validated['locations']
            ?? $plan?->locations
            ?? 1
        ));

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
