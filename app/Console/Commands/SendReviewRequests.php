<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Notifications\ReviewRequestNotification;
use App\Services\ReviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Emails one "rate your visit" link per finished visit, a few hours after it ended.
 * Does nothing unless REVIEWS_REQUEST_EMAILS=true (off until email delivery is verified).
 */
class SendReviewRequests extends Command
{
    protected $signature = 'reviews:send-requests {--limit=200} {--force : Send even when the setting is off}';

    protected $description = 'Send "rate your visit" emails for finished visits';

    public function handle(ReviewService $reviews): int
    {
        if (!config('reviews.request_emails') && !$this->option('force')) {
            $this->info('Review request emails are off (REVIEWS_REQUEST_EMAILS=false).');

            return self::SUCCESS;
        }
        $delay = max(0, (int) config('reviews.request_delay_hours', 2));
        $sent = 0;
        Booking::query()
            ->with(['business:id,name', 'client:id,email'])
            ->whereIn('status', ['confirmed', 'done'])
            ->whereNull('review_requested_at')
            ->where('ends_at', '<=', now()->subHours($delay))
            ->where('ends_at', '>=', now()->subDays(3))
            ->whereDoesntHave('review')
            ->orderBy('ends_at')
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (Booking $booking) use ($reviews, &$sent) {
                // Mark first so a mail failure never causes a second email for the same visit.
                $booking->forceFill(['review_requested_at' => now()])->save();
                $email = $booking->contactEmail();
                if (!$email || !$reviews->eligibility($booking)['eligible']) return;
                try {
                    Notification::route('mail', $email)->notify(new ReviewRequestNotification($booking, $reviews->issueReviewToken($booking)));
                    $sent++;
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });
        $this->info("Sent {$sent} review request(s).");

        return self::SUCCESS;
    }
}
