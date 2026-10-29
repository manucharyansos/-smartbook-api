<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteEvent extends Model
{
    public const UPDATED_AT = null;

    public const EVENTS = [
        'page_view', 'app_open', 'screen_view', 'search', 'business_view', 'booking_started', 'booking_created',
        'signup_started', 'signup_completed', 'map_open', 'contact_click', 'directions_click',
    ];

    protected $fillable = ['event', 'path', 'business_id', 'visitor', 'source', 'device', 'referrer_host', 'utm_source', 'locale', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}
