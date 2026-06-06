<?php
// app/Models/Business.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use App\Support\MediaUrl;
use App\Support\BusinessVertical;

class Business extends Model
{
    use HasFactory; use Notifiable;

    protected $fillable = [
        'name',
        'slug',
        'business_type', // canonical: services | healthcare; legacy aliases are still accepted
        'vertical',
        'business_category_id',
        'custom_category_name',
        'phone',
        'address',
        'short_description',
        'description',
        'logo_url',
        'cover_url',
        'instagram_url',
        'facebook_url',
        'website_url',
        'messenger_url',
        'whatsapp_url',
        'is_onboarding_completed',
        'is_public',
        'is_public_profile_enabled',
        'is_marketplace_visible',
        'show_logo',
        'show_cover',
        'show_staff',
        'show_services',
        'is_verified',
        'status',
        'billing_status',
        'suspended_at',
        'work_start',
        'work_end',
        'slot_step_minutes',
        'timezone',
    ];

    protected $casts = [
        'is_onboarding_completed' => 'boolean',
        'is_public' => 'boolean',
        'is_public_profile_enabled' => 'boolean',
        'is_marketplace_visible' => 'boolean',
        'show_logo' => 'boolean',
        'show_cover' => 'boolean',
        'show_staff' => 'boolean',
        'show_services' => 'boolean',
        'is_verified' => 'boolean',
        'suspended_at' => 'datetime',
    ];

    // Relations
    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    public function clients()
    {
        return $this->hasMany(Client::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }

    public function workingHours()
    {
        return $this->hasMany(BusinessWorkingHour::class);
    }

    public function category()
    {
        return $this->belongsTo(BusinessCategory::class, 'business_category_id');
    }

    public function locations()
    {
        return $this->hasMany(BusinessLocation::class)->orderBy('sort_order')->orderBy('id');
    }

    public function primaryLocation()
    {
        return $this->hasOne(BusinessLocation::class)->where('is_primary', true);
    }

    public function staffSchedules()
    {
        return $this->hasMany(StaffWorkSchedule::class);
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function pricingOverrides()
    {
        return $this->hasMany(BusinessPricingOverride::class);
    }

    public function paymentTransactions()
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function contactRequests()
    {
        return $this->hasMany(ContactRequest::class);
    }

    // Owner relation
    public function owner()
    {
        return $this->hasOne(User::class)->where('role', User::ROLE_OWNER);
    }

    // Business type checks
    public function normalizedVertical(): string
    {
        return BusinessVertical::normalize($this->vertical ?: $this->business_type);
    }

    public function isServicesVertical(): bool
    {
        return $this->normalizedVertical() === BusinessVertical::SERVICES;
    }

    public function isHealthcareVertical(): bool
    {
        return $this->normalizedVertical() === BusinessVertical::HEALTHCARE;
    }

    public function isSalon(): bool
    {
        return $this->isServicesVertical();
    }

    public function isClinic(): bool
    {
        return $this->isHealthcareVertical();
    }

    /**
     * Backwards-compatible alias.
     * Some parts of the app still use the older name "dental".
     */
    public function isDental(): bool
    {
        return $this->isHealthcareVertical();
    }

    // Seat management
    public function activeSeatCount(): int
    {
        // Staff seats are limited by plan.
        // Owner and manager accounts should not consume staff seats.
        return $this->users()
            ->where('is_active', true)
            ->where('role', User::ROLE_STAFF)
            ->count();
    }

    public function seatLimit(): ?int
    {
        // snapshot-first
        $sub = $this->subscription;
        if ($sub && $sub->seatsLimit() !== null) return $sub->seatsLimit();

        // fallback (legacy)
        return $this->subscription?->plan?->staffLimit() ?? $this->subscription?->plan?->seats;
    }

    public function hasAvailableSeat(): bool
    {
        $limit = $this->seatLimit();
        if (!$limit) return true;
        return $this->activeSeatCount() < $limit;
    }

    public function seatUsers()
    {
        return $this->users()
            ->where('is_active', true)
            ->where('role', User::ROLE_STAFF);
    }



    public function locationCount(): int
    {
        return (int) $this->locations()->count();
    }

    public function locationLimit(): int
    {
        $subscription = $this->subscription;
        $features = $subscription?->features() ?? [];
        $locations = (int) ($features['locations'] ?? $subscription?->plan?->locations ?? 1);
        return max(1, $locations);
    }

    public function serviceCount(bool $activeOnly = false): int
    {
        $query = $this->services();

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return (int) $query->count();
    }

    public function activeServiceCount(): int
    {
        return $this->serviceCount(true);
    }

    public function serviceLimit(): ?int
    {
        $subscription = $this->subscription;
        $features = $subscription?->features() ?? [];

        $limit = $features['services_limit']
            ?? $subscription?->plan?->features['services_limit']
            ?? null;

        if ($limit === null || $limit === '') {
            return null;
        }

        $limit = (int) $limit;
        return $limit > 0 ? $limit : null;
    }

    public function hasAvailableServiceSlot(): bool
    {
        $limit = $this->serviceLimit();
        if (!$limit) {
            return true;
        }

        return $this->activeServiceCount() < $limit;
    }

    public function effectiveTimezone(): string
    {
        $tz = trim((string) ($this->timezone ?? ''));
        return $tz !== '' ? $tz : 'Asia/Yerevan';
    }

    public function syncPrimaryAddressFromLocations(): void
    {
        $primary = $this->locations()->where('is_primary', true)->first()
            ?? $this->locations()->orderBy('sort_order')->orderBy('id')->first();

        if ($primary) {
            $this->forceFill([
                'address' => $primary->address,
                'phone' => $this->phone ?: $primary->phone,
            ])->save();
            return;
        }

        $this->forceFill(['address' => null])->save();
    }

    public function getLogoUrlAttribute($value): ?string
    {
        return MediaUrl::absolute($value);
    }

    public function getCoverUrlAttribute($value): ?string
    {
        return MediaUrl::absolute($value);
    }

}
