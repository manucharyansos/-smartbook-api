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
- `IDBANK_MERCHANT_ID=`
- `IDBANK_TERMINAL_ID=`
- `IDBANK_PUBLIC_KEY=`
- `IDBANK_SECRET=`
- `IDBANK_WEBHOOK_SECRET=`
- `IDBANK_CHECKOUT_BASE_URL=`
- `IDBANK_RETURN_URL=`

## What is still mocked

- Checkout URL creation
- Signature verification
- Real provider payment ID / settlement payload
- Refunds / chargebacks / retry webhooks

## Recommended next backend steps

- Add webhook signature verification using `IDBANK_WEBHOOK_SECRET`
- Persist raw provider order/request IDs from the real API response
- Add `failed`, `expired`, `refunded`, and `chargeback` lifecycle handlers
- Add scheduled dunning / retry logic for failed renewals
- Add real recurring billing if IdBank supports tokenized recurring payments for your agreement
