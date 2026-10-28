<?php

namespace App\Services\Billing;

final class BillingPaymentProviders
{
    public static function status(): array
    {
        $provider = (string) config('billing.providers.default', 'idbank');
        $mode = (string) config('billing.providers.mode', 'live');
        $mock = $provider === 'idbank_mock' && $mode === 'mock'
            && app()->environment(['local', 'testing']) && config('billing.allow_mock_payments');
        $live = $mode === 'live' && ($provider === 'idram'
            ? app(IdramGateway::class)->isReady()
            : ($provider === 'idbank' && config('billing.providers.idbank.live_enabled')
                && config('billing.providers.idbank.contract_mapping_ready')
                && collect(['merchant_id', 'terminal_id', 'secret', 'webhook_secret'])->every(
                    fn ($key) => filled($value = config("billing.providers.idbank.{$key}")) && $value !== 'CHANGE_ME'
                )));

        return [
            'default' => $provider,
            'display_name' => $provider === 'idram' ? 'Idram' : 'IDBank',
            'payment_method' => $provider === 'idram' ? 'wallet' : 'card',
            'mode' => $provider === 'idbank_mock' ? 'mock' : 'live',
            'live_ready' => (bool) $live,
            'checkout_required' => true,
            'checkout_available' => (bool) ($live || $mock),
            'connection_status' => $live || $mock ? 'connected' : 'awaiting_official_connection',
        ];
    }
}
