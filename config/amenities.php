<?php

// Fixed amenity keys a business can mark per address. Labels live in the web/mobile
// translations; the API only accepts and returns these keys.
return [
    'general' => [
        'wifi', 'parking', 'card_payment', 'air_conditioning', 'wheelchair_access',
        'kids_corner', 'coffee_tea', 'waiting_area',
    ],
    'rentals' => [
        'projector', 'whiteboard', 'sound_system', 'kitchen', 'changing_room',
    ],
    'max_capacity_people' => 5000,
    'max_area_sqm' => 100000,
];
