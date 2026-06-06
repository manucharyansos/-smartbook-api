<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\PaymentTransaction;

class HostedCheckoutUrlBuilder
{
    public function build(PaymentTransaction $transaction, Invoice $invoice): array
    {
        $provider = $transaction->provider;
        $frontendBase = rtrim((string) config('billing.frontend_base_url', config('app.url')), '/');
        $returnBase = (string) config('billing.providers.idbank.return_url', $frontendBase . '/payment-return');
        $cancelBase = (string) config('billing.providers.idbank.cancel_url', $frontendBase . '/payment-return');
        $webhookUrl = (string) config('billing.providers.idbank.webhook_url', rtrim(config('app.url'), '/') . '/api/webhooks/payments/idbank');

        $returnUrl = $returnBase . '?' . http_build_query([
            'provider' => $provider,
            'reference' => $transaction->provider_transaction_id,
            'invoice_id' => $invoice->id,
        ]);

        $cancelUrl = $cancelBase . '?' . http_build_query([
            'provider' => $provider,
            'reference' => $transaction->provider_transaction_id,
            'invoice_id' => $invoice->id,
            'status' => 'cancelled',
        ]);

        $payload = [
            'reference' => $transaction->provider_transaction_id,
            'invoice_id' => $invoice->id,
            'business_id' => $invoice->business_id,
            'amount' => (int) $invoice->amount,
            'currency' => (string) $invoice->currency,
            'description' => 'Subscription invoice #' . $invoice->id,
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
            'callback_url' => $webhookUrl,
            'payment_page_mode' => 'hosted_redirect',
        ];

        if ($provider === 'idbank_mock') {
            $hostedPageUrl = (string) config('billing.providers.idbank_mock.hosted_page_url', $frontendBase . '/mock-bank/idbank');
            $checkoutUrl = $hostedPageUrl . '?' . http_build_query([
                'reference' => $transaction->provider_transaction_id,
                'invoice_id' => $invoice->id,
                'amount' => (int) $invoice->amount,
                'currency' => (string) $invoice->currency,
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ]);

            return [
                'checkout_url' => $checkoutUrl,
                'payload' => array_merge($payload, [
                    'provider' => 'idbank_mock',
                    'mode' => 'mock',
                    'hosted_page_url' => $hostedPageUrl,
                ]),
            ];
        }

        $checkoutBaseUrl = rtrim((string) config('billing.providers.idbank.checkout_base_url', 'https://payments.idbank.am'), '/');

        return [
            'checkout_url' => $checkoutBaseUrl,
            'payload' => array_merge($payload, [
                'provider' => 'idbank',
                'mode' => 'live',
                'merchant_id' => config('billing.providers.idbank.merchant_id'),
                'terminal_id' => config('billing.providers.idbank.terminal_id'),
                'integration_note' => 'Hosted redirect flow is ready. Replace the payload mapping with real IdBank field names once official API docs/credentials are attached.',
            ]),
        ];
    }
}
