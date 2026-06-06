<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_code',
        'group_id',
        'business_id',
        'location_id',
        'service_id',
        'staff_id',
        'client_id',
        'room_id',
        'client_name',
        'client_phone',
        'starts_at',
        'ends_at',
        'status',
        'notes',
        'source',
        'source_meta',
        'final_price',
        'currency',
        'clinical_notes',
        'treatment_codes',
        'is_emergency',

        // phone verification (public booking)
        'phone_verification_code_hash',
        'phone_verification_expires_at',
        'phone_verified_at',
        'phone_verification_attempts',
        'guest_access_token_hash',
        'guest_access_expires_at',
    ];

    protected $casts = [
        'location_id' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'treatment_codes' => 'array',
        'is_emergency' => 'boolean',
        'source_meta' => 'array',
        'phone_verification_expires_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'guest_access_expires_at' => 'datetime',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function location()
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Multi-service booking items (Phase 3A).
     * If empty, booking falls back to single service_id.
     */
    public function items()
    {
        return $this->hasMany(BookingItem::class)->orderBy('position');
    }

    public function staff()
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function isPhoneVerified(): bool
    {
        return (bool)$this->phone_verified_at;
    }
}
