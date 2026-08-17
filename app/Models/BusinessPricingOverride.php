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
        'custom_monthly_price' => 'integer',
        'custom_yearly_price' => 'integer',
        'discount_value' => 'float',
        'billing_cycles_limit' => 'integer',
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
        if (!$this->hasRemainingBillingCycles()) return false;
        return true;
    }

    public function usedBillingCycles(): int
    {
        if (!$this->id || !$this->business_id || !$this->plan_id) {
            return 0;
        }

        return Invoice::query()
            ->where('business_id', $this->business_id)
            ->where('plan_id', $this->plan_id)
            ->whereIn('status', ['approved', 'paid'])
            ->get(['meta'])
            ->filter(function (Invoice $invoice) {
                $meta = is_array($invoice->meta) ? $invoice->meta : [];

                return (int) ($meta['pricing_override_id'] ?? 0) === (int) $this->id;
            })
            ->count();
    }

    public function remainingBillingCycles(): ?int
    {
        if ($this->billing_cycles_limit === null) {
            return null;
        }

        return max(0, (int) $this->billing_cycles_limit - $this->usedBillingCycles());
    }

    public function hasRemainingBillingCycles(): bool
    {
        $remaining = $this->remainingBillingCycles();

        return $remaining === null || $remaining > 0;
    }
}
