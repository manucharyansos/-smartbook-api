<?php

namespace App\Support;

use App\Models\Business;

class Amenities
{
    /** @return list<string> */
    public static function allowedFor(Business $business): array
    {
        $keys = config('amenities.general', []);
        if ($business->isRental()) {
            $keys = [...$keys, ...config('amenities.rentals', [])];
        }

        return array_values($keys);
    }

    /** Keeps only known keys, in catalog order, without duplicates. */
    public static function normalize(Business $business, ?array $keys): array
    {
        $chosen = array_flip(array_map('strval', $keys ?? []));

        return array_values(array_filter(self::allowedFor($business), fn (string $key) => isset($chosen[$key])));
    }
}
