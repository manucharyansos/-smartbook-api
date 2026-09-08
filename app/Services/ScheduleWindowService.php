<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScheduleWindowService
{
    /** @return array<int, array{start: Carbon, end: Carbon}> */
    public function intervalsFor(Business $business, string|Carbon $date, ?User $staff = null): array
    {
        $timezone = $business->effectiveTimezone();
        try {
            $day = $date instanceof Carbon
                ? $date->copy()->timezone($timezone)->startOfDay()
                : Carbon::createFromFormat('Y-m-d', $date, $timezone)->startOfDay();
        } catch (\Throwable) {
            return [];
        }

        $businessRule = $this->businessRule($business, $day);
        if ($businessRule['closed']) return [];
        $intervals = $this->ruleIntervals($day, $businessRule);
        if (!$intervals || !$staff) return $intervals;

        $staffRule = $this->staffRule($business, $staff, $day);
        if ($staffRule === null) return $intervals;
        if ($staffRule['closed']) return [];

        return $this->intersect($intervals, $this->ruleIntervals($day, $staffRule));
    }

    public function contains(Business $business, Carbon $startLocal, Carbon $endLocal, ?User $staff = null): bool
    {
        if ($endLocal->lte($startLocal)) return false;
        foreach ($this->intervalsFor($business, $startLocal, $staff) as $interval) {
            if ($startLocal->gte($interval['start']) && $endLocal->lte($interval['end'])) return true;
        }
        return false;
    }

    public function hasStructuredSchedule(Business $business): bool
    {
        return Schema::hasTable('business_working_hours')
            && DB::table('business_working_hours')->where('business_id', $business->id)->exists();
    }

    public function shouldEnforce(Business $business, ?User $staff, Carbon $date): bool
    {
        if ($this->hasStructuredSchedule($business)) return true;
        if ($staff && Schema::hasTable('staff_working_hours') && DB::table('staff_working_hours')
            ->where('business_id', $business->id)->where('user_id', $staff->id)->exists()) return true;
        if (Schema::hasTable('schedule_exceptions')) {
            $query = DB::table('schedule_exceptions')
                ->where('business_id', $business->id)
                ->whereDate('date', $date->copy()->timezone($business->effectiveTimezone())->toDateString());
            $query->where(function ($q) use ($staff) {
                $q->whereNull('user_id');
                if ($staff) $q->orWhere('user_id', $staff->id);
            });
            if ($query->exists()) return true;
        }
        return false;
    }

    public function legacyEnvelope(Business $business): ?array
    {
        if (!$this->hasStructuredSchedule($business)) return null;
        $rows = DB::table('business_working_hours')
            ->where('business_id', $business->id)
            ->where('is_closed', false)
            ->whereNotNull('start')->whereNotNull('end')->get(['start', 'end']);
        if ($rows->isEmpty()) return null;
        $starts = $rows->map(fn ($row) => $this->time($row->start))->filter()->sort()->values();
        $ends = $rows->map(fn ($row) => $this->time($row->end))->filter()->sort()->values();
        return $starts->isEmpty() || $ends->isEmpty() ? null : ['start' => $starts->first(), 'end' => $ends->last()];
    }

    private function businessRule(Business $business, Carbon $day): array
    {
        $rule = [
            'closed' => false,
            'start' => $this->time($business->work_start) ?: '09:00',
            'end' => $this->time($business->work_end) ?: '18:00',
            'break_start' => null,
            'break_end' => null,
        ];
        if (Schema::hasTable('business_working_hours')) {
            $row = DB::table('business_working_hours')->where('business_id', $business->id)->where('weekday', $day->isoWeekday())->first();
            if ($row) $rule = $this->fromRow($row, $rule);
        }
        return $this->exceptionRule($business, $day, null, $rule);
    }

    private function staffRule(Business $business, User $staff, Carbon $day): ?array
    {
        $rule = null;
        $openAllDay = ['closed' => false, 'start' => '00:00', 'end' => '23:59', 'break_start' => null, 'break_end' => null];
        if (Schema::hasTable('staff_working_hours')) {
            $row = DB::table('staff_working_hours')->where('business_id', $business->id)->where('user_id', $staff->id)->where('weekday', $day->isoWeekday())->first();
            if ($row) $rule = $this->fromRow($row, $openAllDay);
        }
        if (!Schema::hasTable('schedule_exceptions')) return $rule;
        $row = DB::table('schedule_exceptions')->where('business_id', $business->id)->where('user_id', $staff->id)->whereDate('date', $day->toDateString())->first();
        return $row ? $this->fromRow($row, $rule ?? $openAllDay) : $rule;
    }

    private function exceptionRule(Business $business, Carbon $day, ?int $userId, array $base): array
    {
        if (!Schema::hasTable('schedule_exceptions')) return $base;
        $query = DB::table('schedule_exceptions')->where('business_id', $business->id)->whereDate('date', $day->toDateString());
        $userId === null ? $query->whereNull('user_id') : $query->where('user_id', $userId);
        $row = $query->first();
        return $row ? $this->fromRow($row, $base) : $base;
    }

    private function fromRow(object $row, array $base): array
    {
        $closed = (bool) ($row->is_closed ?? false);
        return [
            'closed' => $closed,
            'start' => $closed ? null : ($this->time($row->start ?? null) ?: $base['start']),
            'end' => $closed ? null : ($this->time($row->end ?? null) ?: $base['end']),
            'break_start' => $closed ? null : ($this->time($row->break_start ?? null) ?: $base['break_start']),
            'break_end' => $closed ? null : ($this->time($row->break_end ?? null) ?: $base['break_end']),
        ];
    }

    private function ruleIntervals(Carbon $day, array $rule): array
    {
        if ($rule['closed'] || !$rule['start'] || !$rule['end']) return [];
        $start = Carbon::parse($day->toDateString().' '.$rule['start'], $day->getTimezone())->seconds(0);
        $end = Carbon::parse($day->toDateString().' '.$rule['end'], $day->getTimezone())->seconds(0);
        if ($end->lte($start)) $end->addDay();
        $intervals = [['start' => $start, 'end' => $end]];
        if (!$rule['break_start'] || !$rule['break_end']) return $intervals;
        $breakStart = Carbon::parse($day->toDateString().' '.$rule['break_start'], $day->getTimezone())->seconds(0);
        $breakEnd = Carbon::parse($day->toDateString().' '.$rule['break_end'], $day->getTimezone())->seconds(0);
        if ($breakEnd->lte($breakStart)) $breakEnd->addDay();
        $result = [];
        foreach ($intervals as $interval) {
            if ($breakEnd->lte($interval['start']) || $breakStart->gte($interval['end'])) { $result[] = $interval; continue; }
            if ($breakStart->gt($interval['start'])) $result[] = ['start' => $interval['start']->copy(), 'end' => $breakStart->copy()->min($interval['end'])];
            if ($breakEnd->lt($interval['end'])) $result[] = ['start' => $breakEnd->copy()->max($interval['start']), 'end' => $interval['end']->copy()];
        }
        return array_values(array_filter($result, fn ($interval) => $interval['end']->gt($interval['start'])));
    }

    private function intersect(array $left, array $right): array
    {
        $result = [];
        foreach ($left as $a) foreach ($right as $b) {
            $start = $a['start']->copy()->max($b['start']);
            $end = $a['end']->copy()->min($b['end']);
            if ($end->gt($start)) $result[] = ['start' => $start, 'end' => $end];
        }
        return $result;
    }

    private function time(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return preg_match('/^\d{2}:\d{2}/', $value) ? substr($value, 0, 5) : null;
    }
}
