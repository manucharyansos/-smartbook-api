<?php

use App\Models\Business;
use App\Models\BusinessCategory;
use App\Models\ClientAccount;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

function socialAuthPlan(): Plan
{
    return Plan::query()->create([
        'name' => 'Start',
        'code' => 'start',
        'version' => 1,
        'allowed_business_types' => ['beauty', 'dental'],
        'price' => 7900,
        'monthly_price' => 7900,
        'yearly_price' => 79000,
        'currency' => 'AMD',
        'seats' => 1,
        'staff_limit' => 1,
        'duration_days' => 30,
        'locations' => 1,
        'features' => ['staff_limit' => 1, 'services_limit' => 10],
        'is_active' => true,
        'is_visible' => true,
        'sort_order' => 1,
    ]);
}

beforeEach(function () {
    config([
        'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
        'app.url' => 'https://api.vizit.am',
        'app.frontend_url' => 'https://vizit.am',
        'services.social_auth.enabled' => true,
        'services.social_auth.frontend_urls' => ['https://vizit.am'],
        'services.social_auth.providers.google.enabled' => true,
        'services.social_auth.providers.facebook.enabled' => false,
        'services.google.client_id' => 'google-client-id',
        'services.google.client_secret' => 'google-client-secret',
        'services.google.redirect' => 'https://api.vizit.am/api/auth/social/google/callback',
        'services.google.authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'services.google.token_url' => 'https://oauth2.googleapis.com/token',
        'services.google.userinfo_url' => 'https://openidconnect.googleapis.com/v1/userinfo',
    ]);
    Mail::fake();
});

it('publishes only social providers that are fully configured', function () {
    $this->getJson('/api/auth/social/providers')
        ->assertOk()
        ->assertExactJson([
            'enabled' => true,
            'providers' => ['google'],
        ]);
});

it('completes client Google login through a state-protected one-time exchange', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'provider-access-token',
            'token_type' => 'Bearer',
        ]),
        'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
            'sub' => 'google-user-123',
            'email' => 'client@example.com',
            'email_verified' => true,
            'name' => 'Vizit Client',
            'given_name' => 'Vizit',
            'picture' => 'https://images.example.com/avatar.jpg',
        ]),
    ]);

    $redirect = $this->get('/api/auth/social/google/redirect?' . http_build_query([
        'callback_url' => 'https://vizit.am/auth/social/callback',
        'mode' => 'login',
        'audience' => 'client',
    ]));

    $redirect->assertRedirect();
    $providerUrl = (string) $redirect->headers->get('Location');
    parse_str(parse_url($providerUrl, PHP_URL_QUERY) ?: '', $providerQuery);
    expect($providerQuery['state'] ?? null)->not->toBeEmpty();

    $contextCookie = collect($redirect->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === 'sb_social_auth_ctx');
    expect($contextCookie)->not->toBeNull();

    $callback = $this
        ->withUnencryptedCookie($contextCookie->getName(), $contextCookie->getValue())
        ->get('/api/auth/social/google/callback?' . http_build_query([
            'code' => 'google-authorization-code',
            'state' => $providerQuery['state'],
        ]));

    $callback->assertRedirect();
    parse_str(parse_url((string) $callback->headers->get('Location'), PHP_URL_QUERY) ?: '', $frontendQuery);
    expect($frontendQuery['code'] ?? null)->not->toBeEmpty();
    expect($frontendQuery['audience'] ?? null)->toBe('client');

    $exchange = $this->postJson('/api/auth/social/exchange', [
        'code' => $frontendQuery['code'],
    ]);

    $exchange
        ->assertOk()
        ->assertJsonPath('user.email', 'client@example.com')
        ->assertJsonPath('user.audience', 'client')
        ->assertJsonPath('provider', 'google');

    expect($exchange->json('token'))->not->toBeEmpty();
    $this->assertDatabaseHas('client_accounts', [
        'email' => 'client@example.com',
        'provider' => 'google',
        'provider_id' => 'google-user-123',
    ]);

    $this->postJson('/api/auth/social/exchange', ['code' => $frontendQuery['code']])
        ->assertUnprocessable();

    expect(ClientAccount::query()->where('email', 'client@example.com')->count())->toBe(1);
});

it('rejects a social callback whose state does not match the encrypted context', function () {
    Http::fake();

    $redirect = $this->get('/api/auth/social/google/redirect?' . http_build_query([
        'callback_url' => 'https://vizit.am/auth/social/callback',
        'mode' => 'login',
        'audience' => 'client',
    ]));

    $contextCookie = collect($redirect->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === 'sb_social_auth_ctx');

    $callback = $this
        ->withUnencryptedCookie($contextCookie->getName(), $contextCookie->getValue())
        ->get('/api/auth/social/google/callback?code=code&state=wrong-state');

    $callback->assertRedirect();
    $location = (string) $callback->headers->get('Location');
    expect($location)
        ->toContain('https://vizit.am/auth/social/callback')
        ->toContain(rawurlencode('Սոցիալական մուտքի անվտանգության ստուգումը չանցավ։ Փորձիր նորից։'));
    Http::assertNothingSent();
});

it('links and signs in an existing business owner by verified Google email', function () {
    $business = Business::factory()->create([
        'phone' => '+37498111222',
        'address' => 'Երևան',
    ]);
    $owner = User::factory()->create([
        'business_id' => $business->id,
        'role' => User::ROLE_OWNER,
        'email' => 'existing-owner@example.com',
    ]);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'existing-owner-provider-token',
        ]),
        'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
            'sub' => 'google-existing-owner',
            'email' => 'EXISTING-OWNER@example.com',
            'email_verified' => true,
            'name' => 'Existing Owner',
        ]),
    ]);

    $redirect = $this->get('/api/auth/social/google/redirect?' . http_build_query([
        'callback_url' => 'https://vizit.am/auth/social/callback',
        'mode' => 'login',
        'audience' => 'business',
    ]));
    parse_str(parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY) ?: '', $providerQuery);
    $contextCookie = collect($redirect->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === 'sb_social_auth_ctx');

    $callback = $this
        ->withUnencryptedCookie($contextCookie->getName(), $contextCookie->getValue())
        ->get('/api/auth/social/google/callback?' . http_build_query([
            'code' => 'existing-owner-code',
            'state' => $providerQuery['state'],
        ]));
    parse_str(parse_url((string) $callback->headers->get('Location'), PHP_URL_QUERY) ?: '', $frontendQuery);

    $this->postJson('/api/auth/social/exchange', ['code' => $frontendQuery['code']])
        ->assertOk()
        ->assertJsonPath('user.id', $owner->id)
        ->assertJsonPath('user.audience', 'business')
        ->assertJsonPath('provider', 'google');

    $this->assertDatabaseHas('users', [
        'id' => $owner->id,
        'provider' => 'google',
        'provider_id' => 'google-existing-owner',
    ]);
});

it('completes Facebook client authentication using the server-side code exchange', function () {
    config([
        'services.social_auth.providers.facebook.enabled' => true,
        'services.facebook.client_id' => 'facebook-app-id',
        'services.facebook.client_secret' => 'facebook-app-secret',
        'services.facebook.redirect' => 'https://api.vizit.am/api/auth/social/facebook/callback',
        'services.facebook.authorize_url' => 'https://www.facebook.com/v26.0/dialog/oauth',
        'services.facebook.token_url' => 'https://graph.facebook.com/v26.0/oauth/access_token',
        'services.facebook.userinfo_url' => 'https://graph.facebook.com/v26.0/me',
    ]);

    Http::fake([
        'https://graph.facebook.com/v26.0/oauth/access_token*' => Http::response([
            'access_token' => 'facebook-provider-token',
        ]),
        'https://graph.facebook.com/v26.0/me*' => Http::response([
            'id' => 'facebook-client-789',
            'email' => 'facebook-client@example.com',
            'name' => 'Facebook Client',
            'picture' => ['data' => ['url' => 'https://images.example.com/facebook.jpg']],
        ]),
    ]);

    $redirect = $this->get('/api/auth/social/facebook/redirect?' . http_build_query([
        'callback_url' => 'https://vizit.am/auth/social/callback',
        'mode' => 'register',
        'audience' => 'client',
    ]));
    parse_str(parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY) ?: '', $providerQuery);
    $contextCookie = collect($redirect->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === 'sb_social_auth_ctx');

    $callback = $this
        ->withUnencryptedCookie($contextCookie->getName(), $contextCookie->getValue())
        ->get('/api/auth/social/facebook/callback?' . http_build_query([
            'code' => 'facebook-authorization-code',
            'state' => $providerQuery['state'],
        ]));
    parse_str(parse_url((string) $callback->headers->get('Location'), PHP_URL_QUERY) ?: '', $frontendQuery);

    $this->postJson('/api/auth/social/exchange', ['code' => $frontendQuery['code']])
        ->assertOk()
        ->assertJsonPath('user.email', 'facebook-client@example.com')
        ->assertJsonPath('provider', 'facebook');

    $this->assertDatabaseHas('client_accounts', [
        'email' => 'facebook-client@example.com',
        'provider' => 'facebook',
        'provider_id' => 'facebook-client-789',
    ]);
});

it('creates a complete business and primary location through Google registration', function () {
    socialAuthPlan();
    $category = BusinessCategory::query()->updateOrCreate([
        'slug' => 'beauty-salon',
    ], [
        'vertical' => 'services',
        'name_hy' => 'Գեղեցկության սրահ',
        'name_ru' => 'Салон красоты',
        'name_en' => 'Beauty salon',
        'is_active' => true,
        'sort_order' => 10,
    ]);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'business-provider-token',
            'token_type' => 'Bearer',
        ]),
        'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
            'sub' => 'google-owner-456',
            'email' => 'social-owner@example.com',
            'email_verified' => true,
            'name' => 'Social Owner',
            'given_name' => 'Social',
            'picture' => 'https://images.example.com/owner.jpg',
        ]),
    ]);

    $redirect = $this->get('/api/auth/social/google/redirect?' . http_build_query([
        'callback_url' => 'https://vizit.am/auth/social/callback',
        'mode' => 'register',
        'audience' => 'business',
        'business_type' => 'beauty',
        'plan_code' => 'start',
    ]));

    parse_str(parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY) ?: '', $providerQuery);
    $contextCookie = collect($redirect->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === 'sb_social_auth_ctx');

    $callback = $this
        ->withUnencryptedCookie($contextCookie->getName(), $contextCookie->getValue())
        ->get('/api/auth/social/google/callback?' . http_build_query([
            'code' => 'business-authorization-code',
            'state' => $providerQuery['state'],
        ]));

    parse_str(parse_url((string) $callback->headers->get('Location'), PHP_URL_QUERY) ?: '', $frontendQuery);

    // A completed provider callback alone must not leave an abandoned trial or
    // an incomplete business behind. Creation happens only at final exchange.
    $this->assertDatabaseMissing('users', ['email' => 'social-owner@example.com']);
    $this->assertDatabaseCount('businesses', 0);

    $this->postJson('/api/auth/social/exchange', [
        'code' => $frontendQuery['code'],
    ])->assertUnprocessable();
    $this->assertDatabaseCount('businesses', 0);

    $this->postJson('/api/auth/social/exchange', [
        'code' => $frontendQuery['code'],
        'business_name' => 'Social Beauty Studio',
        'business_phone' => '098408879',
        'business_address' => 'Երևան, Ամիրյան փողոց 1',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['latitude', 'longitude']);
    $this->assertDatabaseCount('businesses', 0);

    $exchange = $this->postJson('/api/auth/social/exchange', [
        'code' => $frontendQuery['code'],
        'business_name' => 'Social Beauty Studio',
        'business_phone' => '098408879',
        'business_address' => 'Երևան, Ամիրյան փողոց 1',
        'latitude' => 40.179186,
        'longitude' => 44.513134,
        'business_category_slug' => 'beauty-salon',
    ]);

    $exchange
        ->assertOk()
        ->assertJsonPath('user.email', 'social-owner@example.com')
        ->assertJsonPath('user.business_name', 'Social Beauty Studio')
        ->assertJsonPath('user.audience', 'business')
        ->assertJsonPath('user.needs_onboarding', true);

    $businessId = $exchange->json('user.business_id');
    $this->assertDatabaseHas('businesses', [
        'id' => $businessId,
        'name' => 'Social Beauty Studio',
        'phone' => '+37498408879',
        'address' => 'Երևան, Ամիրյան փողոց 1',
        'business_category_id' => $category->id,
    ]);
    $this->assertDatabaseHas('business_locations', [
        'business_id' => $businessId,
        'address' => 'Երևան, Ամիրյան փողոց 1',
        'latitude' => 40.179186,
        'longitude' => 44.513134,
        'phone' => '+37498408879',
        'is_primary' => true,
    ]);
    $this->assertDatabaseHas('subscriptions', [
        'business_id' => $businessId,
        'status' => 'trialing',
    ]);
    $this->assertDatabaseHas('users', [
        'email' => 'social-owner@example.com',
        'show_in_public_team' => true,
        'is_bookable' => true,
    ]);
});
