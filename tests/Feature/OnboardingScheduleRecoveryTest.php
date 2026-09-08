<?php

use App\Http\Controllers\Api\BusinessOnboardingController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Middleware\EnsureOnboardingCompleted;
use App\Models\Business;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('lets an incomplete business recover a missing weekly schedule', function () {
    $business = Business::factory()->create(['is_onboarding_completed' => false]);
    $owner = User::factory()->owner($business->id)->create();
    Service::factory()->create(['business_id' => $business->id, 'is_active' => true]);
    DB::table('business_working_hours')->where('business_id', $business->id)->delete();

    $statusRequest = Request::create('/api/business/onboarding-status', 'GET');
    $statusRequest->setUserResolver(fn () => $owner);
    $status = app(BusinessOnboardingController::class)->status($statusRequest);
    expect($status->getData(true)['data']['onboarding_step'])->toBe('schedule');

    $middlewareRequest = Request::create('/api/schedule', 'PUT');
    $middlewareRequest->setUserResolver(fn () => $owner);
    $allowed = app(EnsureOnboardingCompleted::class)->handle(
        $middlewareRequest,
        fn () => response()->json(['ok' => true]),
    );
    expect($allowed->getStatusCode())->toBe(200);

    $days = collect(range(1, 7))->map(fn (int $weekday) => [
        'weekday' => $weekday,
        'is_closed' => $weekday === 7,
        'start' => $weekday === 7 ? null : '09:00',
        'end' => $weekday === 7 ? null : '18:00',
        'break_start' => null,
        'break_end' => null,
    ])->all();

    $scheduleRequest = Request::create('/api/schedule', 'PUT', ['days' => $days]);
    $scheduleRequest->setUserResolver(fn () => $owner);
    $saved = app(ScheduleController::class)->update($scheduleRequest);
    expect($saved->getStatusCode())->toBe(200);

    $statusAfter = app(BusinessOnboardingController::class)->status($statusRequest);
    expect($statusAfter->getData(true)['data']['onboarding_step'])->toBe('settings');
});
