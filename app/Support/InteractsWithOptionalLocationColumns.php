<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

trait InteractsWithOptionalLocationColumns
{
    protected function tableHasLocationColumn(string $table): bool
    {
        static $cache = [];

        if (!array_key_exists($table, $cache)) {
            $cache[$table] = Schema::hasColumn($table, 'location_id');
        }

        return $cache[$table];
    }

    protected function bookingsHaveLocationColumn(): bool
    {
        return $this->tableHasLocationColumn('bookings');
    }

    protected function servicesHaveLocationColumn(): bool
    {
        return $this->tableHasLocationColumn('services');
    }

    protected function usersHaveLocationColumn(): bool
    {
        return $this->tableHasLocationColumn('users');
    }

    protected function filterColumnsForOptionalLocation(string $table, array $columns): array
    {
        if ($this->tableHasLocationColumn($table)) {
            return $columns;
        }

        return array_values(array_filter($columns, fn ($column) => $column !== 'location_id'));
    }

    protected function applyTableLocationCompatibility(Builder $query, ?int $locationId, string $table): Builder
    {
        if (!$locationId || !$this->tableHasLocationColumn($table)) {
            return $query;
        }

        return $query->where(function ($compat) use ($locationId) {
            $compat->where('location_id', $locationId)
                ->orWhereNull('location_id');
        });
    }

    protected function applyBookingLocationCompatibility(Builder $query, ?int $locationId): Builder
    {
        if (!$locationId) {
            return $query;
        }

        $bookingsHas = $this->bookingsHaveLocationColumn();
        $usersHas = $this->usersHaveLocationColumn();
        $servicesHas = $this->servicesHaveLocationColumn();

        if (!$bookingsHas && !$usersHas && !$servicesHas) {
            return $query;
        }

        return $query->where(function ($filtered) use ($locationId, $bookingsHas, $usersHas, $servicesHas) {
            if ($bookingsHas) {
                $filtered->where('location_id', $locationId);

                if ($usersHas || $servicesHas) {
                    $filtered->orWhere(function ($legacy) use ($locationId, $usersHas, $servicesHas) {
                        $legacy->whereNull('location_id')
                            ->where(function ($legacyMatch) use ($locationId, $usersHas, $servicesHas) {
                                $this->applyRelatedLocationMatchers($legacyMatch, $locationId, $usersHas, $servicesHas);
                            });
                    });
                }

                return;
            }

            $this->applyRelatedLocationMatchers($filtered, $locationId, $usersHas, $servicesHas);
        });
    }

    protected function applyRelatedLocationMatchers($query, int $locationId, bool $usersHas, bool $servicesHas): void
    {
        $hasCondition = false;

        if ($usersHas) {
            $query->whereHas('staff', fn ($staffQ) => $staffQ->where('location_id', $locationId));
            $hasCondition = true;
        }

        if ($servicesHas) {
            if ($hasCondition) {
                $query->orWhereHas('service', fn ($serviceQ) => $serviceQ->where('location_id', $locationId))
                    ->orWhereHas('items.service', fn ($itemServiceQ) => $itemServiceQ->where('location_id', $locationId));
            } else {
                $query->whereHas('service', fn ($serviceQ) => $serviceQ->where('location_id', $locationId))
                    ->orWhereHas('items.service', fn ($itemServiceQ) => $itemServiceQ->where('location_id', $locationId));
            }
        }
    }

    protected function withoutUnavailableLocationAttribute(array $payload, string $table): array
    {
        if (!$this->tableHasLocationColumn($table)) {
            unset($payload['location_id']);
        }

        return $payload;
    }
}
