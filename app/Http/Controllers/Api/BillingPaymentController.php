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

        $data = $request->validate([
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'provider' => ['nullable', 'string', 'in:idbank,idbank_mock'],
            'payment_method' => ['nullable', 'string', 'in:bank_transfer,idram,card'],
        ]);

        $provider = $data['provider'] ?? config('billing.providers.default', 'idbank_mock');
        $method = $data['payment_method'] ?? 'card';

        if (!empty($data['invoice_id'])) {
            $invoice = Invoice::query()->where('business_id', $user->business_id)->findOrFail($data['invoice_id']);
        } else {
            $invoice = Invoice::query()
                ->where('business_id', $user->business_id)
                ->where('status', 'pending')
                ->latest('id')
                ->firstOrFail();
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

        return response()->json([
            'ok' => true,
            'redirect_required' => true,
            'redirect_method' => 'hosted_page',
            'data' => $transaction->fresh(),
            'provider' => [
                'name' => $provider,
                'mode' => $provider === 'idbank' ? 'live' : 'mock',
                'live_ready' => $provider === 'idbank',
                'message' => 'Hosted redirect flow is ready. User should be sent to the bank page, and the final status should come back via return URL and webhook/callback.',
            ],
        ], 201);
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
