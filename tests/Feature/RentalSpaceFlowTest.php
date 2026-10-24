<?php

use App\Models\Booking;
use App\Models\BookingBlock;
use App\Models\Business;
use App\Models\BusinessMedia;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\RentalBookingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-26 05:00:00', 'UTC'));
    Mail::fake();
    Notification::fake();
    $this->rental = Business::factory()->create([
        'name' => 'Rental Space', 'slug' => 'rental-space-flow', 'business_type' => 'rentals', 'vertical' => 'rentals',
        'is_onboarding_completed' => true, 'is_public' => true, 'is_public_profile_enabled' => true, 'is_marketplace_visible' => true,
        'rental_hourly_price' => 2000, 'rental_min_minutes' => 60, 'rental_max_minutes' => 240, 'slot_step_minutes' => 30,
    ]);
    $sub = new Subscription(['business_id' => $this->rental->id, 'status' => 'active', 'current_period_ends_at' => now()->addMonth()]);
    $sub->applyPlanSnapshot(Plan::where('code', 'rental')->firstOrFail());
    $sub->save();
    $this->owner = User::factory()->create(['business_id' => $this->rental->id, 'role' => 'owner', 'is_active' => true, 'is_bookable' => false, 'show_in_public_team' => false]);
    $this->rental->workingHours()->create(['weekday' => 2, 'is_closed' => false, 'start' => '09:00', 'end' => '18:00', 'break_start' => '13:00', 'break_end' => '14:00']);
    $this->rentalData = ['starts_at' => '2026-10-27 10:00', 'duration_minutes' => 90, 'client_name' => 'Rental Client', 'client_phone' => '+37499111001', 'client_email' => 'rental@example.com'];
});

afterEach(fn () => Carbon::setTestNow());

it('offers a rental-specific 25000 AMD plan and isolates it from staff plans', function () {
    $rentalPlan = Plan::where('code', 'rental')->firstOrFail();
    expect($rentalPlan->monthlyPrice())->toBe(25000)->and($rentalPlan->yearlyPrice())->toBe(300000)
        ->and($rentalPlan->supportsBusinessType('rentals'))->toBeTrue()->and($rentalPlan->supportsBusinessType('services'))->toBeFalse();
    $this->getJson('/api/plans?business_type=rentals')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.monthly_price', 25000);
    expect($this->rental->subscription->features()['services'])->toBeFalse()->and($this->rental->subscription->features()['analytics'])->toBeTrue();
    $this->actingAs($this->owner)->getJson('/api/services')->assertForbidden();
    $this->actingAs($this->owner)->postJson('/api/staff', [])->assertForbidden();
});

it('registers and onboards a rental business without creating staff or services', function () {
    $this->postJson('/api/auth/register', [
        'business_name' => 'New Rental Space', 'business_phone' => '+37499111002', 'business_address' => 'Yerevan',
        'latitude' => 40.18, 'longitude' => 44.5, 'business_type' => 'rentals', 'vertical' => 'rentals',
        'name' => 'Rental Owner', 'email' => 'new-space@example.com', 'password' => 'Password123!', 'password_confirmation' => 'Password123!',
        'legal_accepted' => true, 'terms_version' => config('legal.terms_version'), 'privacy_version' => config('legal.privacy_version'),
    ])->assertOk()->assertJsonPath('user.vertical', 'rentals');
    $owner = User::where('email', 'new-space@example.com')->firstOrFail();
    expect($owner->is_bookable)->toBeFalse()->and($owner->business->services()->count())->toBe(0)
        ->and($owner->business->subscription->plan->code)->toBe('rental');
    $this->actingAs($owner)->postJson('/api/business/complete-onboarding')->assertUnprocessable();
    $this->putJson('/api/rental/settings', ['hourly_price' => 4000, 'min_minutes' => 60, 'max_minutes' => 240, 'buffer_minutes' => 0, 'slot_step_minutes' => 30])->assertOk();
    $this->getJson('/api/rental/schedule')->assertOk();
    $this->postJson('/api/business/complete-onboarding')->assertOk();
    expect($owner->business->fresh()->is_onboarding_completed)->toBeTrue();
});

it('creates a priced space reservation with no service or staff and prevents overlaps', function () {
    $this->actingAs($this->owner)->postJson('/api/rental/bookings', $this->rentalData)->assertCreated()->assertJsonPath('data.final_price', 3000);
    $booking = Booking::firstOrFail();
    expect($booking->service_id)->toBeNull()->and($booking->staff_id)->toBeNull()->and($booking->items()->count())->toBe(0)
        ->and($booking->starts_at->format('H:i'))->toBe('06:00');
    $this->postJson('/api/rental/bookings', [...$this->rentalData, 'starts_at' => '2026-10-27 11:00'])->assertUnprocessable();
    $this->postJson('/api/rental/bookings', [...$this->rentalData, 'starts_at' => '2026-10-27 11:30'])->assertCreated();
});

it('respects breaks closed dates buffers and manually blocked times', function () {
    $this->rental->update(['rental_buffer_minutes' => 30]);
    app(RentalBookingService::class)->create($this->rental->fresh(), $this->rentalData);
    BookingBlock::create(['business_id' => $this->rental->id, 'starts_at' => Carbon::parse('2026-10-27 15:00', 'Asia/Yerevan')->utc(), 'ends_at' => Carbon::parse('2026-10-27 16:00', 'Asia/Yerevan')->utc()]);
    $slots = $this->getJson('/api/public/businesses/'.$this->rental->slug.'/rental-availability?date=2026-10-27&duration_minutes=60')->assertOk()->json('data');
    $times = array_column($slots, 'starts_at');
    expect($times)->not->toContain('2026-10-27 09:00', '2026-10-27 11:30', '2026-10-27 12:30', '2026-10-27 13:00', '2026-10-27 15:00')->toContain('2026-10-27 12:00', '2026-10-27 14:00');
    $this->rental->workingHours()->create(['weekday' => 3, 'is_closed' => true]);
    $this->getJson('/api/public/businesses/'.$this->rental->slug.'/rental-availability?date=2026-10-28&duration_minutes=60')->assertOk()->assertJsonCount(0, 'data');
});

it('expires unverified holds and prevents expired verification from taking a newly booked slot', function () {
    $rentals = app(RentalBookingService::class);
    $hold = $rentals->create($this->rental, $this->rentalData, ['status' => 'pending', 'phone_verification_expires_at' => now()->addMinutes(10), 'phone_verification_code_hash' => Hash::make('1234')]);
    Carbon::setTestNow(now()->addMinutes(11));
    $rentals->create($this->rental, $this->rentalData);
    $this->postJson('/api/public/bookings/'.$hold->booking_code.'/verify', ['otp' => '1234'])->assertUnprocessable();
    $this->postJson('/api/public/bookings/'.$hold->booking_code.'/resend')->assertUnprocessable();
    expect($hold->fresh()->status)->toBe('pending');
});

it('verifies a public rental using OTP then manages it without staff selection', function () {
    $response = $this->postJson('/api/public/businesses/'.$this->rental->slug.'/bookings', [
        ...$this->rentalData, 'legal_accepted' => true, 'terms_version' => config('legal.terms_version'), 'privacy_version' => config('legal.privacy_version'),
    ])->assertCreated();
    $code = $response->json('data.booking_code');
    $booking = Booking::where('booking_code', $code)->firstOrFail();
    $booking->update(['phone_verification_code_hash' => Hash::make('1234')]);
    $this->postJson('/api/public/bookings/'.$code.'/verify', ['otp' => '9999'])->assertUnprocessable();
    expect($booking->fresh()->phone_verification_attempts)->toBe(1);
    $token = $this->postJson('/api/public/bookings/'.$code.'/verify', ['otp' => '1234'])->assertOk()->assertJsonPath('data.status', 'confirmed')->json('manage_token');
    $this->postJson('/api/public/bookings/'.$code.'/verify', ['otp' => '1234'])->assertUnprocessable();
    $this->withHeader('X-Guest-Token', $token)->postJson('/api/public/bookings/'.$code.'/reschedule', ['starts_at' => '2026-10-27 14:00'])->assertOk();
    $this->getJson('/api/public/bookings/'.$code.'/reschedule-options?date=2026-10-28')->assertOk();
    $this->postJson('/api/public/bookings/'.$code.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('rejects catalog fields and unavailable or past durations for a rental booking', function () {
    $this->actingAs($this->owner)->postJson('/api/rental/bookings', [...$this->rentalData, 'duration_minutes' => 45])->assertUnprocessable();
    $this->postJson('/api/rental/bookings', [...$this->rentalData, 'starts_at' => '2026-10-27 12:30'])->assertUnprocessable();
    $this->postJson('/api/rental/bookings', [...$this->rentalData, 'starts_at' => '2026-10-25 10:00'])->assertUnprocessable();
    $this->postJson('/api/public/businesses/'.$this->rental->slug.'/bookings', [
        ...$this->rentalData, 'service_id' => 1, 'legal_accepted' => true, 'terms_version' => config('legal.terms_version'), 'privacy_version' => config('legal.privacy_version'),
    ])->assertUnprocessable()->assertJsonValidationErrors('service_id');
});

it('preserves the agreed price when rates change or an existing booking moves', function () {
    $booking = app(RentalBookingService::class)->create($this->rental, $this->rentalData);
    $this->rental->update(['rental_hourly_price' => 9000, 'rental_min_minutes' => 120]);
    $this->actingAs($this->owner)->patchJson('/api/rental/bookings/'.$booking->id, ['starts_at' => '2026-10-27 14:00'])->assertOk()->assertJsonPath('data.final_price', 3000);
});

it('records partial payments refunds and retries without duplicating money', function () {
    $booking = app(RentalBookingService::class)->create($this->rental, $this->rentalData);
    $this->actingAs($this->owner);
    $payload = ['amount' => 1000, 'operation' => 'payment', 'idempotency_key' => (string) Str::uuid()];
    $url = '/api/rental/bookings/'.$booking->id.'/payments';
    $this->postJson($url, $payload)->assertCreated();
    $this->postJson($url, $payload)->assertCreated();
    expect($booking->payments()->count())->toBe(1);
    $this->postJson($url, [...$payload, 'amount' => 3001, 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
    $this->getJson('/api/rental/summary?from=2026-10-27&to=2026-10-27')->assertOk()->assertJsonPath('data.booked_amount', 3000)->assertJsonPath('data.net_received', 1000)->assertJsonPath('data.outstanding_amount', 2000);
    $this->patchJson('/api/rental/bookings/'.$booking->id, ['status' => 'cancelled'])->assertOk();
    $this->getJson('/api/rental/summary?from=2026-10-27&to=2026-10-27')->assertOk()->assertJsonPath('data.refund_due', 1000)->assertJsonPath('data.booked_amount', 0);
    $this->postJson($url, ['amount' => 1000, 'operation' => 'refund', 'idempotency_key' => (string) Str::uuid()])->assertCreated();
    $this->getJson('/api/rental/summary?from=2026-10-27&to=2026-10-27')->assertOk()->assertJsonPath('data.net_received', 0)->assertJsonPath('data.refund_due', 0)->assertJsonPath('data.refunded_amount', 1000);
});

it('isolates rental bookings and financial records from other businesses', function () {
    $booking = app(RentalBookingService::class)->create($this->rental, $this->rentalData);
    $other = Business::factory()->billable()->create(['business_type' => 'rentals', 'vertical' => 'rentals']);
    $actor = User::factory()->create(['business_id' => $other->id, 'role' => 'owner']);
    $this->actingAs($actor)->patchJson('/api/rental/bookings/'.$booking->id, ['status' => 'cancelled'])->assertNotFound();
    $this->postJson('/api/rental/bookings/'.$booking->id.'/payments', ['amount' => 1, 'operation' => 'payment', 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
    $this->getJson('/api/rental/bookings?from=2026-10-27&to=2026-10-27')->assertOk()->assertJsonCount(0, 'data');
});

it('rejects inactive rentals and cannot convert another business to bypass its plan', function () {
    $this->actingAs($this->owner)->patchJson('/api/business/settings', ['vertical' => 'services'])->assertUnprocessable();
    $this->rental->subscription->update(['status' => 'expired']);
    $this->getJson('/api/public/businesses/'.$this->rental->slug.'/rental-availability?date=2026-10-27&duration_minutes=60')->assertForbidden();
});

it('uploads real gallery entries and protects media from cross-business changes', function () {
    Storage::fake('public');
    $file = UploadedFile::fake()->createWithContent('space.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6oU8AAAAASUVORK5CYII='));
    $response = $this->actingAs($this->owner)->postJson('/api/rental/gallery', ['file' => $file, 'caption' => 'Main hall'])->assertCreated()->assertJsonPath('data.kind', 'image');
    $id = $response->json('data.id');
    $this->getJson('/api/public/businesses/'.$this->rental->slug)->assertOk()->assertJsonPath('gallery.0.caption', 'Main hall');
    $this->patchJson('/api/rental/gallery/'.$id, ['caption' => 'Updated', 'sort_order' => 2])->assertOk();
    $otherBusiness = Business::factory()->billable()->create(['business_type' => 'rentals', 'vertical' => 'rentals']);
    $other = User::factory()->create(['business_id' => $otherBusiness->id, 'role' => 'owner']);
    $this->actingAs($other)->deleteJson('/api/rental/gallery/'.$id)->assertNotFound();
    expect(BusinessMedia::find($id))->not->toBeNull();
    $this->actingAs($this->owner)->deleteJson('/api/rental/gallery/'.$id)->assertOk();
    expect(BusinessMedia::find($id))->toBeNull();
});

it('does not block already reserved times or advertise catalogue entries for a rental', function () {
    app(RentalBookingService::class)->create($this->rental, $this->rentalData);
    $this->actingAs($this->owner)->postJson('/api/calendar/blocks', ['starts_at' => '2026-10-27 10:00', 'ends_at' => '2026-10-27 11:00'])->assertUnprocessable();
    $this->getJson('/api/public/businesses/'.$this->rental->slug.'/services')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/public/businesses/'.$this->rental->slug.'/staff')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/public/businesses?vertical=rentals')->assertOk()->assertJsonFragment(['slug' => $this->rental->slug]);
});

it('keeps overnight availability on the selected date and clips occupancy to the report day', function () {
    $this->rental->workingHours()->where('weekday', 2)->update(['start' => '22:00', 'end' => '02:00', 'break_start' => null, 'break_end' => null]);
    $this->rental->workingHours()->create(['weekday' => 3, 'is_closed' => true]);
    $rentals = app(RentalBookingService::class);
    $tuesday = $rentals->slots($this->rental, '2026-10-27', 60);
    expect(collect($tuesday)->every(fn ($slot) => str_starts_with($slot['starts_at'], '2026-10-27')))->toBeTrue();
    $wednesday = $rentals->slots($this->rental, '2026-10-28', 60);
    expect(array_column($wednesday, 'starts_at'))->toContain('2026-10-28 00:00', '2026-10-28 01:00');
    $rentals->create($this->rental, [...$this->rentalData, 'starts_at' => '2026-10-27 23:30', 'duration_minutes' => 90]);
    $this->actingAs($this->owner)->getJson('/api/rental/summary?from=2026-10-28&to=2026-10-28')
        ->assertOk()->assertJsonPath('data.available_minutes', 120)->assertJsonPath('data.occupancy_percent', 50);
});
