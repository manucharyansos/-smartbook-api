<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Services\Billing\IdramGateway;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class IdramPaymentController extends Controller
{
    public function checkout(Request $request, PaymentTransaction $transaction, IdramGateway $gateway)
    {
        abort_unless($gateway->isReady(), 503, 'Idram is not connected.');
        $locale = $request->query('locale');
        app()->setLocale(in_array($locale, ['hy', 'ru', 'en'], true) ? $locale : 'hy');
        $invoice = Invoice::withoutGlobalScope('business')->where('business_id', $transaction->business_id)
            ->findOrFail($transaction->invoice_id);
        $fields = $gateway->fields($transaction, $invoice);
        $nonce = base64_encode(random_bytes(18));
        $request->attributes->set('idram_csp_nonce', $nonce);

        return response()->view('billing.idram-checkout', compact('fields', 'nonce'), 200,
            ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }

    public function result(Request $request, IdramGateway $gateway)
    {
        $status = 200;
        $body = 'OK';
        if (! $gateway->canVerifyCallbacks()) {
            $status = 503;
            $body = 'UNAVAILABLE';
        } else {
            try {
                $data = $request->request->all();
                if (array_key_exists('EDP_PRECHECK', $data)) {
                    $gateway->precheck($data);
                } else {
                    $gateway->confirm($data);
                }
            } catch (HttpExceptionInterface $exception) {
                $status = $exception->getStatusCode();
                $body = 'REJECTED';
            }
        }

        return response($body, $status, ['Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }

    public function success(Request $request)
    {
        return $this->returnToSite($request, false);
    }

    public function fail(Request $request)
    {
        return $this->returnToSite($request, true);
    }

    public function information()
    {
        // Bank staff may open the URL before issuing the test merchant. Only a
        // validated POST callback may ever receive the protocol's "OK" reply.
        return response()->json(['service' => 'Vizit.am / Idram', 'endpoint' => 'payment confirmation',
            'callback_method' => 'POST', 'message' => 'Callback URL is available. Merchant configuration is completed after Idram issues test credentials.'],
            200, ['Cache-Control' => 'no-store, private']);
    }

    private function returnToSite(Request $request, bool $failed)
    {
        $reference = $request->input('EDP_BILL_NO');
        $transaction = is_string($reference) && strlen($reference) <= 100
            ? PaymentTransaction::where('provider', 'idram')->where('provider_transaction_id', $reference)->first()
            : null;
        $query = ['provider' => 'idram', 'return' => $failed ? 'fail' : 'success'];
        if ($transaction) {
            $query += ['reference' => $transaction->provider_transaction_id, 'invoice_id' => $transaction->invoice_id];
        }
        if ($failed) {
            $query['status'] = 'cancelled';
        }

        // Browser return data never changes a payment or subscription state.
        return redirect()->away(rtrim((string) config('billing.frontend_base_url'), '/').'/payment-return?'.http_build_query($query), 303)
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }
}
