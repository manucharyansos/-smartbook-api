<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AccountDeletionRequest extends Model
{
    protected $fillable = ['requester_type', 'requester_id', 'audience', 'email', 'reason', 'status', 'requested_at', 'processed_at'];
    protected $casts = ['requested_at' => 'datetime', 'processed_at' => 'datetime'];
    public function requester(): MorphTo { return $this->morphTo(); }
}
