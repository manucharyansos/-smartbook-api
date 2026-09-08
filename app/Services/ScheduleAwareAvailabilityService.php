<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Carbon;

class ScheduleAwareAvailabilityService extends AvailabilityService
{
    public function slotsForSelection(array $serviceIds, string $date, ?int $businessId = null, ?int $staffId = null, ?int $locationId = null, array $excludeBookingIds = [], int $partySize = 1): array
    {
        $slots = parent::slotsForSelection($serviceIds, $date, $businessId, $staffId, $locationId, $excludeBookingIds, $partySize);
        if (!$slots) return [];

        $businessId = $businessId ?: (int) \App\Models\Service::query()->whereIn('id', $serviceIds)->value('business_id');
        $business = Business::query()->find($businessId);
        if (!$business) return [];

        $resolver = app(ScheduleWindowService::class);
        if (!$resolver->hasStructuredSchedule($business) && !$this->hasStaffScheduleForSlots($business->id, $slots)) {
            return $slots;
        }

        $timezone = $business->effectiveTimezone();
        $staffById = User::query()
            ->where('business_id', $business->id)
            ->whereIn('id', collect($slots)->pluck('staff_id')->filter()->unique()->values())
            ->get()
            ->keyBy('id');

        return array_values(array_filter($slots, function (array $slot) use ($resolver, $business, $timezone, $staffById) {
            $staff = $staffById->get((int) ($slot['staff_id'] ?? 0));
            if (!$staff) return false;
            try {
                $start = Carbon::createFromFormat('Y-m-d H:i:s', (string) ($slot['starts_at'] ?? ''), $timezone);
                $end = Carbon::createFromFormat('Y-m-d H:i:s', (string) ($slot['ends_at'] ?? ''), $timezone);
            } catch (\Throwable) {
                return false;
            }
            return $resolver->contains($business, $start, $end, $staff);
        }));
    }

    private function hasStaffScheduleForSlots(int $businessId, array $slots): bool
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('staff_working_hours')) return false;
        $staffIds = collect($slots)->pluck('staff_id')->filter()->unique()->values();
        if ($staffIds->isEmpty()) return false;
        return \Illuminate\Support\Facades\DB::table('staff_working_hours')
            ->where('business_id', $businessId)
            ->whereIn('user_id', $staffIds)
            ->exists();
    }
}
