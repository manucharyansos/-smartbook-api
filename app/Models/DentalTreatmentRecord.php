<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DentalTreatmentRecord extends Model
{
    use HasFactory, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'client_id',
        'booking_id',
        'created_by_user_id',
        'performed_by_user_id',
        'visit_date',
        'procedure_name',
        'procedure_code',
        'diagnosis',
        'treated_teeth',
        'surfaces',
        'notes',
        'recommendation',
        'treatment_status',
        'priority',
        'cost',
        'follow_up_at',
    ];

    protected $casts = [
        'visit_date' => 'datetime',
        'follow_up_at' => 'datetime',
        'treated_teeth' => 'array',
        'surfaces' => 'array',
        'cost' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}
