<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',    // փոխել salon_id-ից business_id
        'plan_id',
        'amount',
        'currency',
        'billing_cycle',
        'status',
        'payment_method',
        'note',
        'paid_at',
        'cancelled_at',
        'meta',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'meta' => 'array',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function paymentTransactions()
    {
        return $this->hasMany(PaymentTransaction::class);
    }
}

