<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaign extends Model
{
    protected $fillable = [
        'business_id', 'created_by', 'name', 'channel', 'segment', 'subject', 'body',
        'status', 'scheduled_for', 'started_at', 'completed_at', 'recipient_count',
        'sent_count', 'failed_count', 'last_error',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'recipient_count' => 'integer',
        'sent_count' => 'integer',
        'failed_count' => 'integer',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function deliveries(): HasMany { return $this->hasMany(MarketingDelivery::class, 'campaign_id'); }
}
