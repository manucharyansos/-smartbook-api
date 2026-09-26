<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class ContactRequest extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'message',
        'business_id',    // փոխել salon_id-ից business_id
        'status',
        'privacy_accepted_at',
        'privacy_version',
        'legal_acceptance_ip',
        'legal_acceptance_user_agent',
    ];

    protected $casts = [
        'status' => 'string',
        'privacy_accepted_at' => 'datetime',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function scopeNew($query)
    {
        return $query->where('status', 'new');
    }
}
