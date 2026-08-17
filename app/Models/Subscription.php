<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELED = 'canceled';

    /**
     * Vizit's primary workspace tools are included in every current plan.
     * Merging them at read time also keeps older subscription snapshots usable.
     */
    public const CORE_FEATURES = [
        'calendar',
        'bookings',
        'services',
        'staff_schedules',
        'public_booking',
        'client_cabinet',
        'source_tracking',
        'analytics',
        'tasks',
        'gift_cards',
        'loyalty',
        'social_buttons',
        'multilingual',
        'dark_mode',
        'blocks',
        'rooms',
    ];

    protected $fillable = [
        'business_id',
        'plan_id',
        'plan_version',
        'seats_limit_snapshot',
        'features_snapshot',
        'status',
        'billing_cycle',
        'trial_ends_at',
        'current_period_starts_at',
        'current_period_ends_at',
        'cancel_at_period_end',
        'canceled_at',
        'suspended_at',
        'provider',
        'provider_customer_id',
        'provider_subscription_id',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_starts_at' => 'datetime',
        'current_period_ends_at' => 'datetime',
        'canceled_at' => 'datetime',
        'suspended_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'features_snapshot' => 'array',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Snapshot helpers
     */
    public function seatsLimit(): ?int
    {
        return $this->seats_limit_snapshot !== null ? (int)$this->seats_limit_snapshot : null;
    }

    public function features(): array
    {
        $features = is_array($this->features_snapshot) ? $this->features_snapshot : [];

        foreach (self::CORE_FEATURES as $feature) {
            $features[$feature] = true;
        }

        return $features;
    }

    public function hasFeature(string $key): bool
    {
        $f = $this->features();
        return (bool)($f[$key] ?? false);
    }

    /**
     * Apply plan snapshot to this subscription.
     * This implements "Default affects new subs only" behavior automatically.
     * (Existing subs keep their snapshots unless explicitly re-applied.)
     */
    public function applyPlanSnapshot(Plan $plan): void
    {
        $this->plan_id = $plan->id;
        $this->plan_version = (int)($plan->version ?? 1);
        $this->seats_limit_snapshot = (int)$plan->staffLimit();
        $this->features_snapshot = $plan->getFeaturesList();
    }

    /**
     * Status machine
     */
    public function isActive(): bool
    {
        // Business/admin suspended subscription
        if ($this->status === self::STATUS_SUSPENDED) return false;

        // Canceled is inactive
        if ($this->status === self::STATUS_CANCELED) return false;

        if ($this->status === self::STATUS_ACTIVE) {
            // If end is set and already passed => expired
            if ($this->current_period_ends_at && now()->gte($this->current_period_ends_at)) {
                return false;
            }
            return true;
        }

        if ($this->status === self::STATUS_TRIALING) {
            // if no trial end set, allow (for demo/testing)
            if (!$this->trial_ends_at) return true;
            return now()->lt($this->trial_ends_at);
        }

        // expired / past_due / unknown
        return false;
    }

    public function computedStatus(): string
    {
        // convert active->expired if period ended
        if ($this->status === self::STATUS_ACTIVE && $this->current_period_ends_at && now()->gte($this->current_period_ends_at)) {
            return self::STATUS_EXPIRED;
        }
        if ($this->status === self::STATUS_TRIALING && $this->trial_ends_at && now()->gte($this->trial_ends_at)) {
            return self::STATUS_EXPIRED;
        }
        return $this->status;
    }
}
