<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MobileDevice extends Model
{
    protected $fillable = [
        'owner_type',
        'owner_id',
        'audience',
        'expo_push_token',
        'platform',
        'device_id',
        'app_version',
        'locale',
        'timezone',
        'last_seen_at',
        'disabled_at',
    ];

    protected $hidden = ['owner_type', 'owner_id'];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
