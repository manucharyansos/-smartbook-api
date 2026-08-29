<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GiftCard extends Model
{
    protected $fillable = [
        'business_id',
        'code',
        'initial_amount',
        'balance',
        'currency',
        'issued_to_name',
        'issued_to_phone',
        'issued_to_email',
        'purchased_by_name',
        'purchased_by_phone',
        'purchased_by_email',
        'expires_at',
        'status',
        'notes',
        'delivery_message',
        'delivery_status',
        'delivered_at',
        'last_redeemed_at',
        'redeemed_total',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_redeemed_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(GiftCardLedger::class)->latest('id');
    }

    public function isActive(): bool
    {
        if ($this->status !== 'active') return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        return $this->balance > 0;
    }
}
