<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DentalToothRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'client_id',
        'tooth_number',
        'status',
        'condition_label',
        'surface_summary',
        'notes',
        'recommendation',
        'last_treated_at',
        'next_action_due_at',
        'priority',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'surface_summary' => 'array',
        'last_treated_at' => 'datetime',
        'next_action_due_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
