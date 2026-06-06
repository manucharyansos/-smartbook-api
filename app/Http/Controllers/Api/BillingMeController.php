<?php
// app/Http/Controllers/Api/BillingMeController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\Billing\BusinessPricingResolver;
use Illuminate\Http\Request;

class BillingMeController extends Controller
{
    public function __construct(private BusinessPricingResolver $pricingResolver)
    {
    }

    public function show(Request $request)
    {
        $user = $request->user();

        $business = Business::query()
            ->with(['subscription.plan'])
            ->findOrFail($user->business_id);

        $sub = $business->subscription;

        $features = [];
        if ($sub && method_exists($sub, 'features')) {
            $features = $sub->features();
        } elseif ($sub?->plan) {
            $features = (array) ($sub->plan->features ?? []);
        }

        $isActive = $sub && $sub->isActive();
        $isSuspended = $business->billing_status === 'suspended';

        $reason = null;
        if ($isSuspended) $reason = 'suspended';
        elseif (!$sub) $reason = 'no_subscription';
        elseif (!$isActive) $reason = 'subscription_inactive';

        $nextAction = null;
        if ($reason === 'subscription_inactive' || $reason === 'no_subscription') {
            $nextAction = 'create_invoice';
        }
        if ($reason === 'suspended') {
            $nextAction = 'contact_support';
        }

        $pricing = null;
        if ($sub?->plan) {
            $resolved = $this->pricingResolver->resolve($business, $sub->plan, (string) ($sub->billing_cycle ?? 'monthly'));
            $override = $resolved['override'];

            $pricing = [
                'currency' => $resolved['currency'],
                'base_monthly_price' => $resolved['base_monthly_price'],
                'base_yearly_price' => $resolved['base_yearly_price'],
                'effective_monthly_price' => $resolved['effective_monthly_price'],
                'effective_yearly_price' => $resolved['effective_yearly_price'],
                'discount_amount' => $resolved['discount_amount'],
                'has_override' => $resolved['has_override'],
                'override' => $override ? [
                    'id' => $override->id,
                    'custom_monthly_price' => $override->custom_monthly_price,
                    'custom_yearly_price' => $override->custom_yearly_price,
                    'discount_type' => $override->discount_type,
                    'discount_value' => $override->discount_value,
                    'billing_cycles_limit' => $override->billing_cycles_limit,
                    'starts_at' => optional($override->starts_at)->toISOString(),
                    'ends_at' => optional($override->ends_at)->toISOString(),
                    'note' => $override->note,
                ] : null,
            ];
        }

        $individualOffers = $this->pricingResolver
            ->activeOverridesForBusiness($business)
            ->filter(fn ($override) => $override->plan)
            ->map(function ($override) use ($business) {
                $resolved = $this->pricingResolver->resolve($business, $override->plan, 'monthly');

                return [
                    'id' => $override->id,
                    'title' => 'Անհատական առաջարկ',
                    'base_plan' => [
                        'id' => $override->plan->id,
                        'code' => $override->plan->code,
                        'name' => $override->plan->name,
                        'staff_limit' => $override->plan->staffLimit(),
                        'services_limit' => $override->plan->features['services_limit'] ?? null,
                        'locations' => $override->plan->locations,
                        'currency' => $override->plan->currency,
                    ],
                    'effective_monthly_price' => $resolved['effective_monthly_price'],
                    'effective_yearly_price' => $resolved['effective_yearly_price'],
                    'discount_amount' => $resolved['discount_amount'],
                    'discount_type' => $resolved['discount_type'],
                    'discount_value' => $resolved['discount_value'],
                    'billing_cycles_limit' => $override->billing_cycles_limit,
                    'starts_at' => optional($override->starts_at)->toISOString(),
                    'ends_at' => optional($override->ends_at)->toISOString(),
                    'note' => $override->note,
                ];
            })
            ->values();

        return response()->json([
            'data' => [
                'is_billable' => (!$isSuspended) && $isActive,
                'reason' => $reason,
                'next_action' => $nextAction,
                'business' => [
                    'id' => $business->id,
                    'name' => $business->name,
                    'slug' => $business->slug,
                    'business_type' => $business->business_type,
                    'billing_status' => $business->billing_status,
                    'suspended_at' => $business->suspended_at,
                ],
                'seats' => [
                    'active_staff' => $business->activeSeatCount(),
                    'staff_limit' => $business->seatLimit(),
                    'owners_unlimited' => true,
                    'managers_unlimited' => true,
                ],
                'usage' => [
                    'active_staff' => $business->activeSeatCount(),
                    'staff_limit' => $business->seatLimit(),
                    'services_count' => $business->activeServiceCount(),
                    'services_limit' => $business->serviceLimit(),
                    'locations_count' => $business->locationCount(),
                    'locations_limit' => $business->locationLimit(),
                ],
                'payment_provider' => [
                    'default' => config('billing.providers.default', 'idbank_mock'),
                    'mode' => config('billing.providers.default', 'idbank_mock') === 'idbank' ? 'live' : 'mock',
                    'live_ready' => config('billing.providers.default', 'idbank_mock') === 'idbank',
                ],
                'pricing' => $pricing,
                'individual_offers' => $individualOffers,
                'subscription' => $sub ? [
                    'status' => $sub->status,
                    'trial_ends_at' => $sub->trial_ends_at,
                    'current_period_ends_at' => $sub->current_period_ends_at,
                    'billing_cycle' => $sub->billing_cycle ?? 'monthly',
                    'plan' => $sub->plan ? [
                        'code' => $sub->plan->code,
                        'name' => $sub->plan->name,
                        'price' => $sub->plan->monthlyPrice(),
                        'monthly_price' => $sub->plan->monthlyPrice(),
                        'yearly_price' => $sub->plan->yearlyPrice(),
                        'currency' => $sub->plan->currency,
                        'staff_limit' => $sub->plan->staffLimit(),
                        'services_limit' => $features['services_limit'] ?? ($sub->plan->features['services_limit'] ?? null),
                        'locations' => $sub->plan->locations,
                        'duration_days' => $sub->plan->duration_days,
                        'features' => $features,
                    ] : null,
                ] : null,
            ]
        ]);
    }
}
