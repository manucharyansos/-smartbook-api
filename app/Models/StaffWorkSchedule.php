<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffWorkSchedule extends Model
{
    use HasFactory, BelongsToBusiness;

    protected $fillable = [
        'business_id',    // փոխել salon_id-ից business_id
        'staff_id',
        'day_of_week',    // 0=Sunday, 1=Monday, ...
        'starts_at',
        'ends_at',
        'break_start',
        'break_end',
        'is_active',
        'is_closed',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'is_active' => 'boolean',
        'is_closed' => 'boolean',
        'starts_at' => 'datetime:H:i',
        'ends_at' => 'datetime:H:i',
        'break_start' => 'datetime:H:i',
        'break_end' => 'datetime:H:i',
    ];

    public function staff()
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    // Helpers
    public function isWorking(): bool
    {
        return !$this->is_closed && $this->starts_at && $this->ends_at;
    }

    public function getDayNameAttribute(): string
    {
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        return $days[$this->day_of_week] ?? 'Unknown';
    }
}
