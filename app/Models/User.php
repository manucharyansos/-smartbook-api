<?php
// app/Models/User.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Support\MediaUrl;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    const ROLE_SUPER_ADMIN = 'super_admin';
    const ROLE_OWNER = 'owner';
    const ROLE_MANAGER = 'manager';
    const ROLE_STAFF = 'staff';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'whatsapp_phone',
        'telegram_chat_id',
        'password',
        'role',
        'business_id',
        'location_id',
        'is_active',
        'show_in_public_team',
        'is_bookable',
        'avatar_url',
        'bio',
        'provider',
        'provider_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'show_in_public_team' => 'boolean',
        'location_id' => 'integer',
        'is_bookable' => 'boolean',
    ];

    // ✅ Use business relationship, not salon
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function location()
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function locations()
    {
        return $this->belongsToMany(BusinessLocation::class, 'staff_locations', 'staff_id', 'business_location_id')->withTimestamps();
    }

    public function preferences()
    {
        return $this->hasOne(UserPreference::class);
    }

    // Helper methods
    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    public function isManager(): bool
    {
        return $this->role === self::ROLE_MANAGER;
    }

    public function isStaff(): bool
    {
        return $this->role === self::ROLE_STAFF;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    // Staff schedule relation
    public function workSchedules()
    {
        return $this->hasMany(StaffWorkSchedule::class, 'staff_id');
    }

    // Bookings where this user is staff
    public function staffBookings()
    {
        return $this->hasMany(Booking::class, 'staff_id');
    }

    public function assignedTasks()
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    public function createdTasks()
    {
        return $this->hasMany(Task::class, 'created_by_user_id');
    }

    public function getAvatarUrlAttribute($value): ?string
    {
        return MediaUrl::absolute($value);
    }

}
