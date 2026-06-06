<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BookingItem
 *
 * Stores a single service row inside a booking (multi-service booking).
 */
class BookingItem extends Model
{
    protected $fillable = [
        'booking_id',
        'service_id',
        'position',
        'duration_minutes',
        'price',
        'currency',
    ];

    protected $casts = [
        'booking_id' => 'int',
        'service_id' => 'int',
        'position' => 'int',
        'duration_minutes' => 'int',
        'price' => 'int',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
