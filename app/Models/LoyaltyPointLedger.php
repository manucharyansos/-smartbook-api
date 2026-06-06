<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyPointLedger extends Model
{
    protected $table = 'loyalty_point_ledgers';

    protected $fillable = [
        'business_id',
        'client_id',
        'booking_id',
        'delta_points',
        'entry_type',
        'reason',
        'expires_at',
        'meta',
        'reverted_ledger_id',
        'created_by',
    ];

    protected $casts = [
        'delta_points' => 'integer',
        'booking_id' => 'integer',
        'created_by' => 'integer',
        'expires_at' => 'datetime',
        'meta' => 'array',
        'reverted_ledger_id' => 'integer',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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
