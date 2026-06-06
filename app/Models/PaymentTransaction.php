<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'business_id',
        'invoice_id',
        'provider',
        'provider_transaction_id',
        'provider_payment_id',
        'payment_method',
        'amount',
        'currency',
        'status',
        'checkout_url',
        'checkout_payload',
        'provider_payload',
        'paid_at',
        'failed_at',
    ];

    protected $casts = [
        'checkout_payload' => 'array',
        'provider_payload' => 'array',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
