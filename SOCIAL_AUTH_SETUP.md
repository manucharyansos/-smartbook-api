# Vizit social authentication setup

Vizit supports Google and Facebook for:

- business sign in;
- business registration followed by onboarding;
- client sign in;
- client registration and automatic linking to earlier bookings with the same email.

The backend performs the OAuth authorization-code exchange. Provider secrets
must exist only in the backend environment; never place them in a VITE variable.

## 1. Deploy the code

From the API directory:

    composer install --no-dev --optimize-autoloader
    php artisan migrate --force

The migrations add:

- users.provider and users.provider_id;
- immutable bookings.client_email snapshots.
- isolated client-account password reset tokens.

## 2. Backend production environment

    APP_URL=https://api.vizit.am
    APP_FRONTEND_URL=https://vizit.am
    FRONTEND_APP_URL=https://vizit.am
    APP_ENV=production
    APP_DEBUG=false
    SESSION_SECURE_COOKIE=true
    CACHE_STORE=database

    SOCIAL_AUTH_ENABLED=true
    SOCIAL_AUTH_FRONTEND_URLS=https://vizit.am

    SOCIAL_AUTH_GOOGLE_ENABLED=true
    GOOGLE_CLIENT_ID=replace-with-google-client-id
    GOOGLE_CLIENT_SECRET=replace-with-google-client-secret
    GOOGLE_REDIRECT_URI=https://api.vizit.am/api/auth/social/google/callback

    SOCIAL_AUTH_FACEBOOK_ENABLED=true
    FACEBOOK_CLIENT_ID=replace-with-facebook-app-id
    FACEBOOK_CLIENT_SECRET=replace-with-facebook-app-secret
    FACEBOOK_REDIRECT_URI=https://api.vizit.am/api/auth/social/facebook/callback
    FACEBOOK_GRAPH_VERSION=v26.0

After changing the environment:

    php artisan optimize:clear
    php artisan config:cache
    php artisan route:cache

Use `database` or `redis` for `CACHE_STORE`; do not use the per-process `array`
store in production because OAuth exchange codes must survive between HTTP
requests and application workers.

GET /api/auth/social/providers returns only providers that are both enabled and
have a client ID and secret. The frontend therefore does not display a button
whose backend is not ready.

## 3. Google Cloud Console

Create an OAuth 2.0 Client ID of type **Web application** and add this exact
authorized redirect URI:

    https://api.vizit.am/api/auth/social/google/callback

Copy the client ID and client secret to the backend environment.

Google's web-server OAuth instructions are available at:
https://developers.google.com/identity/protocols/oauth2/web-server

## 4. Meta for Developers

Create a Facebook app, enable Facebook Login for the web, and add this exact
Valid OAuth Redirect URI:

    https://api.vizit.am/api/auth/social/facebook/callback

Set the app domain and public website to vizit.am, then copy the App ID and App
Secret to the backend environment. Keep FACEBOOK_GRAPH_VERSION configurable so
it can be upgraded without changing application code.

Meta's manual authorization-code flow is documented at:
https://developers.facebook.com/documentation/facebook-login/guides/advanced/manual-flow

Both provider applications must be switched to their live/production state
before accounts outside the provider application's test-user list can sign in.

## 5. Password reset mail

Business and client password reset links now point to:

    https://vizit.am/reset-password

Configure the production mail transport (`MAIL_MAILER`, host/provider,
credentials, `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`) only in the backend
environment. The reset endpoint always returns the same public response so it
does not disclose whether an account exists; delivery failures are written to
the backend log.

## 6. Frontend build flags

The production frontend build contains:

    VITE_SOCIAL_AUTH_ENABLED=true
    VITE_SOCIAL_AUTH_GOOGLE_ENABLED=true
    VITE_SOCIAL_AUTH_FACEBOOK_ENABLED=true

These are public feature flags, not secrets. Actual button visibility still
comes from the API provider-capability endpoint.

## Security properties

- encrypted, HTTP-only, SameSite=Lax OAuth context cookie;
- cryptographically random state validation against login CSRF;
- exact frontend origin and callback-path allowlist;
- short-lived, single-use exchange code instead of exposing the API token in a URL;
- provider access tokens are used only during the callback and are never stored;
- verified Google email is required;
- auth endpoints are rate limited.
