<?php
// app/Models/Room.php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    use HasFactory, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'name',
        'type',
        'capacity',
        'equipment',
        'is_active',
    ];

    protected $casts = [
        'equipment' => 'array',
        'is_active' => 'boolean',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForDental($query)
    {
        return $query->whereHas('business', fn($q) => $q->where('vertical', 'healthcare')->orWhereIn('business_type', ['healthcare', 'dental', 'clinic']));
    }
}
