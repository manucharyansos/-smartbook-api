# Idram configuration

The billing provider can be selected as Idram while checkout remains disabled. Readiness requires `BILLING_PROVIDER_MODE=live`, `IDRAM_LIVE_ENABLED=true`, a nine-digit `IDRAM_REC_ACCOUNT` and a non-empty `IDRAM_SECRET_KEY` other than `CHANGE_ME`.

Inspect the effective configuration without displaying the secret:

```bash
php artisan billing:configure-idram --check
```

Configure the bank-issued merchant with a hidden secret prompt:

```bash
php artisan billing:configure-idram --account=YOUR_BANK_MERCHANT_ID
php artisan billing:configure-idram --check
```

The command updates only the billing provider/mode, mock/IDBank enablement and the three Idram fields in the application's existing environment file. It validates the encoded values, saves a private backup, preserves unrelated settings and clears Laravel's configuration cache. The secret is entered interactively; it is never an argument or console output. Run the check in a fresh process after saving. These checks confirm local configuration, not authentication or a completed payment with Idram.

`live` is this application's protocol setting for the actual Idram gateway, including a bank-issued test merchant. It does not convert a test merchant into a contracted production merchant. Use a dedicated test business while test credentials are installed: a valid payment confirmation approves its invoice through the normal billing lifecycle. Do not put credentials in Git, frontend builds or mobile configuration.

Register these URLs with Idram for the supplied merchant:

- SUCCESS_URL: `https://api.vizit.am/api/payments/idram/success`
- FAIL_URL: `https://api.vizit.am/api/payments/idram/fail`
- RESULT_URL: `https://api.vizit.am/api/payments/idram/result`

Successful browser returns do not approve an invoice. Only the verified server callback confirms payment. The configuration command makes no bank request or database change and needs no migration.
