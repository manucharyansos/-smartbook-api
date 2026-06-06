<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'business_type' => ['nullable', 'string', 'in:beauty,dental,salon,clinic,services,healthcare'],
        ]);

        $businessType = $data['business_type'] ?? null;

        $plans = Plan::query()
            ->where('is_active', true)
            ->where('is_visible', true)
            ->where('code', '!=', 'custom')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (Plan $plan) => !$businessType || $plan->supportsBusinessType((string) $businessType))
            ->values()
            ->map(function (Plan $plan) {
                $monthlyPrice = $plan->monthlyPrice();
                $yearlyPrice = $plan->yearlyPrice();

                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'code' => $plan->code,
                    'version' => (int)($plan->version ?? 1),
                    'description' => $plan->description,
                    'price' => $monthlyPrice,
                    'monthly_price' => $monthlyPrice,
                    'currency' => $plan->currency,
                    'staff_limit' => $plan->staffLimit(),
                    'services_limit' => $plan->features['services_limit'] ?? null,
                    'duration_days' => $plan->duration_days,
                    'locations' => $plan->locations,
                    'features' => $plan->getFeaturesList(),
                    'period' => 'ամիս',
                    'pricing_model' => [
                        'staff_based' => true,
                        'owners_unlimited' => true,
                        'managers_unlimited' => true,
                    ],
                    'yearly_offer' => [
                        'enabled' => $yearlyPrice > 0,
                        'price' => $yearlyPrice,
                        'months_charged' => 10,
                        'months_free' => 2,
                        'discount_amount' => max(($monthlyPrice * 12) - $yearlyPrice, 0),
                    ],
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $plans,
        ]);
    }
}
