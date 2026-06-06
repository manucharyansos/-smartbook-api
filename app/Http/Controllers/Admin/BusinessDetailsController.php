<?php
// app/Http/Controllers/Admin/BusinessDetailsController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Business; // Փոխել Salon-ից Business
use Illuminate\Http\Request;

class BusinessDetailsController extends Controller
{
    public function show(Request $request, Business $business) // Փոխել Salon-ից Business
    {
        $business->load([
            'owner:id,business_id,name,email,phone,is_active,created_at',
            'subscription.plan:id,name,price,interval,seats',
            'locations',
            'primaryLocation',
        ])->loadCount(['users', 'bookings']);

        $subscription = $business->subscription;
        $plan = $subscription?->plan;

        $bookingsAll = Booking::where('business_id', $business->id)->count(); // Փոխել salon_id-ից business_id

        $bookings30 = Booking::where('business_id', $business->id) // Փոխել salon_id-ից business_id
        ->where('starts_at', '>=', now()->subDays(30))
            ->count();

        $revenueAll = Booking::where('business_id', $business->id) // Փոխել salon_id-ից business_id
        ->whereIn('status', ['confirmed', 'done'])
            ->sum('final_price');

        $revenue30 = Booking::where('business_id', $business->id) // Փոխել salon_id-ից business_id
        ->whereIn('status', ['confirmed', 'done'])
            ->where('starts_at', '>=', now()->subDays(30))
            ->sum('final_price');

        $seatsUsed = $business->activeSeatCount();
        $seatsLimit = $business->seatLimit();

        return response()->json([
            'success' => true,
            'data' => [
                'business' => [ // Փոխել salon-ից business
                    'id' => $business->id,
                    'name' => $business->name,
                    'slug' => $business->slug,
                    'business_type' => $business->business_type, // Ավելացնել
                    'vertical' => $business->vertical,
                    'phone' => $business->phone,
                    'address' => $business->address,
                    'primary_location' => $business->primaryLocation ? [
                        'id' => $business->primaryLocation->id,
                        'name' => $business->primaryLocation->name,
                        'address' => $business->primaryLocation->address,
                        'city' => $business->primaryLocation->city,
                        'district' => $business->primaryLocation->district,
                        'latitude' => $business->primaryLocation->latitude !== null ? (float) $business->primaryLocation->latitude : null,
                        'longitude' => $business->primaryLocation->longitude !== null ? (float) $business->primaryLocation->longitude : null,
                        'is_primary' => (bool) $business->primaryLocation->is_primary,
                    ] : null,
                    'locations' => $business->locations->map(fn ($location) => [
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
                    ])->values(),
                    'locations_count' => $business->locations->count(),
                    'map_locations_count' => $business->locations->filter(fn ($location) => $location->latitude !== null && $location->longitude !== null)->count(),
                    'timezone' => $business->timezone,
                    'status' => $business->status,
                    'is_onboarding_completed' => (bool) $business->is_onboarding_completed,
                    'work_start' => $business->work_start,
                    'work_end' => $business->work_end,
                    'slot_step_minutes' => $business->slot_step_minutes,
                    'created_at' => optional($business->created_at)->toDateTimeString(),
                    'updated_at' => optional($business->updated_at)->toDateTimeString(),
                ],

                'owner' => $business->owner ? [
                    'id' => $business->owner->id,
                    'name' => $business->owner->name,
                    'email' => $business->owner->email,
                    'phone' => $business->owner->phone ?? null,
                    'is_active' => (bool) $business->owner->is_active,
                    'created_at' => optional($business->owner->created_at)->toDateTimeString(),
                ] : null,

                'subscription' => $subscription ? [
                    'id' => $subscription->id,
                    'status' => $subscription->status,
                    'trial_ends_at' => optional($subscription->trial_ends_at)->format('Y-m-d'),
                    'current_period_starts_at' => optional($subscription->current_period_starts_at)->format('Y-m-d'),
                    'current_period_ends_at' => optional($subscription->current_period_ends_at)->format('Y-m-d'),
                    'canceled_at' => optional($subscription->canceled_at)->format('Y-m-d'),
                    'provider' => $subscription->provider,
                    'is_active' => $subscription->isActive(),
                ] : null,

                'plan' => $plan ? [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'price' => $plan->price,
                    'interval' => $plan->interval,
                    'seats' => $plan->seats,
                ] : null,

                'seats' => [
                    'used' => $seatsUsed,
                    'limit' => $seatsLimit,
                    'has_available' => $business->hasAvailableSeat(),
                ],

                'stats' => [
                    'users_total' => $business->users_count ?? 0,
                    'bookings_total' => $bookingsAll,
                    'bookings_last_30_days' => $bookings30,
                    'revenue_total' => (float) $revenueAll,
                    'revenue_last_30_days' => (float) $revenue30,
                    'currency' => 'AMD',
                ],
            ]
        ]);
    }
}
