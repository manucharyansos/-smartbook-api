# IDBank hosted redirect flow (prepared)

This project is now prepared for a bank-hosted payment page flow:

1. Frontend creates invoice.
2. Frontend requests `POST /api/billing/checkout-session`.
3. Backend creates a pending payment transaction and returns a `checkout_url`.
4. Frontend redirects the user to the bank-hosted page.
5. Bank redirects the user back to the frontend `payment-return` page.
6. Bank also calls the backend webhook/callback URL.
7. Backend marks the transaction/invoice/subscription state.

## Mock mode

In mock mode the bank page is simulated by the frontend route `/mock-bank/idbank`, and the simulated bank callback is:

- `POST /api/webhooks/payments/idbank/mock-complete`

## Live IdBank connection later

When real IdBank credentials/docs are available, the remaining work is mainly:

- map checkout payload fields to the bank's official field names
- align the existing HMAC verification input/header with the bank's official signature contract
- switch provider from `idbank_mock` to `idbank`
- set `BILLING_ALLOW_MOCK_PAYMENTS=false` and enable `IDBANK_LIVE_ENABLED=true` only after end-to-end verification
- update `.env` values for merchant/terminal/URLs
