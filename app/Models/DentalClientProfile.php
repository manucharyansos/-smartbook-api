<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DentalClientProfile extends Model
{
    use HasFactory, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'client_id',
        'chief_complaint',
        'dental_history',
        'current_medications',
        'treatment_alerts',
        'insurance_provider',
        'insurance_number',
        'preferred_doctor',
        'pain_level',
        'oral_hygiene_status',
        'periodontal_risk',
        'last_xray_at',
        'next_follow_up_at',
    ];

    protected $casts = [
        'pain_level' => 'integer',
        'last_xray_at' => 'datetime',
        'next_follow_up_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }
}
