<?php

namespace App\Models;

use App\Services\ScheduleWindowService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_code',
        'group_id',
        'party_size',
        'recurrence_id',
        'recurrence_frequency',
        'recurrence_index',
        'recurrence_count',
        'business_id',
        'location_id',
        'service_id',
        'staff_id',
        'client_id',
        'room_id',
        'client_name',
        'client_phone',
        'client_email',
        'starts_at',
        'ends_at',
        'status',
        'notes',
        'source',
        'source_meta',
        'final_price',
        'currency',
        'clinical_notes',
        'treatment_codes',
        'is_emergency',
        'phone_verification_code_hash',
        'phone_verification_expires_at',
        'phone_verified_at',
        'phone_verification_attempts',
        'guest_access_token_hash',
        'guest_access_expires_at',
    ];

    protected $casts = [
        'location_id' => 'integer',
        'party_size' => 'integer',
        'recurrence_index' => 'integer',
        'recurrence_count' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'treatment_codes' => 'array',
        'is_emergency' => 'boolean',
        'source_meta' => 'array',
        'phone_verification_expires_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'guest_access_expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (Booking $booking) => $booking->validateStructuredSchedule());
        static::updating(function (Booking $booking) {
            if ($booking->isDirty(['business_id', 'staff_id', 'starts_at', 'ends_at'])) {
                $booking->validateStructuredSchedule();
            }
        });
    }

    private function validateStructuredSchedule(): void
    {
        if (!$this->business_id || !$this->staff_id || !$this->starts_at || !$this->ends_at) return;

        $business = Business::query()->find((int) $this->business_id);
        $staff = User::query()->where('business_id', (int) $this->business_id)->find((int) $this->staff_id);
        if (!$business || !$staff) return;

        $timezone = $business->effectiveTimezone();
        try {
            $start = $this->starts_at instanceof Carbon
                ? $this->starts_at->copy()->timezone($timezone)
                : Carbon::parse($this->starts_at, 'UTC')->timezone($timezone);
            $end = $this->ends_at instanceof Carbon
                ? $this->ends_at->copy()->timezone($timezone)
                : Carbon::parse($this->ends_at, 'UTC')->timezone($timezone);
        } catch (\Throwable) {
            return;
        }

        $resolver = app(ScheduleWindowService::class);
        if (!$resolver->shouldEnforce($business, $staff, $start)) return;
        if ($resolver->contains($business, $start, $end, $staff)) return;

        throw ValidationException::withMessages([
            'starts_at' => ['Selected time is outside the configured business or staff working schedule.'],
        ]);
    }

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function location()
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function items()
    {
        return $this->hasMany(BookingItem::class)->orderBy('position');
    }

    public function staff()
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function payments()
    {
        return $this->hasMany(BookingPayment::class);
    }

    public function isPhoneVerified(): bool
    {
        return (bool) $this->phone_verified_at;
    }

    public function contactEmail(): ?string
    {
        return self::normalizeContactEmail($this->client_email);
    }

    public static function normalizeContactEmail(?string $email): ?string
    {
        $email = trim((string) $email);
        return $email !== '' ? mb_strtolower($email) : null;
    }
}
