<?php

use App\Models\Admin;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use App\Notifications\ReviewRequestNotification;
use App\Services\GuestBookingAccessService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2030-03-04 12:00:00', 'UTC'));
    Mail::fake();
    config(['services.telegram.enabled' => false]);
    $this->business = Business::factory()->billable()->create([
        'name' => 'Review Salon', 'slug' => 'review-salon', 'timezone' => 'UTC', 'is_onboarding_completed' => true,
        'is_public' => true, 'is_public_profile_enabled' => true, 'is_marketplace_visible' => true,
        'work_start' => '09:00:00', 'work_end' => '18:00:00',
    ]);
    $this->owner = User::factory()->create(['business_id' => $this->business->id, 'role' => 'owner', 'is_active' => true, 'is_bookable' => false, 'show_in_public_team' => false]);
    $this->staff = User::factory()->staff($this->business->id)->create(['name' => 'Narine Hakobyan', 'is_active' => true, 'is_bookable' => true, 'show_in_public_team' => true]);
    $this->service = Service::factory()->create(['business_id' => $this->business->id, 'name' => 'Gel polish', 'duration_minutes' => 60, 'is_active' => true]);
    $this->client = Client::factory()->create(['business_id' => $this->business->id, 'email' => 'ani@example.com', 'name' => 'Ani Martirosyan']);
});

afterEach(fn () => Carbon::setTestNow());

function reviewBooking(array $overrides = []): Booking
{
    static $n = 0;
    $n++;

    return Booking::query()->create(array_merge([
        'booking_code' => 'REV'.$n, 'business_id' => test()->business->id, 'service_id' => test()->service->id,
        'staff_id' => test()->staff->id, 'client_id' => test()->client->id, 'client_name' => 'Ani Martirosyan',
        'client_phone' => '+37499123456', 'client_email' => 'ani@example.com',
        'starts_at' => now()->subHours(3), 'ends_at' => now()->subHours(2), 'status' => 'confirmed', 'currency' => 'AMD',
        'phone_verified_at' => now()->subDay(),
    ], $overrides));
}

function guestToken(Booking $booking): string
{
    return app(GuestBookingAccessService::class)->issue($booking);
}

it('lets a guest review a finished visit once and shows it on the public page', function () {
    $booking = reviewBooking();
    $token = guestToken($booking);

    $this->getJson('/api/public/bookings/'.$booking->booking_code.'/review', ['X-Guest-Token' => $token])->assertOk()
        ->assertJsonPath('data.eligible', true)->assertJsonPath('data.booking.service.name', 'Gel polish');
    $this->getJson('/api/public/bookings/'.$booking->booking_code.'/review')->assertUnauthorized();
    $this->postJson('/api/public/bookings/'.$booking->booking_code.'/review', ['rating' => 6], ['X-Guest-Token' => $token])->assertUnprocessable();
    $this->postJson('/api/public/bookings/'.$booking->booking_code.'/review', ['rating' => 5, 'comment' => '  Great work  '], ['X-Guest-Token' => $token])
        ->assertCreated()->assertJsonPath('data.rating', 5)->assertJsonPath('data.comment', 'Great work');
    $this->postJson('/api/public/bookings/'.$booking->booking_code.'/review', ['rating' => 1], ['X-Guest-Token' => $token])
        ->assertUnprocessable()->assertJsonValidationErrors('rating');

    $review = Review::sole();
    expect($review->author_name)->toBe('Ani M.')->and($review->staff_id)->toBe($this->staff->id)->and($review->service_id)->toBe($this->service->id);

    $this->getJson('/api/public/businesses/review-salon/reviews')->assertOk()
        ->assertJsonPath('data.0.author_name', 'Ani M.')->assertJsonPath('data.0.staff.name', 'Narine Hakobyan')
        ->assertJsonMissingPath('data.0.client_id')->assertJsonPath('meta.summary.count', 1)
        // Fewer than three reviews: the average stays hidden ("New").
        ->assertJsonPath('meta.summary.shown', false)->assertJsonPath('meta.summary.average', null);
    $this->getJson('/api/public/bookings/'.$booking->booking_code, ['X-Guest-Token' => $token])->assertOk()
        ->assertJsonPath('data.primary_booking.review_state.reason', 'already_reviewed')
        ->assertJsonMissingPath('data.primary_booking.review_token_hash');
});

it('refuses reviews for unfinished, cancelled, no-show and expired visits', function () {
    $cases = [
        'not_finished' => reviewBooking(['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]),
        'pending' => reviewBooking(['status' => 'pending']),
        'not_visited' => reviewBooking(['status' => 'cancelled']),
        'no_show' => reviewBooking(['status' => 'no_show']),
        'expired' => reviewBooking(['status' => 'done', 'starts_at' => now()->subDays(32), 'ends_at' => now()->subDays(31)]),
    ];
    foreach ($cases as $booking) {
        $this->postJson('/api/public/bookings/'.$booking->booking_code.'/review', ['rating' => 4], ['X-Guest-Token' => guestToken($booking)])
            ->assertUnprocessable();
    }
    // A visit the business marked done can be reviewed even before its planned end.
    $done = reviewBooking(['status' => 'done', 'starts_at' => now()->subMinutes(10), 'ends_at' => now()->addMinutes(50)]);
    $this->postJson('/api/public/bookings/'.$done->booking_code.'/review', ['rating' => 4], ['X-Guest-Token' => guestToken($done)])->assertCreated();
    expect(Review::count())->toBe(1);
});

it('accepts the email review token and only for its own booking group', function () {
    $booking = reviewBooking();
    $token = app(\App\Services\ReviewService::class)->issueReviewToken($booking);
    $other = reviewBooking(['client_id' => Client::factory()->create(['business_id' => $this->business->id])->id]);

    $this->postJson('/api/public/bookings/'.$booking->booking_code.'/review', ['rating' => 5], ['X-Review-Token' => 'wrong'])->assertUnauthorized();
    $this->postJson('/api/public/bookings/'.$booking->booking_code.'/review', ['rating' => 5, 'booking_id' => $other->id], ['X-Review-Token' => $token])->assertNotFound();
    $this->postJson('/api/public/bookings/'.$booking->booking_code.'/review', ['rating' => 5, 'review_token' => $token])->assertCreated();
});

it('lets a signed-in client review an owned visit from the cabinet', function () {
    $account = ClientAccount::query()->create(['name' => 'Ani', 'email' => 'ani@example.com', 'phone' => '+37499123456', 'password' => 'A-client-password-123', 'email_verified_at' => now()]);
    $this->client->update(['client_account_id' => $account->id]);
    $booking = reviewBooking(['starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour()]);
    Sanctum::actingAs($account);

    $this->getJson('/api/client/cabinet/bookings')->assertOk()->assertJsonPath('data.past.0.review_state.eligible', true);
    $this->postJson('/api/client/cabinet/bookings/'.$booking->id.'/review', ['rating' => 3])->assertCreated();
    $this->getJson('/api/client/cabinet/bookings')->assertOk()->assertJsonPath('data.past.0.review_state.review.rating', 3);

    $stranger = reviewBooking(['client_id' => Client::factory()->create(['business_id' => $this->business->id, 'email' => 'x@example.com'])->id]);
    $this->postJson('/api/client/cabinet/bookings/'.$stranger->id.'/review', ['rating' => 3])->assertNotFound();
});

it('shows averages from three reviews and per specialist, lets the business reply and report, and the admin hide', function () {
    foreach ([5, 4, 3] as $rating) {
        $booking = reviewBooking();
        $this->postJson('/api/public/bookings/'.$booking->booking_code.'/review', ['rating' => $rating], ['X-Guest-Token' => guestToken($booking)])->assertCreated();
    }
    $this->getJson('/api/public/businesses/review-salon')->assertOk()
        ->assertJsonPath('rating.count', 3)->assertJsonPath('rating.shown', true)->assertJsonPath('rating.average', 4);
    $staffRow = collect($this->getJson('/api/public/businesses/review-salon/staff')->json('data'))->firstWhere('id', $this->staff->id);
    expect($staffRow['rating']['count'])->toBe(3)->and((float) $staffRow['rating']['average'])->toBe(4.0);

    $review = Review::query()->where('rating', 3)->sole();
    $this->actingAs($this->staff)->getJson('/api/reviews')->assertForbidden();
    $this->actingAs($this->owner)->getJson('/api/reviews')->assertOk()->assertJsonCount(3, 'data');
    $this->putJson('/api/reviews/'.$review->id.'/reply', ['reply' => 'Thank you!'])->assertOk()->assertJsonPath('data.reply', 'Thank you!');
    $this->postJson('/api/reviews/'.$review->id.'/report', ['reason' => 'Not our client'])->assertOk();
    expect(Review::find($review->id)->hidden_at)->toBeNull();

    $other = Business::factory()->billable()->create(['is_onboarding_completed' => true]);
    $otherOwner = User::factory()->create(['business_id' => $other->id, 'role' => 'owner', 'is_active' => true]);
    $this->actingAs($otherOwner)->putJson('/api/reviews/'.$review->id.'/reply', ['reply' => 'x'])->assertNotFound();

    $admin = Admin::query()->create(['name' => 'Vizit Admin', 'email' => 'mod@vizit.am', 'password' => Hash::make('A-secure-passphrase-123'), 'role' => Admin::ROLE_SUPER_ADMIN, 'is_active' => true, 'email_verified_at' => now()]);
    Sanctum::actingAs($admin);
    $this->getJson('/api/admin/reviews')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.report_reason', 'Not our client');
    $this->patchJson('/api/admin/reviews/'.$review->id, ['hidden' => true])->assertOk()->assertJsonPath('data.hidden', true);

    $this->getJson('/api/public/businesses/review-salon/reviews')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.summary.shown', false);
});

it('sends review request emails only when switched on, once per visit', function () {
    Notification::fake();
    $booking = reviewBooking();
    $this->artisan('reviews:send-requests')->assertSuccessful();
    Notification::assertNothingSent();

    config(['reviews.request_emails' => true]);
    $this->artisan('reviews:send-requests')->assertSuccessful();
    $this->artisan('reviews:send-requests')->assertSuccessful();
    Notification::assertSentOnDemandTimes(ReviewRequestNotification::class, 1);
    expect($booking->fresh()->review_requested_at)->not->toBeNull()->and($booking->fresh()->review_token_hash)->not->toBeNull();
});

it('finds businesses by specialist name and by free time on a chosen day', function () {
    $busy = Business::factory()->billable()->create([
        'name' => 'Busy Salon', 'slug' => 'busy-salon', 'timezone' => 'UTC', 'is_onboarding_completed' => true,
        'is_public' => true, 'is_public_profile_enabled' => true, 'is_marketplace_visible' => true,
    ]);
    User::factory()->staff($busy->id)->create(['name' => 'Gor Petrosyan', 'is_active' => true, 'is_bookable' => true]);

    $this->getJson('/api/public/businesses?search=Narine')->assertOk()
        ->assertJsonPath('data.0.slug', 'review-salon')->assertJsonPath('data.0.matched_staff.0.name', 'Narine Hakobyan')
        ->assertJsonCount(1, 'data');

    // Tuesday 2030-03-05: Review Salon has a bookable specialist and a service; Busy Salon has no service.
    $response = $this->getJson('/api/public/businesses?date=2030-03-05')->assertOk();
    $slugs = collect($response->json('data'))->pluck('slug');
    expect($slugs)->toContain('review-salon')->not->toContain('busy-salon');
    $salon = collect($response->json('data'))->firstWhere('slug', 'review-salon');
    expect($salon['free_slots'])->not->toBeEmpty()->and($salon['free_slots'][0]['service_id'])->toBe($this->service->id);

    // Work ends at 18:00, so a 60-minute visit fits in the evening only at 17:00; booking 17:00 leaves no evening time.
    $evening = collect($this->getJson('/api/public/businesses?date=2030-03-05&part=evening')->json('data'))->firstWhere('slug', 'review-salon');
    expect(collect($evening['free_slots'])->pluck('time')->all())->toBe(['17:00']);
    reviewBooking(['status' => 'confirmed', 'starts_at' => '2030-03-05 17:00:00', 'ends_at' => '2030-03-05 18:00:00']);
    \Illuminate\Support\Facades\Cache::flush();
    $evening = collect($this->getJson('/api/public/businesses?date=2030-03-05&part=evening')->json('data'))->firstWhere('slug', 'review-salon');
    expect($evening)->toBeNull();
    $this->getJson('/api/public/businesses?date=2031-01-01')->assertUnprocessable();
    $this->getJson('/api/public/businesses?date=2030-03-05&part=night')->assertUnprocessable();
});
