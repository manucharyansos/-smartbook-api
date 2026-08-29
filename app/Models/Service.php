<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToBusiness;
use App\Support\MediaUrl;

class Service extends Model
{
    use HasFactory, BelongsToBusiness;

    protected $fillable = [
        'name',
        'description',
        'image_url',
        'duration_minutes',
        'booking_mode',
        'capacity',
        'price',
        'is_active',
        'currency',
        'business_id', // Փոխել salon_id-ից business_id
        'location_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'capacity' => 'integer',
    ];

    public function business() // Փոխել salon() -ից business()
    {
        return $this->belongsTo(Business::class);
    }

    public function location()
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function locations()
    {
        return $this->belongsToMany(BusinessLocation::class, 'service_locations', 'service_id', 'business_location_id')->withTimestamps();
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }


    public function getImageUrlAttribute($value): ?string
    {
        return MediaUrl::absolute($value);
    }

}
