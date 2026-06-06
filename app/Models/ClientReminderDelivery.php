<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientReminderDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_reminder_id',
        'channel',
        'status',
        'recipient',
        'provider',
        'scheduled_for',
        'sent_at',
        'failed_at',
        'error_message',
        'payload',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
        'payload' => 'array',
    ];

    public function reminder()
    {
        return $this->belongsTo(ClientReminder::class, 'client_reminder_id');
    }
}
