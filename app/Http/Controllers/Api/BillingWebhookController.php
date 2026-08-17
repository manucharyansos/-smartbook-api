<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Services\Billing\BillingLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingWebhookController extends Controller
{
    public function __construct(private BillingLifecycleService $lifecycle)
    {
    }

    public function idbank(Request $request)
    {
        if (!config('billing.providers.idbank.live_enabled', false)) {
            return response()->json([
                'message' => 'IDBank live webhook-ը միացված չէ։',
                'code' => 'live_payments_disabled',
            ], 503);
        }

        $payload = $request->all();
        $signature = (string) $request->header('X-IdBank-Signature', '');
        $webhookSecret = (string) config('billing.providers.idbank.webhook_secret', '');
        $signatureAlgorithm = (string) config('billing.providers.idbank.signature_algorithm', 'sha256');

        if ($webhookSecret === '' || $signature === '' || !in_array($signatureAlgorithm, hash_hmac_algos(), true)) {
            return response()->json([
                'message' => 'Վճարման callback-ը հաստատված չէ։',
                'code' => 'invalid_webhook_signature',
            ], 401);
        }

        $providedSignature = str_starts_with($signature, $signatureAlgorithm . '=')
            ? substr($signature, strlen($signatureAlgorithm) + 1)
            : $signature;
        $expectedSignature = hash_hmac($signatureAlgorithm, $request->getContent(), $webhookSecret);

        if (!hash_equals($expectedSignature, $providedSignature)) {
            return response()->json([
                'message' => 'Վճարման callback-ի ստորագրությունը սխալ է։',
                'code' => 'invalid_webhook_signature',
            ], 401);
        }

        $reference = (string) ($payload['transaction_reference'] ?? $payload['reference'] ?? '');
        $eventType = (string) ($payload['event'] ?? $payload['status'] ?? 'unknown');

        $event = PaymentWebhookEvent::create([
            'provider' => 'idbank',
            'event_type' => $eventType,
            'signature' => $signature,
            'transaction_reference' => $reference,
            'payload' => $payload,
            'status' => 'received',
        ]);

        $transaction = PaymentTransaction::query()
            ->where('provider', 'idbank')
            ->where('provider_transaction_id', $reference)
            ->first();

        if (!$transaction) {
            $event->update(['status' => 'ignored', 'processed_at' => now()]);
            return response()->json(['ok' => true, 'message' => 'Transaction not found']);
        }

        $this->applyPaymentResult(
            $transaction,
            (string) ($payload['status'] ?? 'unknown'),
            [
                'provider' => 'idbank',
                'provider_payment_id' => $payload['payment_id'] ?? null,
                'provider_subscription_id' => (string) ($payload['subscription_id'] ?? ''),
                'note' => 'Webhook confirmed by IdBank.',
                'payload' => $payload,
            ],
            $event
        );

        return response()->json(['ok' => true]);
    }

    public function hostedMockComplete(Request $request)
    {
        $this->ensureMockPaymentsAreAllowed();

        $data = $request->validate([
            'reference' => ['required', 'string'],
            'status' => ['required', 'string', 'in:success,failed,cancelled'],
        ]);

        $transaction = PaymentTransaction::query()
            ->where('provider', 'idbank_mock')
            ->where('provider_transaction_id', $data['reference'])
            ->firstOrFail();

        $event = PaymentWebhookEvent::create([
            'provider' => 'idbank_mock',
            'event_type' => 'hosted_page_result',
            'transaction_reference' => $transaction->provider_transaction_id,
            'payload' => [
                'source' => 'mock-hosted-page',
                'status' => $data['status'],
                'invoice_id' => $transaction->invoice_id,
            ],
            'status' => 'received',
        ]);

        $this->applyPaymentResult(
            $transaction,
            $data['status'],
            [
                'provider' => 'idbank_mock',
                'provider_payment_id' => 'mock_' . $transaction->id,
                'provider_subscription_id' => 'mock_sub_' . $transaction->invoice_id,
                'note' => 'Mock hosted bank page completed.',
                'payload' => ['source' => 'mock-hosted-page', 'status' => $data['status']],
            ],
            $event
        );

        return response()->json([
            'ok' => true,
            'data' => [
                'transaction' => $transaction->fresh(),
            ],
        ]);
    }

    public function mockSuccess(Request $request, PaymentTransaction $transaction)
    {
        $this->ensureMockPaymentsAreAllowed();

        $user = $request->user();
        abort_unless($transaction->business_id === $user->business_id, 404);
        abort_unless($transaction->provider === 'idbank_mock', 404);

        $event = PaymentWebhookEvent::create([
            'provider' => 'idbank_mock',
            'event_type' => 'payment_succeeded',
            'transaction_reference' => $transaction->provider_transaction_id,
            'payload' => [
                'source' => 'mock-endpoint',
                'invoice_id' => $transaction->invoice_id,
                'status' => 'success',
            ],
            'status' => 'received',
        ]);

        $this->applyPaymentResult(
            $transaction,
            'success',
            [
                'provider' => 'idbank_mock',
                'provider_payment_id' => 'mock_' . $transaction->id,
                'provider_subscription_id' => 'mock_sub_' . $transaction->invoice_id,
                'note' => 'Mock payment completed successfully.',
                'payload' => ['source' => 'mock-endpoint', 'status' => 'success'],
            ],
            $event
        );

        return response()->json([
            'ok' => true,
            'data' => [
                'invoice' => $transaction->invoice->fresh(['plan', 'business', 'business.subscription']),
                'transaction' => $transaction->fresh(),
            ],
        ]);
    }

    private function applyPaymentResult(PaymentTransaction $transaction, string $status, array $meta, PaymentWebhookEvent $event): void
    {
        DB::transaction(function () use ($transaction, $status, $meta, $event) {
            $transaction = PaymentTransaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if ($transaction->status === 'paid') {
                $event->update(['status' => 'processed', 'processed_at' => now()]);
                return;
            }

            $normalized = strtolower($status);
            $successStates = ['paid', 'success', 'approved'];
            $invoice = $transaction->invoice()->lockForUpdate()->first();

            if (in_array($normalized, $successStates, true)) {
                $transaction->update([
                    'status' => 'paid',
                    'provider_payment_id' => $meta['provider_payment_id'] ?? $transaction->provider_payment_id,
                    'provider_payload' => $meta['payload'] ?? null,
                    'paid_at' => now(),
                    'failed_at' => null,
                ]);

                if (!$invoice || !in_array($invoice->status, ['pending', 'approved', 'paid'], true)) {
                    $event->update(['status' => 'ignored', 'processed_at' => now()]);
                    return;
                }

                if (in_array($invoice->status, ['approved', 'paid'], true)) {
                    $event->update(['status' => 'processed', 'processed_at' => now()]);
                    return;
                }

                $this->lifecycle->approveInvoice($invoice, [
                    'provider' => $meta['provider'] ?? $transaction->provider,
                    'provider_subscription_id' => $meta['provider_subscription_id'] ?? '',
                    'note' => $meta['note'] ?? null,
                ]);

                $event->update(['status' => 'processed', 'processed_at' => now()]);
                return;
            }

            $transaction->update([
                'status' => $normalized === 'cancelled' ? 'failed' : 'failed',
                'provider_payload' => $meta['payload'] ?? ['status' => $normalized],
                'failed_at' => now(),
            ]);

            $event->update(['status' => $normalized === 'cancelled' ? 'cancelled' : 'failed', 'processed_at' => now()]);
        });
    }

    private function ensureMockPaymentsAreAllowed(): void
    {
        abort_unless(config('billing.allow_mock_payments', false), 404);
    }
}
