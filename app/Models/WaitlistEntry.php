<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaitlistEntry extends Model
{
    protected $fillable = [
        'business_id', 'location_id', 'service_id', 'staff_id', 'client_id',
        'customer_name', 'customer_phone', 'customer_email', 'desired_date',
        'window_start', 'window_end', 'party_size', 'status', 'offered_staff_id',
        'offered_starts_at', 'offered_ends_at', 'offer_token_hash', 'offer_expires_at',
        'notified_at', 'booked_booking_id', 'source', 'notes',
    ];

    protected $casts = [
        'desired_date' => 'date',
        'party_size' => 'integer',
        'offered_starts_at' => 'datetime',
        'offered_ends_at' => 'datetime',
        'offer_expires_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function location(): BelongsTo { return $this->belongsTo(BusinessLocation::class, 'location_id'); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function staff(): BelongsTo { return $this->belongsTo(User::class, 'staff_id'); }
    public function offeredStaff(): BelongsTo { return $this->belongsTo(User::class, 'offered_staff_id'); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function bookedBooking(): BelongsTo { return $this->belongsTo(Booking::class, 'booked_booking_id'); }
}
