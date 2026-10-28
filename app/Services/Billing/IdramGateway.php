<?php

namespace App\Services\Billing;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;

final class IdramGateway
{
    public const CHECKOUT_URL = 'https://banking.idram.am/Payment/GetPayment';

    public function isReady(): bool
    {
        return (bool) config('billing.providers.idram.live_enabled')
            && config('billing.providers.mode') === 'live' && $this->canVerifyCallbacks();
    }

    public function canVerifyCallbacks(): bool
    {
        $secret = config('billing.providers.idram.secret_key');

        return preg_match('/^[0-9]{9}$/D', (string) config('billing.providers.idram.rec_account')) === 1
            && is_string($secret) && trim($secret) !== '' && $secret !== 'CHANGE_ME';
    }

    public function checkout(PaymentTransaction $transaction, Invoice $invoice): array
    {
        abort_unless($this->isReady(), 503, 'Idram is not connected.');
        $this->assertPayable($transaction, $invoice);

        return [
            // The signature covers only the path and query, so a TLS-terminating
            // proxy (https outside, http to PHP) cannot invalidate the link.
            'checkout_url' => rtrim((string) config('app.url'), '/').URL::temporarySignedRoute('payments.idram.checkout',
                now()->addMinutes(max(5, min(60, (int) config('billing.providers.idram.checkout_minutes', 30)))),
                ['transaction' => $transaction->id, 'locale' => app()->getLocale()], false),
            // The merchant secret is used only when checking server callbacks.
            'payload' => ['provider' => 'idram', 'mode' => 'live', 'payment_page_mode' => 'hosted_redirect'],
        ];
    }

    public function fields(PaymentTransaction $transaction, Invoice $invoice): array
    {
        $this->assertPayable($transaction, $invoice);
        $locale = strtolower(substr((string) app()->getLocale(), 0, 2));
        $fields = [
            'EDP_LANGUAGE' => ['hy' => 'AM', 'ru' => 'RU', 'en' => 'EN'][$locale] ?? 'AM',
            'EDP_REC_ACCOUNT' => (string) config('billing.providers.idram.rec_account'),
            'EDP_DESCRIPTION' => 'Vizit subscription / invoice #'.$invoice->id,
            'EDP_AMOUNT' => (string) ((int) $transaction->amount).'.00',
            'EDP_BILL_NO' => $transaction->provider_transaction_id,
        ];
        $email = config('billing.providers.idram.email');
        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fields['EDP_EMAIL'] = $email;
        }

        return $fields;
    }

    public function precheck(array $data): void
    {
        $this->validateBase($data);
        abort_unless(($data['EDP_PRECHECK'] ?? null) === 'YES', 400);
        $transaction = PaymentTransaction::where('provider', 'idram')
            ->where('provider_transaction_id', $data['EDP_BILL_NO'])->first();
        abort_unless($transaction, 404);
        $invoice = $this->invoice($transaction);
        abort_unless($invoice, 404);
        $this->assertPayable($transaction, $invoice);
        $this->assertAmount($transaction, $data['EDP_AMOUNT']);
    }

    public function confirm(array $data): void
    {
        $this->validateBase($data);
        abort_if(Validator::make($data, [
            'EDP_PAYER_ACCOUNT' => ['required', 'string', 'regex:/^[0-9]{9}$/D'],
            'EDP_TRANS_ID' => ['required', 'string', 'size:14', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'EDP_TRANS_DATE' => ['required', 'string', 'date_format:d/m/Y'],
            'EDP_CHECKSUM' => ['required', 'string', 'regex:/^[a-fA-F0-9]{32}$/D'],
        ])->fails(), 400);
        $signed = implode(':', [$data['EDP_REC_ACCOUNT'], $data['EDP_AMOUNT'],
            config('billing.providers.idram.secret_key'), $data['EDP_BILL_NO'],
            $data['EDP_PAYER_ACCOUNT'], $data['EDP_TRANS_ID'], $data['EDP_TRANS_DATE']]);
        abort_unless(hash_equals(strtoupper(md5($signed)), strtoupper($data['EDP_CHECKSUM'])), 401);

        try {
            DB::transaction(function () use ($data) {
                $transaction = PaymentTransaction::where('provider', 'idram')
                    ->where('provider_transaction_id', $data['EDP_BILL_NO'])->lockForUpdate()->first();
                abort_unless($transaction, 404);
                $this->assertAmount($transaction, $data['EDP_AMOUNT']);
                $key = 'idram:'.$data['EDP_TRANS_ID'];
                $existing = PaymentWebhookEvent::where('deduplication_key', $key)->first();
                if ($existing) {
                    abort_unless($existing->transaction_reference === $transaction->provider_transaction_id
                        && $transaction->status === 'paid', 409);

                    return;
                }

                $event = PaymentWebhookEvent::create([
                    'provider' => 'idram', 'deduplication_key' => $key, 'event_type' => 'payment_confirmation',
                    'signature' => $data['EDP_CHECKSUM'], 'transaction_reference' => $data['EDP_BILL_NO'],
                    'payload' => collect($data)->only(['EDP_REC_ACCOUNT', 'EDP_BILL_NO', 'EDP_AMOUNT',
                        'EDP_PAYER_ACCOUNT', 'EDP_TRANS_ID', 'EDP_TRANS_DATE', 'EDP_CHECKSUM'])->all(),
                    'status' => 'received',
                ]);
                $invoice = $this->invoice($transaction, true);
                $business = Business::find($transaction->business_id);
                $review = $transaction->status === 'paid' || ! $invoice || $invoice->status !== 'pending'
                    || ! $business || $business->status === 'suspended'
                    || (int) $invoice->amount !== (int) $transaction->amount || $invoice->currency !== 'AMD';
                $payload = $event->payload;
                $payload['requires_manual_review'] = $review;
                // A late/duplicate real receipt is recorded without reactivating a
                // cancelled invoice or extending a subscription a second time.
                if ($transaction->status === 'paid') {
                    $transaction->update(['provider_payload' => array_merge($transaction->provider_payload ?? [],
                        ['requires_manual_review' => true, 'duplicate_payment_received' => true])]);
                } else {
                    $transaction->update(['status' => 'paid', 'provider_payment_id' => $data['EDP_TRANS_ID'],
                        'provider_payload' => $payload, 'paid_at' => now(), 'failed_at' => null]);
                }
                if (! $review) {
                    $invoice->update(['payment_method' => 'wallet']);
                    app(BillingLifecycleService::class)->approveInvoice($invoice, [
                        'provider' => 'idram', 'note' => 'Confirmed by Idram server callback.',
                    ]);
                }
                $event->update(['status' => $review ? 'review_required' : 'processed', 'processed_at' => now()]);
                if ($review) {
                    Log::warning('Idram receipt requires manual review.', [
                        'invoice_id' => $transaction->invoice_id, 'payment_transaction_id' => $transaction->id,
                        'idram_transaction_id' => $data['EDP_TRANS_ID'],
                    ]);
                }
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // Cross-invoice races are also protected by the database, not only a
            // read-before-write check. A receipt belongs to exactly one bill.
            $event = PaymentWebhookEvent::where('deduplication_key', 'idram:'.$data['EDP_TRANS_ID'])->first();
            abort_unless($event && $event->transaction_reference === $data['EDP_BILL_NO']
                && in_array($event->status, ['processed', 'review_required'], true), 409);
        }
    }

    private function validateBase(array $data): void
    {
        abort_if(Validator::make($data, [
            'EDP_REC_ACCOUNT' => ['required', 'string', 'regex:/^[0-9]{9}$/D'],
            'EDP_BILL_NO' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'EDP_AMOUNT' => ['required', 'string', 'regex:/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/D'],
        ])->fails(), 400);
        abort_unless($data['EDP_REC_ACCOUNT'] === (string) config('billing.providers.idram.rec_account'), 400);
    }

    private function assertPayable(PaymentTransaction $transaction, Invoice $invoice): void
    {
        abort_unless($transaction->provider === 'idram' && $transaction->status === 'pending'
            && $invoice->status === 'pending' && (int) $invoice->business_id === (int) $transaction->business_id
            && (int) $invoice->amount > 0 && (int) $invoice->amount === (int) $transaction->amount
            && $invoice->currency === 'AMD' && $transaction->currency === 'AMD'
            && Business::whereKey($transaction->business_id)->where('status', '!=', 'suspended')->exists(), 409);
    }

    private function assertAmount(PaymentTransaction $transaction, string $amount): void
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $minor = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        abort_unless($minor > 0 && $minor === (int) $transaction->amount * 100 && $transaction->currency === 'AMD', 400);
    }

    private function invoice(PaymentTransaction $transaction, bool $lock = false): ?Invoice
    {
        return Invoice::withoutGlobalScope('business')->where('business_id', $transaction->business_id)
            ->whereKey($transaction->invoice_id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }
}
