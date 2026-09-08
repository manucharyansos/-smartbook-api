<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BusinessOnboardingController extends Controller
{
    public function complete(Request $request)
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthenticated.'], 401);
        if (!in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_SUPER_ADMIN], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $business = $user->business;
        if (!$business) return response()->json(['message' => 'Business not found'], 404);

        $updates = ['is_onboarding_completed' => true, 'is_public' => true];
        if (Schema::hasColumn('businesses', 'is_public_profile_enabled')) $updates['is_public_profile_enabled'] = true;
        if (Schema::hasColumn('businesses', 'is_marketplace_visible')) $updates['is_marketplace_visible'] = true;
        $business->update($updates);

        if (Schema::hasColumn('users', 'is_bookable') && Schema::hasColumn('users', 'show_in_public_team')) {
            $hasBookableProvider = $business->users()->where('is_active', true)->where('is_bookable', true)->exists();
            if (!$hasBookableProvider) {
                $business->users()->where('role', User::ROLE_OWNER)->where('is_active', true)->limit(1)->update([
                    'is_bookable' => true,
                    'show_in_public_team' => true,
                ]);
            }
        }

        $locations = $business->locations()->where('is_active', true)->get(['id']);
        if ($locations->count() === 1) {
            $locationId = (int) $locations->first()->id;
            if (Schema::hasColumn('services', 'location_id')) $business->services()->whereNull('location_id')->update(['location_id' => $locationId]);
            if (Schema::hasColumn('users', 'location_id')) $business->users()->whereNull('location_id')->update(['location_id' => $locationId]);
        }

        return response()->json([
            'ok' => true,
            'business_id' => $business->id,
            'business_name' => $business->name,
            'business_type' => $business->business_type,
            'is_onboarding_completed' => true,
        ]);
    }

    public function status(Request $request)
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthenticated.'], 401);

        $business = $user->business;
        if (!$business) return response()->json(['message' => 'Business not found'], 404);

        return response()->json(['data' => [
            'business_id' => $business->id,
            'business_name' => $business->name,
            'business_type' => $business->business_type,
            'is_onboarding_completed' => $business->is_onboarding_completed,
            'onboarding_step' => $this->getOnboardingStep($business),
        ]]);
    }

    private function getOnboardingStep(Business $business): string
    {
        if ($business->is_onboarding_completed) return 'completed';
        if (!$business->services()->exists()) return 'services';
        if (!$this->hasConfiguredSchedule($business)) return 'schedule';
        return 'settings';
    }

    private function hasConfiguredSchedule(Business $business): bool
    {
        // Current schedule endpoints persist here. This is the canonical check.
        if (Schema::hasTable('business_working_hours')
            && DB::table('business_working_hours')->where('business_id', $business->id)->exists()) {
            return true;
        }

        if (Schema::hasTable('staff_working_hours')
            && DB::table('staff_working_hours')->where('business_id', $business->id)->exists()) {
            return true;
        }

        // Backward compatibility for deployments that still have legacy schedules.
        return $business->staffSchedules()->exists();
    }
}
