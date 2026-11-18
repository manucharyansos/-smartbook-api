<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


Schedule::command('reminders:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer();

Schedule::command('marketing:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer();

Schedule::command('vizit:prune-site-events')->dailyAt('04:10')->onOneServer();

// No-op until REVIEWS_REQUEST_EMAILS=true.
Schedule::command('reviews:send-requests')->hourlyAt(17)->withoutOverlapping()->onOneServer();
