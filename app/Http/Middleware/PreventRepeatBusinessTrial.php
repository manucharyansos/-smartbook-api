<?php

namespace App\Http\Middleware;

use App\Models\TrialAttempt;
use App\Support\Phone;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventRepeatBusinessTrial
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isMethod('post') || !$request->is('api/auth/register')) {
            return $next($request);
        }

        $phoneNorm = Phone::normalizeAM($request->input('business_phone'));
        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $fingerprint = trim((string) ($request->header('X-Device-Fingerprint') ?? $request->input('device_fingerprint', '')));
        $ip = (string) $request->ip();

        // Trial eligibility is enforced server-side so web and mobile share the
        // same rule. Phone/email/device are lifetime identifiers for a trial.
        $usedTrial = TrialAttempt::query()
            ->where(function ($query) use ($phoneNorm, $email, $fingerprint) {
                if ($phoneNorm) {
                    $query->orWhere('phone_norm', $phoneNorm);
                }
                if ($email !== '') {
                    $query->orWhereRaw('LOWER(email) = ?', [$email]);
                }
                if ($fingerprint !== '') {
                    $query->orWhere('fingerprint', $fingerprint);
                }
            })
            ->exists();

        if ($usedTrial) {
            return response()->json([
                'message' => 'The free trial has already been used for this business or device.',
                'code' => 'TRIAL_ALREADY_USED',
                'errors' => [
                    'trial' => ['The 14-day free trial is available only once. Please choose a paid plan to continue.'],
                ],
            ], 422);
        }

        // IP is deliberately not a lifetime identity: offices, homes and mobile
        // networks can legitimately share one public IP. It is only an abuse
        // signal to stop bursts of disposable-account registrations.
        if ($ip !== '' && TrialAttempt::query()->where('ip', $ip)->where('created_at', '>=', now()->subDay())->count() >= 5) {
            return response()->json([
                'message' => 'Too many trial registrations from this network. Please try again later or contact support.',
                'code' => 'TRIAL_RATE_LIMITED',
            ], 429);
        }

        return $next($request);
    }
}
