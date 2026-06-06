# SmartBook Social Auth Setup

## What is already implemented

- API redirect route: `/api/auth/social/{provider}/redirect`
- API callback route: `/api/auth/social/{provider}/callback`
- One-time exchange route: `/api/auth/social/exchange`
- Google / Facebook provider config in `config/services.php`
- Client social sign in / sign up
- Business social sign in
- Business social register with auto-created trial business + owner account
- Frontend callback flow without putting the final API token in the URL

## What you still must do before turning it on

### Backend

1. Run composer install in `api/`
2. Run migrations
3. Fill these env vars:

- `SOCIAL_AUTH_ENABLED=true`
- `SOCIAL_AUTH_GOOGLE_ENABLED=true`
- `GOOGLE_CLIENT_ID=...`
- `GOOGLE_CLIENT_SECRET=...`
- `SOCIAL_AUTH_FRONTEND_URLS=https://your-frontend-domain.com`

Optional Facebook:

- `SOCIAL_AUTH_FACEBOOK_ENABLED=true`
- `FACEBOOK_CLIENT_ID=...`
- `FACEBOOK_CLIENT_SECRET=...`

### Frontend

Set these env vars in `web/.env.production`:

- `VITE_SOCIAL_AUTH_ENABLED=true`
- `VITE_SOCIAL_AUTH_GOOGLE_ENABLED=true`
- `VITE_SOCIAL_AUTH_FACEBOOK_ENABLED=false` (or true if you also configured Facebook)
- `VITE_API_URL=https://api.your-domain.com/api`

## Important notes

- Since `laravel/socialite` was added, you must install backend dependencies again.
- `composer.lock` was removed intentionally so the new package can resolve cleanly on the target machine.
- Google should be enabled first. Facebook should be turned on only after its credentials are ready.
- If you want stricter anti-abuse rules for business trial creation through Google sign-up, add extra checks around fingerprint / email / phone before opening it publicly.
