<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientReminder extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'client_id',
        'user_id',
        'title',
        'note',
        'remind_at',
        'channel',
        'status',
        'is_enabled',
        'lead_minutes',
        'notify_via',
        'completed_at',
    ];

    protected $casts = [
        'remind_at' => 'datetime',
        'completed_at' => 'datetime',
        'is_enabled' => 'boolean',
        'lead_minutes' => 'integer',
        'notify_via' => 'array',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries()
    {
        return $this->hasMany(ClientReminderDelivery::class)->latest();
    }
}

