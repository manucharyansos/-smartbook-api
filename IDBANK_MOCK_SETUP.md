# IdBank Mock Integration Setup

This project now supports an **IdBank-ready mock payment flow**:

1. `POST /api/billing/upgrade-request` creates a pending invoice.
2. `POST /api/billing/checkout-session` creates a payment transaction.
3. `POST /api/billing/transactions/{transaction}/mock-success` simulates a successful provider callback.
4. `POST /api/webhooks/payments/idbank` is the public webhook endpoint for future real callbacks.
5. Successful payment automatically activates the subscription and restores business billing status.

## Env keys to wire later

- `BILLING_DEFAULT_PROVIDER=idbank`
- `BILLING_PROVIDER_MODE=live`
- `BILLING_ALLOW_MOCK_PAYMENTS=false`
- `IDBANK_LIVE_ENABLED=true` (only after the official checkout payload and callback format are verified)
- `IDBANK_MERCHANT_ID=`
- `IDBANK_TERMINAL_ID=`
- `IDBANK_PUBLIC_KEY=`
- `IDBANK_SECRET=`
- `IDBANK_WEBHOOK_SECRET=`
- `IDBANK_CHECKOUT_BASE_URL=`
- `IDBANK_RETURN_URL=`

## What is still mocked

- Checkout URL creation
- Real provider payment ID / settlement payload
- Refunds / chargebacks / retry webhooks

## Production safety already enforced

- Production defaults do not expose mock checkout.
- The client cannot select a provider different from the server configuration.
- Mock callbacks return 404 when mock payments are disabled.
- Live callbacks require an HMAC signature from `X-IdBank-Signature` using `IDBANK_WEBHOOK_SECRET`.
- `IDBANK_LIVE_ENABLED` remains false until the official IDBank field/signature mapping is confirmed.

## Remaining provider-specific work

- Align the checkout payload and HMAC input/header with the official IDBank contract before enabling live mode.
- Persist raw provider order/request IDs from the real API response
- Add `failed`, `expired`, `refunded`, and `chargeback` lifecycle handlers
- Add scheduled dunning / retry logic for failed renewals
- Add real recurring billing if IdBank supports tokenized recurring payments for your agreement
