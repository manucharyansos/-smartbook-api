<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'client_account_id',
        'name',
        'phone',
        'email',
        'notes',
        'birth_date',
        'blood_type',
        'medical_history',
        'allergies',
        'emergency_contact_name',
        'emergency_contact_phone',
        'medical_notes',
        'group_name',
        'is_vip',
        'is_blacklisted',
        'blacklist_reason',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'medical_notes' => 'array',
        'is_vip' => 'boolean',
        'is_blacklisted' => 'boolean',
    ];

    public function clientAccount()
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function loyaltyLedger()
    {
        return $this->hasMany(LoyaltyPointLedger::class);
    }

    public function loyaltyBalance(): int
    {
        return (int) $this->loyaltyLedger()->sum('delta_points');
    }

    public function clientNotes()
    {
        return $this->hasMany(ClientNote::class)->latest();
    }

    public function clientReminders()
    {
        return $this->hasMany(ClientReminder::class)->orderBy('remind_at');
    }

    public function getLoyaltyPointsBalanceAttribute(): int
    {
        return $this->loyaltyBalance();
    }

    public function scopeForDental($query)
    {
        return $query->whereHas('business', fn ($q) => $q->where('vertical', 'healthcare')->orWhereIn('business_type', ['healthcare', 'dental', 'clinic']));
    }
}
