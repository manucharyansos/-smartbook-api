<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $timezone = $this->relationLoaded('business') && $this->business
            ? $this->business->effectiveTimezone()
            : 'Asia/Yerevan';

        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'party_size' => (int) ($this->party_size ?? 1),
            'recurrence_id' => $this->recurrence_id,
            'recurrence_frequency' => $this->recurrence_frequency,
            'recurrence_index' => (int) ($this->recurrence_index ?? 1),
            'recurrence_count' => (int) ($this->recurrence_count ?? 1),
            'booking_code' => $this->booking_code,

            'business_id' => $this->business_id,
            'location_id' => $this->location_id,
            'service_id' => $this->service_id,
            'staff_id' => $this->staff_id,
            'client_id' => $this->client_id,

            'client_name' => $this->client_name,
            'client_phone' => $this->client_phone,
            'client_email' => $this->contactEmail(),
            'notes' => $this->notes,
            'source' => $this->source,
            'source_meta' => $this->source_meta,

            'status' => $this->status,
            // Admin booking forms and calendars exchange business-local wall-clock
            // values. Datetimes remain UTC in the database, but the API must not
            // expose a UTC value without an offset and let the browser guess.
            'starts_at' => $this->businessLocalDateTime($this->starts_at, $timezone),
            'ends_at' => $this->businessLocalDateTime($this->ends_at, $timezone),

            'final_price' => $this->final_price,
            'currency' => $this->currency,

            // relations (✅ only if loaded)
            'service' => $this->whenLoaded('service', function () {
                return [
                    'id' => $this->service->id,
                    'name' => $this->service->name,
                    'duration_minutes' => $this->service->duration_minutes,
                    'price' => $this->service->price,
                    'currency' => $this->service->currency ?? null,
                    'booking_mode' => $this->service->booking_mode ?? 'individual',
                    'capacity' => (int) ($this->service->capacity ?? 1),
                ];
            }),

            'staff' => $this->whenLoaded('staff', function () {
                return [
                    'id' => $this->staff->id,
                    'name' => $this->staff->name,
                    'email' => $this->staff->email ?? null,
                ];
            }),

            'location' => $this->whenLoaded('location', function () {
                return [
                    'id' => $this->location->id,
                    'name' => $this->location->name,
                    'address' => $this->location->address,
                    'phone' => $this->location->phone,
                    'is_primary' => (bool) $this->location->is_primary,
                ];
            }),

            'business' => $this->whenLoaded('business', function () {
                return [
                    'id' => $this->business->id,
                    'name' => $this->business->name ?? null,
                    'timezone' => $this->business->timezone ?? null,
                ];
            }),

            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->id,
                    'name' => $this->client->name,
                    'phone' => $this->client->phone,
                ];
            }),

            'room' => $this->whenLoaded('room', function () {
                return [
                    'id' => $this->room->id,
                    'name' => $this->room->name ?? null,
                ];
            }),

            // ✅ Phase 3A
            'items' => $this->whenLoaded('items', function () {
                return $this->items->map(function ($it) {
                    return [
                        'id' => $it->id,
                        'service_id' => $it->service_id,
                        'position' => $it->position,
                        'duration_minutes' => $it->duration_minutes,
                        'price' => $it->price,
                        'currency' => $it->currency,
                        'service' => $it->relationLoaded('service') && $it->service ? [
                            'id' => $it->service->id,
                            'name' => $it->service->name,
                            'duration_minutes' => $it->service->duration_minutes,
                            'price' => $it->service->price,
                        ] : null,
                    ];
                })->values();
            }),
        ];
    }

    private function businessLocalDateTime(mixed $value, string $timezone): mixed
    {
        if (!$value) {
            return $value;
        }

        $date = $value instanceof Carbon
            ? $value->copy()
            : Carbon::parse((string) $value, 'UTC');

        return $date->setTimezone($timezone)->format('Y-m-d H:i:s');
    }
}
