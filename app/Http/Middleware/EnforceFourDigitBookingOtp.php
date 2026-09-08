<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceFourDigitBookingOtp
{
    public function handle(Request $request, Closure $next): Response
    {
        $isBookingVerification = $request->is('api/public/bookings/*/verify')
            || $request->is('api/v1/public/bookings/*/verify');

        if ($isBookingVerification) {
            $otp = $request->input('otp');
            if (!is_string($otp) || preg_match('/^\d{4}$/', $otp) !== 1) {
                return response()->json([
                    'message' => 'The OTP must be exactly 4 digits.',
                    'errors' => [
                        'otp' => ['The OTP must be exactly 4 digits.'],
                    ],
                ], 422);
            }
        }

        return $next($request);
    }
}
