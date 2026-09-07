<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingPayment extends Model
{
    protected $fillable = [
        'booking_id', 'business_id', 'provider', 'reference', 'amount', 'currency',
        'status', 'checkout_url', 'return_url', 'cancel_url', 'provider_payload',
        'expires_at', 'paid_at', 'failed_at', 'cancelled_at', 'refunded_at',
    ];

    protected $hidden = ['return_url', 'cancel_url', 'provider_payload'];

    protected $casts = [
        'provider_payload' => 'array',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function booking() { return $this->belongsTo(Booking::class); }
    public function business() { return $this->belongsTo(Business::class); }
}
