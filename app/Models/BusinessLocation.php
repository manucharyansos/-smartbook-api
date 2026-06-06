<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'name',
        'address',
        'city',
        'district',
        'latitude',
        'longitude',
        'phone',
        'working_hours',
        'is_active',
        'is_primary',
        'sort_order',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'working_hours' => 'array',
        'sort_order' => 'integer',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function users()
    {
        return $this->hasMany(User::class, 'location_id');
    }

    public function linkedStaff()
    {
        return $this->belongsToMany(User::class, 'staff_locations', 'business_location_id', 'staff_id')->withTimestamps();
    }

    public function services()
    {
        return $this->hasMany(Service::class, 'location_id');
    }

    public function linkedServices()
    {
        return $this->belongsToMany(Service::class, 'service_locations', 'business_location_id', 'service_id')->withTimestamps();
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'location_id');
    }
}
