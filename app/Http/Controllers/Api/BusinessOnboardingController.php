<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Business; // Ավելացնել Business մոդելը
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class BusinessOnboardingController extends Controller
{
    /**
     * POST /api/business/complete-onboarding
     * Mark business onboarding completed (owner/manager/super_admin only)
     */
    public function complete(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // թույլատրված role-եր
        if (!in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_SUPER_ADMIN], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $business = $user->business; // Փոխել $salon-ից $business

        if (!$business) {
            return response()->json(['message' => 'Business not found'], 404); // Փոխել հաղորդագրությունը
        }

        $updates = [
            'is_onboarding_completed' => true,
            // Legacy visibility flag used by older deployments/endpoints.
            'is_public' => true,
        ];

        if (Schema::hasColumn('businesses', 'is_public_profile_enabled')) {
            $updates['is_public_profile_enabled'] = true;
        }
        if (Schema::hasColumn('businesses', 'is_marketplace_visible')) {
            $updates['is_marketplace_visible'] = true;
        }

        $business->update($updates);

        // Public booking needs at least one active, bookable provider.
        if (Schema::hasColumn('users', 'is_bookable') && Schema::hasColumn('users', 'show_in_public_team')) {
            $hasBookableProvider = $business->users()
                ->where('is_active', true)
                ->where('is_bookable', true)
                ->exists();

            if (! $hasBookableProvider) {
                $business->users()
                    ->where('role', User::ROLE_OWNER)
                    ->where('is_active', true)
                    ->limit(1)
                    ->update([
                        'is_bookable' => true,
                        'show_in_public_team' => true,
                    ]);
            }
        }

        // In a single-location business, unassigned services/providers belong to that location.
        $locations = $business->locations()->where('is_active', true)->get(['id']);
        if ($locations->count() === 1) {
            $locationId = (int) $locations->first()->id;
            if (Schema::hasColumn('services', 'location_id')) {
                $business->services()->whereNull('location_id')->update(['location_id' => $locationId]);
            }
            if (Schema::hasColumn('users', 'location_id')) {
                $business->users()->whereNull('location_id')->update(['location_id' => $locationId]);
            }
        }

        return response()->json([
            'ok' => true,
            'business_id' => $business->id, // Փոխել salon_id-ից business_id
            'business_name' => $business->name,
            'business_type' => $business->business_type,
            'is_onboarding_completed' => true,
        ]);
    }

    /**
     * GET /api/business/onboarding-status
     * Check onboarding status
     */
    public function status(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $business = $user->business;

        if (!$business) {
            return response()->json(['message' => 'Business not found'], 404);
        }

        return response()->json([
            'data' => [
                'business_id' => $business->id,
                'business_name' => $business->name,
                'business_type' => $business->business_type,
                'is_onboarding_completed' => $business->is_onboarding_completed,
                'onboarding_step' => $this->getOnboardingStep($business),
            ]
        ]);
    }

    private function getOnboardingStep(Business $business): string
    {
        if ($business->is_onboarding_completed) {
            return 'completed';
        }

        // Ստուգել քայլերը
        if (!$business->services()->exists()) {
            return 'services'; // Ավելացնել ծառայություններ
        }

        if (!$business->staffSchedules()->exists()) {
            return 'schedule'; // Կարգավորել գրաֆիկը
        }

        return 'settings'; // Վերջին քայլ
    }
}
