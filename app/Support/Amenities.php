<?php

namespace App\Support;

use App\Models\Business;

class Amenities
{
    public const MAX_CAPACITY_PEOPLE = 5000;

    public const MAX_AREA_SQM = 100000;

    private const GENERAL_KEYS = [
        'wifi',
        'parking',
        'card_payment',
        'air_conditioning',
        'wheelchair_access',
        'kids_corner',
        'coffee_tea',
        'waiting_area',
    ];

    private const RENTAL_KEYS = [
        'projector',
        'whiteboard',
        'sound_system',
        'kitchen',
        'changing_room',
    ];

    /** @return list<string> */
    public static function allowedFor(Business $business): array
    {
        return $business->isRental()
            ? [...self::GENERAL_KEYS, ...self::RENTAL_KEYS]
            : self::GENERAL_KEYS;
    }

    /** Keeps only known keys, in catalog order, without duplicates. */
    public static function normalize(Business $business, ?array $keys): array
    {
        $chosen = array_flip(array_map('strval', $keys ?? []));

        return array_values(array_filter(self::allowedFor($business), fn (string $key) => isset($chosen[$key])));
    }
}
