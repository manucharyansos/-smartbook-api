<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyProgram extends Model
{
    protected $fillable = [
        'business_id',
        'is_enabled',
        'points_per_currency_unit', // e.g. 1 point per 100 AMD
        'redeem_points_step',
        'redeem_currency_amount',
        'max_redeem_percent',
        'allow_gift_card_with_points',
        'points_expire_after_days',
        'currency_unit',            // e.g. 100
        'min_booking_amount',       // e.g. 0
        'notes',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'points_per_currency_unit' => 'integer',
        'redeem_points_step' => 'integer',
        'redeem_currency_amount' => 'integer',
        'max_redeem_percent' => 'integer',
        'allow_gift_card_with_points' => 'boolean',
        'points_expire_after_days' => 'integer',
        'currency_unit' => 'integer',
        'min_booking_amount' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
