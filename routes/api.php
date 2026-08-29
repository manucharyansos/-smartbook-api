<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\Public\PublicBookingController;
use App\Http\Controllers\Api\Public\PublicCategoryController;
use App\Http\Controllers\Api\Public\SeoController;

use App\Http\Controllers\Api\FeaturesController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\BusinessOnboardingController;
use App\Http\Controllers\Api\BusinessSettingsController;
use App\Http\Controllers\Api\TelegramConnectionController;
use App\Http\Controllers\Api\TelegramWebhookController;

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\CalendarBlockController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ClientActivityController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\LoyaltyController;
use App\Http\Controllers\Api\GiftCardController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\WaitlistController;
use App\Http\Controllers\Api\MarketingCampaignController;

use App\Http\Controllers\Api\BillingInvoiceController;

use App\Http\Controllers\Api\PlanController as PublicPlanController;

use App\Http\Controllers\Api\ContactRequestController;

use App\Http\Controllers\Api\ClientAuthController;
use App\Http\Controllers\Api\ClientCabinetController;
use App\Http\Controllers\Api\SocialAuthController;

use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\AdminAnalyticsController;
use App\Http\Controllers\Admin\AdminManagementController;
use App\Http\Controllers\Admin\BusinessManagementController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Admin\LogController;

use App\Http\Controllers\Api\Admin\BillingAdminController;
use App\Http\Controllers\Api\Admin\InvoiceAdminController;
use App\Http\Controllers\Api\AdminMetricsController;

use App\Http\Controllers\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Api\BusinessTypeController;
use App\Http\Controllers\Api\BillingSubscriptionController;
use App\Http\Controllers\Api\BillingMeController;
use App\Http\Controllers\Api\BillingPaymentController;
use App\Http\Controllers\Api\BillingWebhookController;

/*
|--------------------------------------------------------------------------
| Health
|--------------------------------------------------------------------------
*/
Route::get('/health', fn () => response()->json(['ok' => true]));
Route::get('/media/file/{path}', [MediaController::class, 'show'])
    ->where('path', '.*')
    ->name('media.show');

/*
|--------------------------------------------------------------------------
| API v1 — Public Discovery / Booking aliases
|--------------------------------------------------------------------------
*/
Route::prefix('v1/public')->group(function () {
    Route::get('/categories', [PublicCategoryController::class, 'index']);
    Route::get('/businesses', [PublicBookingController::class, 'index']);
    Route::get('/businesses/map', [PublicBookingController::class, 'map']);
    Route::get('/businesses/{slug}', [PublicBookingController::class, 'business']);
    Route::get('/businesses/{slug}/services', [PublicBookingController::class, 'services']);
    Route::get('/businesses/{slug}/staff', [PublicBookingController::class, 'staff']);
    Route::get('/businesses/{slug}/availability', [PublicBookingController::class, 'availability']);
    Route::get('/businesses/{slug}/availability/multi', [PublicBookingController::class, 'availabilityMulti']);
    Route::post('/businesses/{slug}/bookings', [PublicBookingController::class, 'store'])->middleware('throttle:20,1');
    Route::post('/businesses/{slug}/bookings/multi', [PublicBookingController::class, 'storeMulti'])->middleware('throttle:20,1');
    Route::post('/businesses/{slug}/bookings/lines', [PublicBookingController::class, 'storeLines'])->middleware('throttle:20,1');
    Route::post('/businesses/{slug}/waitlist', [WaitlistController::class, 'publicStore'])->middleware('throttle:10,1');
    Route::get('/businesses/{slug}/waitlist/offers/{entry}', [WaitlistController::class, 'publicOffer'])->middleware('throttle:30,1');
    Route::post('/businesses/{slug}/waitlist/offers/{entry}/accept', [WaitlistController::class, 'publicAccept'])->middleware('throttle:10,1');
    Route::get('/bookings/{code}', [PublicBookingController::class, 'show']);
    Route::post('/bookings/{code}/verify', [PublicBookingController::class, 'verifyPhone'])->middleware('throttle:20,1');
    Route::post('/bookings/{code}/resend', [PublicBookingController::class, 'resendCode'])->middleware('throttle:20,1');
    Route::post('/bookings/{code}/telegram-link', [PublicBookingController::class, 'telegramLink'])->middleware('throttle:10,1');
    Route::get('/bookings/{code}/reschedule-options', [PublicBookingController::class, 'rescheduleOptions'])->middleware('throttle:60,1');
    Route::post('/bookings/{code}/reschedule', [PublicBookingController::class, 'reschedule'])->middleware('throttle:20,1');
    Route::post('/bookings/{code}/cancel', [PublicBookingController::class, 'cancel'])->middleware('throttle:20,1');
});

/*
|--------------------------------------------------------------------------
| Public Booking (Guest)
|--------------------------------------------------------------------------
*/
Route::prefix('public')->group(function () {
    Route::get('/categories', [PublicCategoryController::class, 'index']);
    Route::get('/businesses/map', [PublicBookingController::class, 'map']);
    Route::get('/seo/meta', [SeoController::class, 'meta']);
    Route::get('/seo/sitemap.xml', [SeoController::class, 'sitemap']);
    Route::get('/businesses/{slug}', [PublicBookingController::class, 'business']);
    Route::get('/businesses/{slug}/services', [PublicBookingController::class, 'services']);
    Route::get('/businesses/{slug}/staff', [PublicBookingController::class, 'staff']);
    Route::get('/businesses/{slug}/availability', [PublicBookingController::class, 'availability']);
    // Multi-service availability for public bookings
    Route::get('/businesses/{slug}/availability/multi', [PublicBookingController::class, 'availabilityMulti']);
    Route::post('/businesses/{slug}/bookings', [PublicBookingController::class, 'store'])->middleware('throttle:20,1');
    // Multi-service booking for public (sequential multi-service booking)
    Route::post('/businesses/{slug}/bookings/multi', [PublicBookingController::class, 'storeMulti'])->middleware('throttle:20,1');
    // Multi-line bookings where each service can have its own staff/time
    Route::post('/businesses/{slug}/bookings/lines', [PublicBookingController::class, 'storeLines'])->middleware('throttle:20,1');
    Route::post('/businesses/{slug}/waitlist', [WaitlistController::class, 'publicStore'])->middleware('throttle:10,1');
    Route::get('/businesses/{slug}/waitlist/offers/{entry}', [WaitlistController::class, 'publicOffer'])->middleware('throttle:30,1');
    Route::post('/businesses/{slug}/waitlist/offers/{entry}/accept', [WaitlistController::class, 'publicAccept'])->middleware('throttle:10,1');
    Route::get('/bookings/{code}', [PublicBookingController::class, 'show']);
    Route::post('/bookings/{code}/verify', [PublicBookingController::class, 'verifyPhone'])->middleware('throttle:20,1');
    Route::post('/bookings/{code}/resend', [PublicBookingController::class, 'resendCode'])->middleware('throttle:20,1');
    Route::post('/bookings/{code}/telegram-link', [PublicBookingController::class, 'telegramLink'])->middleware('throttle:10,1');
    Route::get('/bookings/{code}/reschedule-options', [PublicBookingController::class, 'rescheduleOptions'])->middleware('throttle:60,1');
    Route::post('/bookings/{code}/reschedule', [PublicBookingController::class, 'reschedule'])->middleware('throttle:20,1');
    Route::post('/bookings/{code}/cancel', [PublicBookingController::class, 'cancel'])->middleware('throttle:20,1');
    Route::get('/businesses', [PublicBookingController::class, 'index']);
});

Route::post('/public/marketing/unsubscribe/{delivery}', [MarketingCampaignController::class, 'unsubscribe'])
    ->middleware('throttle:10,1');

/*
|--------------------------------------------------------------------------
| Public Contact Requests (Guest)
|--------------------------------------------------------------------------
*/
Route::post('/contact-requests', [ContactRequestController::class, 'store'])->middleware('throttle:10,1');

/*
|--------------------------------------------------------------------------
| Public Availability (Guest)
|--------------------------------------------------------------------------
*/
Route::get('/availability', [AvailabilityController::class, 'availability']);

/*
|--------------------------------------------------------------------------
| Public Plans (Landing)
|--------------------------------------------------------------------------
*/
Route::get('/plans', [PublicPlanController::class, 'index']);

/*
|--------------------------------------------------------------------------
| Public Payment Webhooks
|--------------------------------------------------------------------------
*/
Route::post('/webhooks/payments/idbank', [BillingWebhookController::class, 'idbank']);
Route::post('/webhooks/payments/idbank/mock-complete', [BillingWebhookController::class, 'hostedMockComplete']);
Route::post('/webhooks/telegram', TelegramWebhookController::class)->middleware('throttle:120,1');

/*
|--------------------------------------------------------------------------
| Auth
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    // Password reset (public)
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});


/*
|--------------------------------------------------------------------------
| Client Auth & Cabinet
|--------------------------------------------------------------------------
*/
Route::prefix('client/auth')->group(function () {
    Route::post('register', [ClientAuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('login', [ClientAuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('forgot-password', [ClientAuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('reset-password', [ClientAuthController::class, 'resetPassword'])->middleware('throttle:10,1');
    Route::get('email/verify/{id}/{hash}', [ClientAuthController::class, 'verifyEmail'])
        ->middleware(['auth:sanctum', 'signed', 'throttle:10,1'])
        ->name('verification.verify');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [ClientAuthController::class, 'me']);
        Route::post('logout', [ClientAuthController::class, 'logout']);
        Route::post('email/verification-notification', [ClientAuthController::class, 'resendVerification'])
            ->middleware('throttle:3,1');
    });
});

Route::prefix('client')->middleware('auth:sanctum')->group(function () {
    Route::get('cabinet/bookings', [ClientCabinetController::class, 'bookings']);
});

/*
|--------------------------------------------------------------------------
| Social Auth Redirect Hooks
|--------------------------------------------------------------------------
*/
Route::get('/auth/social/providers', [SocialAuthController::class, 'providers'])
    ->middleware('throttle:60,1')
    ->name('auth.social.providers');
Route::get('/auth/social/{provider}/redirect', [SocialAuthController::class, 'redirect'])
    ->middleware('throttle:20,1')
    ->name('auth.social.redirect');
Route::get('/auth/social/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->middleware('throttle:30,1')
    ->name('auth.social.callback');
Route::post('/auth/social/exchange', [SocialAuthController::class, 'exchange'])
    ->middleware('throttle:20,1')
    ->name('auth.social.exchange');

/*
|--------------------------------------------------------------------------
| ✅ Protected Business Routes
| - /features: available right after login (even before onboarding)
| - onboarding routes: allowed BEFORE ensure.onboarded
| - main app routes: require ensure.onboarded + ensure.billable
|--------------------------------------------------------------------------
*/

/**
 * ✅ Available immediately after login (even before onboarding)
 */
Route::middleware(['auth:sanctum', 'ensure.business'])->group(function () {
    Route::get('/features', [FeaturesController::class, 'index'])->name('features.index');
    Route::post('/media/upload', [MediaController::class, 'upload'])->name('media.upload');
    Route::get('/telegram/connection', [TelegramConnectionController::class, 'show'])->name('telegram.connection.show');
    Route::post('/telegram/connection', [TelegramConnectionController::class, 'store'])->middleware('throttle:10,1')->name('telegram.connection.store');
    Route::delete('/telegram/connection', [TelegramConnectionController::class, 'destroy'])->name('telegram.connection.destroy');
       Route::get('/business-types', [BusinessTypeController::class, 'show'])
        ->middleware('role:owner,manager')
        ->name('business.types.show');
});

/**
 * ✅ ONBOARDING FLOW (allowed BEFORE ensure.onboarded)
 * works while business->is_onboarding_completed = false
 */
Route::middleware(['auth:sanctum', 'ensure.business', 'role:owner,manager'])->group(function () {

    // Step 0: Create first service
    Route::post('/services', [ServiceController::class, 'store'])->name('services.store');

    // Step 1: Create first staff (seat limit applies)
    Route::post('/staff', [StaffController::class, 'store'])
        ->middleware('ensure.seat')
        ->name('staff.store');

    // Step 2: Read/save business settings while onboarding is still incomplete
    Route::get('/business/settings', [BusinessSettingsController::class, 'show'])
        ->name('business.settings.show.preonboarding');
    Route::patch('/business/settings', [BusinessSettingsController::class, 'update'])
        ->name('business.settings.update.preonboarding');
    Route::post('/business/locations', [BusinessSettingsController::class, 'storeLocation'])
        ->name('business.locations.store.preonboarding');
    Route::patch('/business/locations/{location}', [BusinessSettingsController::class, 'updateLocation'])
        ->name('business.locations.update.preonboarding');
    Route::delete('/business/locations/{location}', [BusinessSettingsController::class, 'destroyLocation'])
        ->name('business.locations.destroy.preonboarding');

    // Onboarding status helper
    Route::get('/business/onboarding-status', [BusinessOnboardingController::class, 'status'])
        ->name('business.onboarding-status');

    // Finish onboarding
    Route::post('/business/complete-onboarding', [BusinessOnboardingController::class, 'complete'])
        ->name('business.complete-onboarding');
});

/**
 * ✅ BILLING RECOVERY (must work even when subscription is inactive)
 */
Route::middleware(['auth:sanctum', 'ensure.business', 'ensure.onboarded', 'role:owner'])->group(function () {
    Route::get('/billing/me', [BillingMeController::class, 'show'])->name('billing.me');
    Route::get('/billing/invoices', [BillingInvoiceController::class, 'index'])->name('billing.invoices.index');
    Route::post('/billing/upgrade-request', [BillingInvoiceController::class, 'requestUpgrade'])->name('billing.upgrade.request');
    Route::post('/billing/invoices/{invoice}/cancel', [BillingInvoiceController::class, 'cancel'])->name('billing.invoices.cancel');
    Route::post('/billing/checkout-session', [BillingPaymentController::class, 'createCheckout'])->name('billing.checkout');
    Route::get('/billing/invoices/{invoice}/payment-status', [BillingPaymentController::class, 'status'])->name('billing.payment.status');
    Route::post('/billing/transactions/{transaction}/mock-success', [BillingWebhookController::class, 'mockSuccess'])->name('billing.mock.success');
    Route::post('/billing/pause', [BillingSubscriptionController::class, 'pause'])->name('billing.pause');
    Route::post('/billing/resume', [BillingSubscriptionController::class, 'resume'])->name('billing.resume');
    Route::post('/billing/cancel-subscription', [BillingSubscriptionController::class, 'cancel'])->name('billing.cancel');
});

/**
 * ✅ MAIN APP (only AFTER onboarding is completed + business is billable)
 */
Route::middleware(['auth:sanctum', 'ensure.business', 'ensure.onboarded', 'ensure.billable'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Business Settings (Owner/Manager)  ✅ GET + PATCH
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:owner,manager')->group(function () {
        Route::get('/business/settings', [BusinessSettingsController::class, 'show'])->name('business.settings.show');
        Route::patch('/business/settings', [BusinessSettingsController::class, 'update'])->name('business.settings.update');
        Route::post('/business/locations', [BusinessSettingsController::class, 'storeLocation'])->name('business.locations.store');
        Route::patch('/business/locations/{location}', [BusinessSettingsController::class, 'updateLocation'])->name('business.locations.update');
        Route::delete('/business/locations/{location}', [BusinessSettingsController::class, 'destroyLocation'])->name('business.locations.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Staff (Owner/Manager)
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:owner,manager')->group(function () {
        Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
        Route::patch('/staff/{user}', [StaffController::class, 'update'])->name('staff.update');
        Route::patch('/staff/{user}/deactivate', [StaffController::class, 'deactivate'])->name('staff.deactivate');
        Route::patch('/staff/{user}/activate', [StaffController::class, 'activate'])
            ->middleware('ensure.seat')
            ->name('staff.activate');
    });

    /*
    |--------------------------------------------------------------------------
    | Services (Owner/Manager) - index/update/delete AFTER onboarding
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:owner,manager')->group(function () {
        Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
        Route::get('/services/{service}', [ServiceController::class, 'show'])->name('services.show');
        Route::put('/services/{service}', [ServiceController::class, 'update'])->name('services.update');
        Route::delete('/services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Bookings
    |--------------------------------------------------------------------------
    */
    Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::post('/bookings', [BookingController::class, 'store'])->name('bookings.store');
    Route::put('/bookings/{booking}', [BookingController::class, 'update'])->name('bookings.update');
    Route::patch('/bookings/{booking}/done', [BookingController::class, 'done'])->name('bookings.done');
    Route::patch('/bookings/{booking}/no-show', [BookingController::class, 'noShow'])->name('bookings.no_show');
    Route::patch('/bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
    Route::patch('/bookings/{booking}/recurrence/cancel', [BookingController::class, 'cancelRecurrence'])->name('bookings.recurrence.cancel');
    Route::patch('/bookings/{booking}/confirm', [BookingController::class, 'confirm'])->name('bookings.confirm');
    Route::patch('/bookings/{booking}/time', [BookingController::class, 'updateTime'])->name('bookings.time');
    Route::post('/bookings/multi', [BookingController::class, 'storeMulti'])->name('bookings.storeMulti');
    Route::post('/bookings/lines', [BookingController::class, 'storeLines'])->name('bookings.storeLines');

    Route::middleware(['ensure.feature:waitlist', 'role:owner,manager'])->group(function () {
        Route::get('/waitlist', [WaitlistController::class, 'index'])->name('waitlist.index');
        Route::post('/waitlist/{entry}/offer', [WaitlistController::class, 'offer'])->name('waitlist.offer');
        Route::patch('/waitlist/{entry}', [WaitlistController::class, 'update'])->name('waitlist.update');
    });

    Route::middleware(['ensure.feature:marketing', 'role:owner,manager'])->group(function () {
        Route::get('/marketing/campaigns', [MarketingCampaignController::class, 'index'])->name('marketing.campaigns.index');
        Route::post('/marketing/campaigns', [MarketingCampaignController::class, 'store'])->name('marketing.campaigns.store');
        Route::put('/marketing/campaigns/{campaign}', [MarketingCampaignController::class, 'update'])->name('marketing.campaigns.update');
        Route::get('/marketing/campaigns/{campaign}/preview', [MarketingCampaignController::class, 'preview'])->name('marketing.campaigns.preview');
        Route::post('/marketing/campaigns/{campaign}/send', [MarketingCampaignController::class, 'send'])->name('marketing.campaigns.send');
        Route::post('/marketing/campaigns/{campaign}/cancel', [MarketingCampaignController::class, 'cancel'])->name('marketing.campaigns.cancel');
        Route::get('/marketing/campaigns/{campaign}/deliveries', [MarketingCampaignController::class, 'deliveries'])->name('marketing.campaigns.deliveries');
    });
    /*
    |--------------------------------------------------------------------------
    | Calendar
    |--------------------------------------------------------------------------
    */
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');

    Route::middleware(['ensure.feature:blocks', 'role:owner,manager'])->group(function () {
        Route::get('/calendar/blocks', [CalendarBlockController::class, 'index'])->name('calendar.blocks.index');
        Route::post('/calendar/blocks', [CalendarBlockController::class, 'store'])->name('calendar.blocks.store');
        Route::delete('/calendar/blocks/{block}', [CalendarBlockController::class, 'destroy'])->name('calendar.blocks.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Schedule  ✅ required by frontend (/api/schedule)
    |--------------------------------------------------------------------------
    */
    Route::get('/schedule', [ScheduleController::class, 'show'])->name('schedule.show');

    Route::put('/schedule', [ScheduleController::class, 'update'])
        ->middleware('role:owner,manager')
        ->name('schedule.update');

    Route::get('/staff/{user}/schedule', [ScheduleController::class, 'showStaff'])->name('schedule.staff.show');

    Route::put('/staff/{user}/schedule', [ScheduleController::class, 'updateStaff'])
        ->middleware('role:owner,manager')
        ->name('schedule.staff.update');

    Route::get('/exceptions', [ScheduleController::class, 'listExceptions'])->name('schedule.exceptions.index');

    Route::post('/exceptions', [ScheduleController::class, 'createException'])
        ->middleware('role:owner,manager')
        ->name('schedule.exceptions.store');

    Route::delete('/exceptions/{id}', [ScheduleController::class, 'deleteException'])
        ->middleware('role:owner,manager')
        ->name('schedule.exceptions.destroy');

    /*
    |--------------------------------------------------------------------------
    | Stats / Dashboard
    |--------------------------------------------------------------------------
    */
    Route::get('/stats', [StatsController::class, 'index'])->name('stats.index');
    Route::get('/dashboard', [DashboardController::class, 'show'])->name('dashboard.show');

    /*
    |--------------------------------------------------------------------------
    | Analytics (Summary + Detailed) ✅ matches your AnalyticsController
    |--------------------------------------------------------------------------
    */
    Route::middleware(['ensure.feature:analytics', 'role:owner,manager'])->group(function () {
        Route::get('/analytics', [AnalyticsController::class, 'summary'])->name('analytics.summary');

        Route::get('/analytics/overview', [AnalyticsController::class, 'overview'])->name('analytics.overview');
        Route::get('/analytics/revenue', [AnalyticsController::class, 'revenue'])->name('analytics.revenue');
        Route::get('/analytics/services', [AnalyticsController::class, 'services'])->name('analytics.services');
        Route::get('/analytics/staff', [AnalyticsController::class, 'staff'])->name('analytics.staff');
        Route::get('/analytics/sources', [AnalyticsController::class, 'sources'])->name('analytics.sources');
        Route::get('/analytics/clients', [AnalyticsController::class, 'clients'])->name('analytics.clients');
    });

    /*
    |--------------------------------------------------------------------------
    | Rooms
    |--------------------------------------------------------------------------
    */
    Route::middleware(['ensure.feature:rooms', 'role:owner,manager'])->group(function () {
        Route::get('/rooms', [RoomController::class, 'index'])->name('rooms.index');
        Route::post('/rooms', [RoomController::class, 'store'])->name('rooms.store');
        Route::put('/rooms/{room}', [RoomController::class, 'update'])->name('rooms.update');
        Route::delete('/rooms/{room}', [RoomController::class, 'destroy'])->name('rooms.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Tasks
    |--------------------------------------------------------------------------
    */
    Route::middleware('ensure.feature:tasks')->group(function () {
        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');

        Route::middleware('role:owner,manager')->group(function () {
            Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
            Route::put('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
            Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.patch');
            Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
        });

        Route::middleware('role:staff')->group(function () {
            Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.patch.staff');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Clients
    |--------------------------------------------------------------------------
    */
    Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
    Route::get('/clients/{client}', [ClientController::class, 'show'])->name('clients.show');
    Route::get('/clients/{client}/bookings', [ClientController::class, 'bookings'])->name('clients.bookings');

    Route::middleware('role:owner,manager')->group(function () {
        Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
        Route::put('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');
        Route::post('/clients/{client}/notes', [ClientActivityController::class, 'storeNote'])->name('clients.notes.store');
        Route::put('/clients/{client}/notes/{note}', [ClientActivityController::class, 'updateNote'])->name('clients.notes.update');
        Route::delete('/clients/{client}/notes/{note}', [ClientActivityController::class, 'destroyNote'])->name('clients.notes.destroy');
        Route::post('/clients/{client}/reminders', [ClientActivityController::class, 'storeReminder'])->name('clients.reminders.store');
        Route::put('/clients/{client}/reminders/{reminder}', [ClientActivityController::class, 'updateReminder'])->name('clients.reminders.update');
        Route::delete('/clients/{client}/reminders/{reminder}', [ClientActivityController::class, 'destroyReminder'])->name('clients.reminders.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Loyalty
    |--------------------------------------------------------------------------
    */
    Route::middleware(['ensure.feature:loyalty', 'role:owner,manager'])->group(function () {
        Route::get('/loyalty/program', [LoyaltyController::class, 'program'])->name('loyalty.program.show');
        Route::put('/loyalty/program', [LoyaltyController::class, 'updateProgram'])->name('loyalty.program.update');
        Route::get('/loyalty/clients', [LoyaltyController::class, 'clients'])->name('loyalty.clients.index');
        Route::get('/loyalty/summary', [LoyaltyController::class, 'summary'])->name('loyalty.summary');
        Route::get('/loyalty/clients/{client}/ledger', [LoyaltyController::class, 'clientLedger'])->name('loyalty.clients.ledger');
        Route::post('/loyalty/preview', [LoyaltyController::class, 'preview'])->name('loyalty.preview');
        Route::post('/loyalty/clients/{client}/adjust', [LoyaltyController::class, 'adjust'])->name('loyalty.clients.adjust');
    });

    /*
    |--------------------------------------------------------------------------
    | Gift Cards
    |--------------------------------------------------------------------------
    */
    Route::middleware(['ensure.feature:gift_cards', 'role:owner,manager'])->group(function () {
        Route::get('/gift-cards', [GiftCardController::class, 'index'])->name('giftcards.index');
        Route::post('/gift-cards', [GiftCardController::class, 'store'])->name('giftcards.store');
        Route::post('/gift-cards/lookup', [GiftCardController::class, 'lookup'])->name('giftcards.lookup');
        Route::get('/gift-cards/{giftCard}', [GiftCardController::class, 'show'])->name('giftcards.show');
        Route::get('/gift-cards/{giftCard}/ledger', [GiftCardController::class, 'ledger'])->name('giftcards.ledger');
        Route::put('/gift-cards/{giftCard}', [GiftCardController::class, 'update'])->name('giftcards.update');
        Route::patch('/gift-cards/{giftCard}/redeem', [GiftCardController::class, 'redeem'])->name('giftcards.redeem');
        Route::patch('/gift-cards/{giftCard}/adjust', [GiftCardController::class, 'adjust'])->name('giftcards.adjust');
        Route::post('/gift-cards/{giftCard}/deliver', [GiftCardController::class, 'deliver'])->name('giftcards.deliver');
    });
});

/*
|--------------------------------------------------------------------------
| Admin Panel Auth (public login)
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:5,1');
});


/*
|--------------------------------------------------------------------------
| Super Admin Panel Protected Routes
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::post('/logout', [AdminAuthController::class, 'logout']);
    Route::get('/me', [AdminAuthController::class, 'me']);

    Route::get('/dashboard', [AdminDashboardController::class, 'index'])->middleware('admin:super_admin');
    Route::get('/analytics', [AdminDashboardController::class, 'analytics'])->middleware('admin:super_admin');

    Route::get('/businesses', [BusinessManagementController::class, 'index']);
    Route::get('/businesses/{business}', [BusinessManagementController::class, 'show'])->middleware('admin:super_admin');
    Route::post('/businesses/{business}/suspend', [BusinessManagementController::class, 'suspend'])->middleware('admin:super_admin');
    Route::post('/businesses/{business}/restore', [BusinessManagementController::class, 'restore'])->middleware('admin:super_admin');
    Route::post('/businesses/{business}/plan', [BusinessManagementController::class, 'updatePlan'])->middleware('admin:super_admin');
    Route::post('/businesses/{business}/trial', [BusinessManagementController::class, 'extendTrial'])->middleware('admin:super_admin');
    Route::post('/businesses/{business}/pricing-overrides', [BusinessManagementController::class, 'storePricingOverride'])->middleware('admin:super_admin');
    Route::patch('/businesses/{business}/pricing-overrides/{override}', [BusinessManagementController::class, 'updatePricingOverride'])->middleware('admin:super_admin');
    Route::delete('/businesses/{business}/pricing-overrides/{override}', [BusinessManagementController::class, 'destroyPricingOverride'])->middleware('admin:super_admin');

    Route::get('/users', [UserManagementController::class, 'index']);
    Route::get('/users/{user}', [UserManagementController::class, 'show']);
    Route::put('/users/{user}', [UserManagementController::class, 'update'])->middleware('admin:super_admin,admin');
    Route::patch('/users/{user}/toggle-active', [UserManagementController::class, 'toggleActive'])->middleware('admin:super_admin,admin');

    Route::apiResource('/admins', AdminManagementController::class)->middleware('admin:super_admin');
    Route::patch('/admins/{admin}/toggle-active', [AdminManagementController::class, 'toggleActive'])->middleware('admin:super_admin');
    Route::post('/admins/{admin}/password', [AdminManagementController::class, 'updatePassword'])->middleware('admin:super_admin');

    Route::get('/logs', [LogController::class, 'index'])->middleware('admin:super_admin');
    Route::get('/logs/{id}', [LogController::class, 'show'])->middleware('admin:super_admin');
    Route::get('/logs/admin/{adminId}', [LogController::class, 'adminLogs'])->middleware('admin:super_admin');

    Route::get('/analytics/dashboard', [AdminAnalyticsController::class, 'dashboard'])->middleware('admin:super_admin');
    Route::get('/analytics/businesses', [AdminAnalyticsController::class, 'businesses'])->middleware('admin:super_admin');
    Route::get('/analytics/revenue', [AdminAnalyticsController::class, 'revenue'])->middleware('admin:super_admin');
    Route::post('/analytics/export/businesses', [AdminAnalyticsController::class, 'exportBusinesses'])->middleware('admin:super_admin');
    Route::post('/analytics/export/revenue', [AdminAnalyticsController::class, 'exportRevenue'])->middleware('admin:super_admin');

    Route::apiResource('/plans', AdminPlanController::class)->middleware('admin:super_admin');
    Route::post('/plans/reorder', [AdminPlanController::class, 'reorder'])->middleware('admin:super_admin');
    Route::patch('/plans/{plan}/toggle-active', [AdminPlanController::class, 'toggleActive'])->middleware('admin:super_admin');
    Route::patch('/plans/{plan}/toggle-visible', [AdminPlanController::class, 'toggleVisible'])->middleware('admin:super_admin');
});
