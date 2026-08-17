<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Services\Billing\HostedCheckoutUrlBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BillingPaymentController extends Controller
{
    public function __construct(private HostedCheckoutUrlBuilder $checkoutBuilder)
    {
    }

    public function createCheckout(Request $request)
    {
        $user = $request->user();

        if ($user->business?->status === 'suspended') {
            return response()->json([
                'message' => 'Բիզնեսը կասեցված է։ Վճարումից առաջ կապվիր աջակցության հետ։',
                'code' => 'business_suspended',
            ], 409);
        }

        $data = $request->validate([
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'provider' => ['nullable', 'string', 'in:idbank,idbank_mock'],
            'payment_method' => ['nullable', 'string', 'in:bank_transfer,idram,card'],
        ]);

        $provider = (string) config('billing.providers.default', 'idbank_mock');
        $method = $data['payment_method'] ?? 'card';

        if (!in_array($provider, ['idbank', 'idbank_mock'], true)) {
            return response()->json([
                'message' => 'Վճարման provider-ը սխալ է կարգավորված։',
                'code' => 'payment_provider_misconfigured',
            ], 503);
        }

        if (!empty($data['provider']) && $data['provider'] !== $provider) {
            return response()->json([
                'message' => 'Տվյալ վճարման provider-ը հասանելի չէ։',
                'code' => 'payment_provider_unavailable',
            ], 422);
        }

        if ($provider === 'idbank_mock' && !config('billing.allow_mock_payments', false)) {
            return response()->json([
                'message' => 'Փորձնական վճարումները հասանելի չեն այս միջավայրում։',
                'code' => 'mock_payments_disabled',
            ], 503);
        }

        if ($provider === 'idbank' && !$this->idbankIsReady()) {
            return response()->json([
                'message' => 'IDBank live վճարումը դեռ միացված չէ։',
                'code' => 'live_payments_disabled',
            ], 503);
        }

        if (!empty($data['invoice_id'])) {
            $invoice = Invoice::query()->where('business_id', $user->business_id)->findOrFail($data['invoice_id']);
        } else {
            $invoice = Invoice::query()
                ->where('business_id', $user->business_id)
                ->where('status', 'pending')
                ->latest('id')
                ->firstOrFail();
        }

        if ($invoice->status !== 'pending') {
            return response()->json([
                'message' => 'Այս վճարման հաշիվն այլևս վճարման ենթակա չէ։',
                'code' => 'invoice_not_pending',
            ], 409);
        }

        $transaction = PaymentTransaction::query()
            ->where('business_id', $invoice->business_id)
            ->where('invoice_id', $invoice->id)
            ->where('provider', $provider)
            ->where('payment_method', $method)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if ($transaction) {
            if (!$transaction->checkout_url) {
                $checkout = $this->checkoutBuilder->build($transaction, $invoice);
                $transaction->update([
                    'checkout_url' => $checkout['checkout_url'],
                    'checkout_payload' => $checkout['payload'],
                ]);
            }

            return $this->checkoutResponse($transaction->fresh(), $provider, 200);
        }

        $reference = 'pay_' . Str::upper(Str::random(18));

        $transaction = PaymentTransaction::create([
            'business_id' => $invoice->business_id,
            'invoice_id' => $invoice->id,
            'provider' => $provider,
            'provider_transaction_id' => $reference,
            'payment_method' => $method,
            'amount' => (int) $invoice->amount,
            'currency' => (string) $invoice->currency,
            'status' => 'pending',
        ]);

        $checkout = $this->checkoutBuilder->build($transaction, $invoice);

        $transaction->update([
            'checkout_url' => $checkout['checkout_url'],
            'checkout_payload' => $checkout['payload'],
        ]);

        return $this->checkoutResponse($transaction->fresh(), $provider, 201);
    }

    private function checkoutResponse(PaymentTransaction $transaction, string $provider, int $status)
    {
        return response()->json([
            'ok' => true,
            'redirect_required' => true,
            'redirect_method' => 'hosted_page',
            'data' => $transaction,
            'provider' => [
                'name' => $provider,
                'mode' => $provider === 'idbank' ? 'live' : 'mock',
                'live_ready' => $provider === 'idbank',
                'message' => 'Hosted redirect flow is ready. User should be sent to the bank page, and the final status should come back via return URL and webhook/callback.',
            ],
        ], $status);
    }

    private function idbankIsReady(): bool
    {
        if (!config('billing.providers.idbank.live_enabled', false)) {
            return false;
        }

        foreach (['merchant_id', 'terminal_id', 'secret', 'webhook_secret'] as $key) {
            $value = config("billing.providers.idbank.{$key}");
            if (!filled($value) || $value === 'CHANGE_ME') {
                return false;
            }
        }

        return true;
    }

    public function status(Request $request, Invoice $invoice)
    {
        abort_unless($invoice->business_id === $request->user()->business_id, 404);

        $transaction = PaymentTransaction::query()
            ->where('invoice_id', $invoice->id)
            ->latest('id')
            ->first();

        return response()->json([
            'data' => [
                'invoice' => $invoice,
                'transaction' => $transaction,
            ],
        ]);
    }
}
