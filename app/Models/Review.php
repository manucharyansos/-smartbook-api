<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $fillable = [
        'business_id', 'booking_id', 'client_id', 'staff_id', 'service_id', 'rating', 'comment', 'author_name',
        'reply', 'replied_at', 'reported_at', 'report_reason', 'hidden_at', 'hidden_by_admin_id',
    ];

    protected $casts = [
        'rating' => 'integer',
        'replied_at' => 'datetime',
        'reported_at' => 'datetime',
        'hidden_at' => 'datetime',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function staff()
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('hidden_at');
    }
}
