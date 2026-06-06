<?php
// app/Models/BusinessWorkingHour.php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessWorkingHour extends Model
{
    use HasFactory, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'weekday',
        'is_closed',
        'start',
        'end',
        'break_start',
        'break_end',
    ];

    protected $casts = [
        'is_closed' => 'boolean',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
