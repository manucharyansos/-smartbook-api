<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessPricingOverride extends Model
{
    protected $fillable = [
        'business_id',
        'plan_id',
        'custom_monthly_price',
        'custom_yearly_price',
        'discount_type',
        'discount_value',
        'billing_cycles_limit',
        'starts_at',
        'ends_at',
        'note',
        'created_by_admin_id',
        'is_active',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function creator()
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function isCurrentlyActive(): bool
    {
        if (!$this->is_active) return false;
        if ($this->starts_at && now()->lt($this->starts_at)) return false;
        if ($this->ends_at && now()->gt($this->ends_at)) return false;
        return true;
    }
}
