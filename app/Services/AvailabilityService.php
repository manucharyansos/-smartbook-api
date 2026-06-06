<?php
// app/Services/AvailabilityService.php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingBlock;
use App\Models\Business;
use App\Models\Room;
use App\Models\Service;
use App\Models\User;
use App\Support\InteractsWithOptionalLocationColumns;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AvailabilityService
{
    use InteractsWithOptionalLocationColumns;

    /**
     * Backward-compatible single-service helper.
     */
    public function slotsForDay(int $staffId, int $serviceId, string $date, ?int $businessId = null, ?int $locationId = null): array
    {
        return $this->slotsForSelection([$serviceId], $date, $businessId, $staffId, $locationId);
    }

    /**
     * Smart availability for one or many services and either one staff member or the whole team.
     */
    public function slotsForSelection(array $serviceIds, string $date, ?int $businessId = null, ?int $staffId = null, ?int $locationId = null): array
    {
        $serviceIds = array_values(array_unique(array_filter(array_map('intval', $serviceIds), fn ($id) => $id > 0)));
        if (!$serviceIds) {
            return [];
        }

        $serviceQ = Service::query()->whereIn('id', $serviceIds);
        if ($businessId) {
            $serviceQ->where('business_id', $businessId);
        }
        if ($locationId) {
            $this->applyTableLocationCompatibility($serviceQ, $locationId, 'services');
        }

        $services = $serviceQ->get();
        if ($services->count() !== count($serviceIds)) {
            return [];
        }

        $businessId = $businessId ?: (int) $services->first()?->business_id;
        /** @var Business|null $business */
        $business = Business::query()->find($businessId);
        if (!$business) {
            return [];
        }

        $totalDuration = (int) $services->sum(function (Service $service) {
            return (int) ($service->duration_minutes ?? 0);
        });

        if ($totalDuration < 5 || $totalDuration > 600) {
            return [];
        }

        $staffQuery = User::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->whereIn('role', [User::ROLE_STAFF, User::ROLE_MANAGER, User::ROLE_OWNER]);

        if ($staffId) {
            $staffQuery->whereKey($staffId);
        }
        if ($locationId) {
            $this->applyTableLocationCompatibility($staffQuery, $locationId, 'users');
        }

        $staffMembers = $staffQuery->get();
        if ($staffMembers->isEmpty()) {
            return [];
        }

        $slots = [];
        foreach ($staffMembers as $staff) {
            $slots = array_merge($slots, $this->buildSlotsForStaffDuration($business, $staff, $date, $totalDuration));
        }

        if (!$slots) {
            return [];
        }

        if (!$staffId) {
            $slots = $this->dedupeSmartSlotsByTime($slots);
        } else {
            usort($slots, function (array $a, array $b) {
                return strcmp((string) $a['starts_at'], (string) $b['starts_at']);
            });
        }

        return $this->markRecommendedSlots($slots);
    }

    private function buildSlotsForStaffDuration(Business $business, User $staff, string $date, int $duration): array
    {
        try {
            $day = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        } catch (\Throwable $e) {
            return [];
        }

        $step = max(5, min(60, (int) ($business->slot_step_minutes ?? 15)));
        $workStart = $business->work_start ?: '09:00';
        $workEnd = $business->work_end ?: '18:00';
        $tz = $business->effectiveTimezone();

        try {
            $dayStart = Carbon::parse($day->format('Y-m-d') . ' ' . $workStart, $tz)->seconds(0);
            $dayEnd = Carbon::parse($day->format('Y-m-d') . ' ' . $workEnd, $tz)->seconds(0);
        } catch (\Throwable $e) {
            return [];
        }

        if ($dayEnd->lte($dayStart)) {
            $dayEnd = $dayEnd->addDay();
        }

        $lastStart = $dayEnd->copy()->subMinutes($duration);
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

        $intervals = $this->mergeBusyIntervals(
            $busyBookings,
            $busyBlocks,
            $dayStart,
            $dayEnd,
            $tz,
        );

        $slots = [];
        for ($t = $dayStart->copy(); $t->lte($lastStart); $t->addMinutes($step)) {
            $start = $t->copy();
            $end = $t->copy()->addMinutes($duration);

            if ($isToday && $start->lte($now->copy()->addMinutes(5))) {
                continue;
            }

            if ($this->collidesWithIntervals($intervals, $start, $end)) {
                continue;
            }

            [$score, $reason, $gapBefore, $gapAfter] = $this->scoreSlot(
                $intervals,
                $dayStart,
                $dayEnd,
                $start,
                $end,
                $duration,
                $step,
            );

            $slot = [
                'starts_at' => $start->format('Y-m-d H:i:s'),
                'ends_at' => $end->format('Y-m-d H:i:s'),
                'staff_id' => (int) $staff->id,
                'staff_name' => $staff->name,
                'smart_score' => $score,
                'smart_reason' => $reason,
                'gap_before_minutes' => $gapBefore,
                'gap_after_minutes' => $gapAfter,
            ];

            if (method_exists($business, 'isDental') && $business->isDental()) {
                $slot['available_rooms'] = $this->getAvailableRooms($business->id, $start, $end, $busyBookings);
            }

            $slots[] = $slot;
        }

        return $slots;
    }

    private function mergeBusyIntervals(Collection $busyBookings, Collection $busyBlocks, Carbon $dayStart, Carbon $dayEnd, string $tz): array
    {
        $intervals = [];

        foreach ($busyBookings as $booking) {
            $start = $booking->starts_at instanceof Carbon
                ? $booking->starts_at->copy()->setTimezone($tz)
                : Carbon::parse($booking->starts_at, 'UTC')->setTimezone($tz);
            $end = $booking->ends_at instanceof Carbon
                ? $booking->ends_at->copy()->setTimezone($tz)
                : Carbon::parse($booking->ends_at, 'UTC')->setTimezone($tz);

            if ($start->lt($dayEnd) && $end->gt($dayStart)) {
                $intervals[] = [
                    'start' => $start->max($dayStart->copy()),
                    'end' => $end->min($dayEnd->copy()),
                ];
            }
        }

        foreach ($busyBlocks as $block) {
            $start = $block->starts_at instanceof Carbon
                ? $block->starts_at->copy()->setTimezone($tz)
                : Carbon::parse($block->starts_at, 'UTC')->setTimezone($tz);
            $end = $block->ends_at instanceof Carbon
                ? $block->ends_at->copy()->setTimezone($tz)
                : Carbon::parse($block->ends_at, 'UTC')->setTimezone($tz);

            if ($start->lt($dayEnd) && $end->gt($dayStart)) {
                $intervals[] = [
                    'start' => $start->max($dayStart->copy()),
                    'end' => $end->min($dayEnd->copy()),
                ];
            }
        }

        usort($intervals, function (array $a, array $b) {
            return $a['start']->lt($b['start']) ? -1 : 1;
        });

        $merged = [];
        foreach ($intervals as $interval) {
            if (!$merged) {
                $merged[] = $interval;
                continue;
            }

            $lastIndex = count($merged) - 1;
            $last = $merged[$lastIndex];

            if ($interval['start']->lte($last['end'])) {
                if ($interval['end']->gt($last['end'])) {
                    $merged[$lastIndex]['end'] = $interval['end'];
                }
                continue;
            }

            $merged[] = $interval;
        }

        return $merged;
    }

    private function collidesWithIntervals(array $intervals, Carbon $start, Carbon $end): bool
    {
        foreach ($intervals as $interval) {
            if ($interval['start']->lt($end) && $interval['end']->gt($start)) {
                return true;
            }
        }

        return false;
    }

    private function scoreSlot(
        array $intervals,
        Carbon $dayStart,
        Carbon $dayEnd,
        Carbon $start,
        Carbon $end,
        int $duration,
        int $step,
    ): array {
        $prevBoundary = $dayStart->copy();
        $nextBoundary = $dayEnd->copy();

        foreach ($intervals as $interval) {
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

        if ($gapBefore === 0) {
            $score += 18;
        } elseif ($gapBefore <= $step) {
            $score += 8;
        } elseif ($gapBefore < $duration) {
            $score -= 10;
        } else {
            $score -= min(14, (int) floor($gapBefore / max(1, $step * 2)));
        }

        if ($gapAfter === 0) {
            $score += 18;
        } elseif ($gapAfter <= $step) {
            $score += 8;
        } elseif ($gapAfter < $duration) {
            $score -= 10;
        } else {
            $score -= min(14, (int) floor($gapAfter / max(1, $step * 2)));
        }

        if ($gapBefore === 0 && $gapAfter === 0) {
            $score += 16;
            $reason = 'Փակում է ազատ պատուհանը երկու կողմից';
        } elseif ($gapBefore === 0 || $gapAfter === 0) {
            $score += 8;
            $reason = 'Օգնում է օրացույցը պահել կոմպակտ';
        } elseif ($gapBefore < $duration && $gapAfter < $duration) {
            $reason = 'Թողնում է փոքր բացեր, բայց դեռ շահավետ է';
        } elseif ($gapBefore < $duration || $gapAfter < $duration) {
            $reason = 'Մոտ է զբաղված հատվածին';
        } else {
            $reason = 'Սովորական ազատ ժամ';
        }

        if ($start->hour >= 11 && $start->hour <= 17) {
            $score += 3;
        }

        return [max(0, min(100, $score)), $reason, $gapBefore, $gapAfter];
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

        return array_values($grouped);
    }

    private function markRecommendedSlots(array $slots, int $limit = 3): array
    {
        usort($slots, function (array $a, array $b) {
            $scoreA = (int) ($a['smart_score'] ?? 0);
            $scoreB = (int) ($b['smart_score'] ?? 0);
            if ($scoreA === $scoreB) {
                return strcmp((string) $a['starts_at'], (string) $b['starts_at']);
            }
            return $scoreB <=> $scoreA;
        });

        foreach ($slots as $rank => &$slot) {
            $slot['is_recommended'] = $rank < $limit;
            $slot['recommendation_rank'] = $rank < $limit ? $rank + 1 : null;
        }
        unset($slot);

        usort($slots, function (array $a, array $b) {
            return strcmp((string) $a['starts_at'], (string) $b['starts_at']);
        });

        return $slots;
    }

    private function getAvailableRooms(int $businessId, Carbon $start, Carbon $end, $existingBookings): array
    {
        $rooms = Room::where('business_id', $businessId)
            ->where('is_active', true)
            ->get(['id', 'name', 'type']);

        $busyRoomIds = $existingBookings
            ->whereNotNull('room_id')
            ->filter(function ($booking) use ($start, $end) {
                $bs = Carbon::parse($booking->starts_at);
                $be = Carbon::parse($booking->ends_at);
                return $bs->lt($end) && $be->gt($start);
            })
            ->pluck('room_id')
            ->toArray();

        return $rooms->whereNotIn('id', $busyRoomIds)->values()->toArray();
    }
}
