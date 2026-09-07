<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BookingPaymentController extends Controller
{
    public function capabilities(string $code, Request $request)
    {
        $booking = Booking::query()->where('booking_code', $code)->firstOrFail();
        $this->assertGuestAccess($booking, (string) $request->header('X-Guest-Token'));
        $related = Booking::query()->where('client_id', $booking->client_id)
            ->when($booking->group_id, fn ($q) => $q->where('group_id', $booking->group_id), fn ($q) => $q->whereKey($booking->id))->get();
        $total = $related->contains(fn ($item) => $item->final_price === null)
            ? null
            : (int) $related->sum(fn ($item) => (int) $item->final_price);
        return response()->json(['data' => [
            'deposit_available' => (bool) config('booking_payments.enabled') && $total !== null && $total > 0
                && !in_array($booking->status, ['cancelled', 'done', 'completed', 'no_show'], true),
            'deposit_percent' => (int) config('booking_payments.deposit_percent', 20),
        ]]);
    }

    public function session(string $code, Request $request)
    {
        abort_unless(config('booking_payments.enabled'), 503, 'Booking payments are not enabled.');
        $booking = Booking::query()->where('booking_code', $code)->firstOrFail();
        $this->assertGuestAccess($booking, (string) $request->header('X-Guest-Token'));

        $data = $request->validate([
            'return_url' => ['required', 'string', 'max:500'],
            'cancel_url' => ['required', 'string', 'max:500'],
        ]);
        abort_unless($this->allowedReturnUrl($data['return_url']) && $this->allowedReturnUrl($data['cancel_url']), 422, 'Invalid payment return URL.');
        abort_if(in_array($booking->status, ['cancelled', 'done', 'completed', 'no_show'], true), 409, 'This booking cannot be paid.');

        $provider = (string) config('booking_payments.provider');
        abort_unless(in_array($provider, ['idbank', 'idbank_mock'], true), 503, 'Booking payment provider is misconfigured.');
        if ($provider === 'idbank') {
            abort_unless(config('billing.providers.idbank.live_enabled'), 503, 'IDBank live payment is not connected yet.');
            abort(503, 'IDBank contract mapping is pending official merchant documentation.');
        }
        abort_unless(config('billing.allow_mock_payments'), 404);

        $existing = BookingPayment::query()->where('booking_id', $booking->id)->where('provider', $provider)
            ->where('status', 'pending')->where('expires_at', '>', now())->latest('id')->first();
        if ($existing) return $this->sessionResponse($existing, 200);

        $related = Booking::query()->where('client_id', $booking->client_id)
            ->when($booking->group_id, fn ($q) => $q->where('group_id', $booking->group_id), fn ($q) => $q->whereKey($booking->id))->get();
        abort_if($related->contains(fn ($item) => $item->final_price === null), 422, 'Booking price is not available.');
        $total = (int) $related->sum(fn ($item) => (int) $item->final_price);
        abort_if($total < 1, 422, 'Booking amount must be positive.');
        $percent = min(100, max(1, (int) config('booking_payments.deposit_percent', 20)));
        $amount = min($total, max(1, (int) ceil($total * $percent / 100)));
        $reference = 'bp_' . Str::upper(Str::random(24));

        $payment = BookingPayment::query()->create([
            'booking_id' => $booking->id, 'business_id' => $booking->business_id,
            'provider' => $provider, 'reference' => $reference, 'amount' => $amount,
            'currency' => $booking->currency ?: 'AMD', 'status' => 'pending',
            'return_url' => $data['return_url'], 'cancel_url' => $data['cancel_url'],
            'expires_at' => now()->addMinutes(max(5, (int) config('booking_payments.session_minutes', 30))),
        ]);
        $payment->update(['checkout_url' => rtrim((string) config('app.url'), '/') . '/api/public/booking-payments/mock/' . $reference]);

        return $this->sessionResponse($payment->fresh(), 201);
    }

    public function status(string $code, BookingPayment $payment, Request $request)
    {
        $booking = Booking::query()->where('booking_code', $code)->firstOrFail();
        $this->assertGuestAccess($booking, (string) $request->header('X-Guest-Token'));
        abort_unless($payment->booking_id === $booking->id, 404);
        if ($payment->status === 'pending' && $payment->expires_at?->isPast()) $payment->update(['status' => 'expired']);
        return response()->json(['data' => $this->paymentPayload($payment->fresh())]);
    }

    public function mockPage(string $reference)
    {
        abort_unless(config('billing.allow_mock_payments'), 404);
        $payment = BookingPayment::query()->where('provider', 'idbank_mock')->where('reference', $reference)->firstOrFail();
        abort_unless($payment->status === 'pending' && (!$payment->expires_at || $payment->expires_at->isFuture()), 410);
        $action = url('/api/public/booking-payments/mock/' . rawurlencode($reference) . '/complete');
        $amount = number_format($payment->amount, 0, '.', ' ');
        return response("<!doctype html><meta name=viewport content='width=device-width'><title>Vizit test payment</title><style>body{font-family:system-ui;background:#fff1de;color:#351d35;display:grid;place-items:center;min-height:100vh}.c{background:white;padding:28px;border-radius:24px;box-shadow:0 12px 40px #351d3522;text-align:center;max-width:360px}button{border:0;border-radius:14px;padding:14px 20px;margin:6px;font-weight:700}.ok{background:#5c3158;color:white}.no{background:#f7dfd0;color:#5c3158}</style><div class=c><h1>IDBank test flow</h1><p>{$amount} {$payment->currency}</p><p>Reference: {$payment->reference}</p><form method=post action='{$action}'><button class=ok name=status value=success>Test successful payment</button><button class=no name=status value=cancelled>Cancel</button></form></div>")->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function mockComplete(string $reference, Request $request)
    {
        abort_unless(config('billing.allow_mock_payments'), 404);
        $data = $request->validate(['status' => ['required', 'in:success,failed,cancelled']]);
        $payment = DB::transaction(function () use ($reference, $data) {
            $payment = BookingPayment::query()->where('provider', 'idbank_mock')->where('reference', $reference)->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'pending') return $payment;
            $status = $data['status'] === 'success' ? 'paid' : $data['status'];
            $payment->update([
                'status' => $status,
                'paid_at' => $status === 'paid' ? now() : null,
                'failed_at' => $status === 'failed' ? now() : null,
                'cancelled_at' => $status === 'cancelled' ? now() : null,
                'provider_payload' => ['source' => 'booking-mock-page', 'status' => $data['status']],
            ]);
            return $payment->fresh();
        });
        $target = $payment->status === 'cancelled' ? $payment->cancel_url : $payment->return_url;
        $separator = str_contains($target, '?') ? '&' : '?';
        return redirect()->away($target . $separator . http_build_query(['status' => $payment->status === 'paid' ? 'success' : $payment->status, 'reference' => $payment->reference, 'invoice_id' => $payment->id]));
    }

    private function assertGuestAccess(Booking $booking, string $token): void
    {
        abort_unless($booking->phone_verified_at, 403, 'Booking is not verified yet.');
        abort_unless($token !== '', 401, 'Guest access token is required.');
        abort_unless($booking->guest_access_expires_at && now()->lte($booking->guest_access_expires_at), 401, 'Guest access has expired.');
        abort_unless(Hash::check($token, (string) $booking->guest_access_token_hash), 401, 'Invalid guest access token.');
    }

    private function allowedReturnUrl(string $value): bool
    {
        $parts = parse_url($value);
        return ($parts['scheme'] ?? '') === 'vizit' || (($parts['scheme'] ?? '') === 'https' && strtolower((string) ($parts['host'] ?? '')) === 'vizit.am');
    }

    private function sessionResponse(BookingPayment $payment, int $status)
    {
        return response()->json(['checkout_url' => $payment->checkout_url, 'reference' => $payment->reference, 'invoice_id' => (string) $payment->id, 'amount' => $payment->amount, 'currency' => $payment->currency], $status);
    }

    private function paymentPayload(BookingPayment $payment): array
    {
        return ['status' => $payment->status, 'reference' => $payment->reference, 'invoice_id' => (string) $payment->id, 'amount' => $payment->amount, 'currency' => $payment->currency, 'paid_at' => $payment->paid_at?->toISOString()];
    }
}
