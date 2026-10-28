# Idram Wallet subscription checkout

This integration pays Vizit's SaaS invoices. It does not collect a salon's or clinic's customer deposits. Existing IDBank and local mock flows remain available under their existing configuration.

## URLs to send to Idram

These routes must be deployed before the bank tests or registers them:

```text
SUCCESS_URL=https://api.vizit.am/api/payments/idram/success
FAIL_URL=https://api.vizit.am/api/payments/idram/fail
RESULT_URL=https://api.vizit.am/api/payments/idram/result
```

Success/fail accept GET and POST and redirect to the public web payment return page. Browser return parameters never approve an invoice. A GET of the result URL returns informational JSON for the bank's setup check; it never replies with the payment protocol's `OK`. Actual prechecks and confirmations use form-encoded POST requests.

The routes are available before credentials are issued. A callback POST without configured merchant credentials returns 503. Idram must register all three URLs against the test merchant it issues, then against the business merchant after contracting. A personal wallet ID is not a substitute for an activated merchant account.

## Deployment before test credentials

From the API deployment directory (`~/apps.vizit.am` in the existing setup):

```bash
git pull --ff-only origin master
php artisan migrate --force
php artisan optimize:clear
```

Deploy the matching web build to `vizit.am`, including its Apache fallback configuration. Keep the existing application key. The new migration adds only a nullable, unique webhook deduplication key; it does not recreate payment tables or alter existing receipts.

## Merchant configuration

After Idram issues the test account and secret and confirms URL registration, configure these values directly in the API server's environment:

```dotenv
APP_URL=https://api.vizit.am
FRONTEND_APP_URL=https://vizit.am
BILLING_DEFAULT_PROVIDER=idram
BILLING_PROVIDER_MODE=live
BILLING_ALLOW_MOCK_PAYMENTS=false
IDRAM_LIVE_ENABLED=false
IDRAM_REC_ACCOUNT=
IDRAM_SECRET_KEY=
IDRAM_EMAIL=info@vizit.am
IDRAM_CHECKOUT_MINUTES=30
```

Fill `IDRAM_REC_ACCOUNT` and `IDRAM_SECRET_KEY` with the bank-issued test merchant values, then set `IDRAM_LIVE_ENABLED=true` and clear Laravel's configuration cache. The flag enables the external Idram connection; Idram determines test versus production processing from the merchant account. Replace credentials with the contracted business merchant only when production activation is approved. Keep secrets on the server; no secret is included in the browser form, API response or Git.

## Test flow

1. Use the test merchant and the bank's test payment instructions. For a small SaaS amount, use an individual price for the test business and create a new invoice. Existing pending invoices retain their saved amount until replaced.
2. Open Billing, create/select the pending invoice and press Pay with Idram. A short-lived, signed API page POSTs the stored AMD amount and bill reference to `https://banking.idram.am/Payment/GetPayment`.
3. Idram POSTs `EDP_PRECHECK=YES` to the result URL. The server checks the merchant account, pending bill, currency and exact amount before returning plain `OK`.
4. The final callback must pass the documented `EDP_CHECKSUM` validation. The bill and webhook receipt are locked/deduplicated in the database before the invoice is approved and the subscription is extended once.
5. The return page polls the authenticated invoice status. A forged `status=success` URL cannot display a verified successful payment.
6. Test success, cancellation, wrong amount/signature, repeated callbacks, an expired checkout link and a late payment for a cancelled invoice. A genuine late or duplicate receipt is recorded for manual review; it does not extend a cancelled/already-paid invoice again.

If new checkout is disabled later, keep the old merchant key configured until pending receipts settle: verified callbacks for existing Idram attempts continue to be accepted.

This version implements the supplied five-page Idram Wallet merchant specification (April 2025). It does not implement a separate card vPOS contract, refunds API, recurring debits or a new deposit-collection model.
