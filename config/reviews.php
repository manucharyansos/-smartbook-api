<?php

return [
    // Days after the visit during which the client can leave a review.
    'window_days' => (int) env('REVIEWS_WINDOW_DAYS', 30),
    // The public average is shown only from this many visible reviews ("New" before that).
    'min_public_count' => (int) env('REVIEWS_MIN_PUBLIC_COUNT', 3),
    // "Rate your visit" emails stay off until production email delivery is verified.
    'request_emails' => (bool) env('REVIEWS_REQUEST_EMAILS', false),
    'request_delay_hours' => (int) env('REVIEWS_REQUEST_DELAY_HOURS', 2),
    'comment_max' => 1000,
];
