<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

if (! function_exists('public_booking_frontend_base_url')) {
    function public_booking_frontend_base_url(): string
    {
        return rtrim((string) config('services.public_booking.frontend_url', 'https://vizit.am'), '/');
    }
}

Route::get('/', function () {
    return redirect()->away(public_booking_frontend_base_url());
});

Route::get('/book/{slug}', function (Request $request, string $slug) {
    $query = $request->getQueryString();
    $target = public_booking_frontend_base_url() . '/book/' . rawurlencode($slug);

    return redirect()->away($query ? $target . '?' . $query : $target);
});
