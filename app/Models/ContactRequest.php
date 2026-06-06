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
        'status'
    ];

    protected $casts = [
        'status' => 'string'
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
