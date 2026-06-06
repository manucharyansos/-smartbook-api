<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiftCardLedger extends Model
{
    protected $fillable = [
        'business_id',
        'gift_card_id',
        'booking_id',
        'delta_amount',
        'entry_type',
        'reason',
        'meta',
        'created_by',
        'reverted_ledger_id',
    ];

    protected $casts = [
        'delta_amount' => 'integer',
        'booking_id' => 'integer',
        'created_by' => 'integer',
        'reverted_ledger_id' => 'integer',
        'meta' => 'array',
    ];

    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
