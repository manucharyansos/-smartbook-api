<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingDelivery extends Model
{
    protected $fillable = [
        'campaign_id', 'business_id', 'client_id', 'email', 'status',
        'unsubscribe_token_hash', 'sent_at', 'error',
    ];

    protected $casts = ['sent_at' => 'datetime'];

    public function campaign(): BelongsTo { return $this->belongsTo(MarketingCampaign::class, 'campaign_id'); }
    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
}
