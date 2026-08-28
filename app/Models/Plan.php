<?php

namespace App\Models;

use App\Support\BusinessVertical;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'code',
        'version',
        'business_type',
        'allowed_business_types',
        'description',
        'price',
        'price_beauty',
        'price_dental',
        'monthly_price',
        'yearly_price',
        'currency',
        'seats',
        'staff_limit',
        'duration_days',
        'locations',
        'features',
        'is_active',
        'is_visible',
        'sort_order',
    ];

    protected $casts = [
        'allowed_business_types' => 'array',
        'features' => 'array',
        'is_active' => 'boolean',
        'is_visible' => 'boolean',
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public static function normalizeBusinessType(string $type): string
    {
        $vertical = BusinessVertical::normalize($type);
        return $vertical === BusinessVertical::HEALTHCARE ? 'dental' : 'beauty';
    }

    public function allowsBusinessType(string $type): bool
    {
        $type = self::normalizeBusinessType($type);
        $allowed = $this->allowed_business_types;

        if (!is_array($allowed) || count($allowed) === 0) {
            if ($this->business_type === null) return true;
            if ($this->business_type === 'beauty' || $this->business_type === 'salon') return $type === 'beauty';
            if ($this->business_type === 'dental' || $this->business_type === 'clinic') return $type === 'dental';
            return true;
        }

        return in_array($type, $allowed, true);
    }

    public function supportsBusinessType(string $type): bool
    {
        return $this->allowsBusinessType($type);
    }

    public function staffLimit(): int
    {
        return (int)($this->staff_limit ?? ($this->features['staff_limit'] ?? $this->seats ?? 0));
    }

    public function monthlyPrice(): int
    {
        if ($this->monthly_price !== null) {
            return (int) $this->monthly_price;
        }

        if ($this->price !== null) {
            return (int) $this->price;
        }

        if ($this->price_beauty !== null) {
            return (int) $this->price_beauty;
        }

        if ($this->price_dental !== null) {
            return (int) $this->price_dental;
        }

        return 0;
    }

    public function yearlyPrice(): int
    {
        if ($this->yearly_price !== null) {
            return (int) $this->yearly_price;
        }

        $monthly = $this->monthlyPrice();

        return $monthly > 0 ? $monthly * 10 : 0;
    }

    public function usesCustomPricing(): bool
    {
        $features = is_array($this->features) ? $this->features : [];

        return $this->code === 'custom' || (bool) ($features['custom_pricing'] ?? false);
    }

    public function isSelfServe(): bool
    {
        return !$this->usesCustomPricing() && $this->monthlyPrice() > 0;
    }

    /**
     * Legacy helper kept so existing billing/admin code does not break.
     * Pricing is now unified and independent of business type.
     */
    public function getPriceForBusinessType(string $type): int
    {
        return $this->monthlyPrice();
    }

    public function getFeaturesList(): array
    {
        $f = is_array($this->features) ? $this->features : [];
        $staffLimit = (int)($f['staff_limit'] ?? $this->staffLimit());
        $servicesLimit = $f['services_limit'] ?? null;
        $servicesLimit = $servicesLimit === null || $servicesLimit === '' ? null : max(1, (int) $servicesLimit);
        $locations = max(1, (int) ($this->locations ?? 1));

        return [
            'staff_limit' => $staffLimit,
            'services_limit' => $servicesLimit,
            'locations' => $locations,
            'staff_seat_based' => true,
            'owners_unlimited' => true,
            'managers_unlimited' => true,
            'calendar' => true,
            'bookings' => true,
            'services' => true,
            'staff_schedules' => true,
            'public_booking' => true,
            'client_cabinet' => true,
            'source_tracking' => true,
            'analytics' => true,
            'tasks' => true,
            'blocks' => true,
            'rooms' => true,
            'gift_cards' => true,
            'loyalty' => true,
            'social_buttons' => true,
            'multilingual' => true,
            'dark_mode' => true,
            'email_notifications' => (bool)($f['email_notifications'] ?? true),
            'telegram_notifications' => (bool)($f['telegram_notifications'] ?? true),
            'sms_reminders' => $f['sms_reminders'] ?? false,
            'whatsapp_notifications' => (bool)($f['whatsapp_notifications'] ?? false),
            'priority_support' => (bool)($f['priority_support'] ?? false),
            'multi_location' => $locations > 1,
            'custom_pricing' => (bool)($f['custom_pricing'] ?? ($this->code === 'custom')),
            'partner_terms' => (bool)($f['partner_terms'] ?? ($this->code === 'custom')),
        ];
    }
}
