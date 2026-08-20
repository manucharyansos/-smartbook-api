<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\OwnerRegistered;
use App\Models\Business;
use App\Models\ClientAccount;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TrialAttempt;
use App\Models\User;
use App\Services\ClientIdentityLinker;
use App\Support\BusinessVertical;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SocialAuthController extends Controller
{
    private const CONTEXT_COOKIE = 'sb_social_auth_ctx';
    private const EXCHANGE_CACHE_PREFIX = 'sb_social_auth_exchange:';

    /**
     * Lets the SPA display only providers that are enabled and configured on
     * the API. No provider secret is ever exposed.
     */
    public function providers()
    {
        $providers = collect(['google', 'facebook'])
            ->filter(fn (string $provider) => $this->providerIsReady($provider))
            ->values()
            ->all();

        return response()->json([
            'enabled' => $providers !== [],
            'providers' => $providers,
        ])->header('Cache-Control', 'public, max-age=300');
    }

    public function redirect(Request $request, string $provider)
    {
        abort_unless($this->isSupportedProvider($provider), 404);
        $this->ensureProviderReady($provider);

        $data = $request->validate([
            'callback_url' => ['required', 'url'],
            'mode' => ['nullable', 'in:login,register'],
            'audience' => ['nullable', 'in:business,client'],
            'business_type' => ['nullable', 'in:beauty,dental,salon,clinic,services,healthcare'],
            'plan_code' => ['nullable', 'string', 'max:80'],
        ]);

        $callbackUrl = $data['callback_url'];
        if (!$this->isAllowedCallbackUrl($callbackUrl)) {
            throw ValidationException::withMessages([
                'callback_url' => 'Սոցիալական callback URL-ը թույլատրված չէ։',
            ]);
        }

        $audience = $data['audience'] ?? 'client';
        $mode = $data['mode'] ?? 'login';
        $businessType = BusinessVertical::canonicalBusinessType(
            $data['business_type'] ?? BusinessVertical::SERVICES
        );
        $planCode = null;

        if ($audience === 'business' && $mode === 'register') {
            $planCode = trim((string) ($data['plan_code'] ?? 'start')) ?: 'start';
            if (!$this->selfServePlan($planCode, $businessType)) {
                throw ValidationException::withMessages([
                    'plan_code' => 'The selected plan is not available for self-service registration.',
                ]);
            }
        }

        $state = Str::random(64);
        $fingerprint = trim((string) ($request->header('X-Device-Fingerprint') ?? $request->query('device_fingerprint', '')));
        $context = [
            'provider' => $provider,
            'state' => $state,
            'callback_url' => $callbackUrl,
            'audience' => $audience,
            'mode' => $mode,
            'business_type' => $businessType,
            'plan_code' => $planCode,
            'fingerprint' => $fingerprint !== '' ? mb_substr($fingerprint, 0, 255) : '',
            'ip' => mb_substr((string) $request->ip(), 0, 255),
            'created_at' => now()->toIso8601String(),
        ];

        $secureCookie = (bool) config('session.secure')
            || $request->isSecure()
            || app()->environment('production');

        $cookie = Cookie::make(
            self::CONTEXT_COOKIE,
            Crypt::encryptString(json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            10,
            '/',
            null,
            (bool) $secureCookie,
            true,
            false,
            'lax'
        );

        return redirect()->away($this->authorizationUrl($provider, $state))->withCookie($cookie);
    }

    public function callback(Request $request, string $provider, ClientIdentityLinker $linker)
    {
        abort_unless($this->isSupportedProvider($provider), 404);
        $this->ensureProviderReady($provider);

        try {
            $context = $this->readContext($request, $provider);
        } catch (\Throwable $e) {
            Log::warning('Social auth context could not be read.', [
                'provider' => $provider,
                'exception' => $e::class,
            ]);

            return $this->redirectToFrontend(
                $this->defaultFrontendCallbackUrl(),
                ['provider' => $provider, 'message' => 'Սոցիալական մուտքի ժամկետը սպառվել է։ Փորձիր նորից։'],
                true
            );
        }

        $receivedState = (string) $request->query('state', '');
        if ($receivedState === '' || !hash_equals((string) $context['state'], $receivedState)) {
            return $this->redirectToFrontend(
                $context['callback_url'],
                [
                    'provider' => $provider,
                    'audience' => $context['audience'],
                    'message' => 'Սոցիալական մուտքի անվտանգության ստուգումը չանցավ։ Փորձիր նորից։',
                ],
                true
            );
        }

        if ($request->filled('error')) {
            return $this->redirectToFrontend(
                $context['callback_url'],
                [
                    'provider' => $provider,
                    'audience' => $context['audience'],
                    'message' => 'Սոցիալական մուտքը չեղարկվեց կամ թույլտվություն չտրվեց։',
                ],
                true
            );
        }

        $authorizationCode = trim((string) $request->query('code', ''));
        if ($authorizationCode === '') {
            return $this->redirectToFrontend(
                $context['callback_url'],
                [
                    'provider' => $provider,
                    'audience' => $context['audience'],
                    'message' => 'Provider-ը authorization code չվերադարձրեց։',
                ],
                true
            );
        }

        try {
            $providerUser = $this->fetchProviderUser($provider, $authorizationCode);
            $providerId = trim((string) ($providerUser['id'] ?? ''));
            $email = $linker->normalizeEmail($providerUser['email'] ?? null);

            if ($providerId === '' || mb_strlen($providerId) > 191) {
                throw ValidationException::withMessages([
                    'social' => 'Provider-ը չվերադարձրեց օգտատիրոջ նույնականացուցիչը։',
                ]);
            }

            if (!$email || mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([
                    'social' => 'Provider-ը email չվերադարձրեց։ Այս պահին social login-ը email է պահանջում։',
                ]);
            }

            if (($context['audience'] ?? 'client') === 'business') {
                [$account, $pendingRegistration, $needsBusinessProfile] = $this->resolveBusinessUser(
                    $provider,
                    $providerUser,
                    $context
                );
            } else {
                $account = $this->resolveClientUser($provider, $providerUser, $linker);
                $pendingRegistration = null;
                $needsBusinessProfile = false;
            }
        } catch (ValidationException $e) {
            return $this->redirectToFrontend(
                $context['callback_url'],
                [
                    'provider' => $provider,
                    'audience' => $context['audience'],
                    'message' => $this->extractValidationMessage($e),
                ],
                true
            );
        } catch (\Throwable $e) {
            Log::error('Social auth provider exchange failed.', [
                'provider' => $provider,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->redirectToFrontend(
                $context['callback_url'],
                [
                    'provider' => $provider,
                    'audience' => $context['audience'],
                    'message' => 'Սոցիալական մուտքը չհաջողվեց։ Փորձիր նորից։',
                ],
                true
            );
        }

        $exchangeCode = Str::random(80);
        Cache::put(self::EXCHANGE_CACHE_PREFIX . $exchangeCode, [
            'account_id' => $account?->id,
            'audience' => $context['audience'],
            'provider' => $provider,
            'mode' => $context['mode'],
            'complete_business_profile' => $needsBusinessProfile,
            'pending_business_registration' => $pendingRegistration,
        ], now()->addMinutes(5));

        return $this->redirectToFrontend(
            $context['callback_url'],
            [
                'code' => $exchangeCode,
                'provider' => $provider,
                'audience' => $context['audience'],
                'mode' => $context['mode'],
            ],
            true
        );
    }

    public function exchange(Request $request)
    {
        $base = $request->validate([
            'code' => ['required', 'string', 'min:32', 'max:120'],
        ]);

        $cacheKey = self::EXCHANGE_CACHE_PREFIX . $base['code'];
        $preview = Cache::get($cacheKey);
        if (!$preview) {
            throw ValidationException::withMessages([
                'code' => 'Social login code-ը սպառված է կամ անվավեր է։',
            ]);
        }

        $businessData = null;
        if (!empty($preview['complete_business_profile'])) {
            $businessData = $request->validate([
                'business_name' => ['required', 'string', 'min:2', 'max:120'],
                'business_phone' => ['required', 'string', 'max:40'],
                'business_address' => ['required', 'string', 'max:255'],
                'latitude' => ['required', 'numeric', 'between:-90,90'],
                'longitude' => ['required', 'numeric', 'between:-180,180'],
            ]);

            $businessData['business_phone'] = Phone::normalizeAM($businessData['business_phone']);
            if (!$businessData['business_phone']) {
                throw ValidationException::withMessages([
                    'business_phone' => 'Հեռախոսահամարը սխալ է։',
                ]);
            }
        }

        // Pull only after all request validation succeeds. The exchange code is
        // single-use and cannot be replayed.
        $payload = Cache::pull($cacheKey);
        if (!$payload) {
            throw ValidationException::withMessages([
                'code' => 'Social login code-ը սպառված է կամ արդեն օգտագործվել է։',
            ]);
        }

        if (($payload['audience'] ?? 'client') === 'business') {
            if (!empty($payload['pending_business_registration'])) {
                if (!$businessData) {
                    throw ValidationException::withMessages([
                        'business' => 'Բիզնեսի տվյալները պարտադիր են։',
                    ]);
                }

                $user = $this->createBusinessUser(
                    (array) $payload['pending_business_registration'],
                    $businessData
                );
                $this->sendOwnerRegisteredMail($user);
            } else {
                $user = User::query()->with('business')->findOrFail((int) $payload['account_id']);
            }

            if ($businessData && empty($payload['pending_business_registration'])) {
                $this->completeBusinessProfile($user, $businessData);
                $user->refresh()->load('business');
                $this->sendOwnerRegisteredMail($user);
            }

            $serialized = $this->serializeBusiness($user);
            $token = $user->createToken('api')->plainTextToken;
        } else {
            $account = ClientAccount::query()->findOrFail((int) $payload['account_id']);
            $serialized = $this->serializeClient($account);
            $token = $account->createToken('client-api')->plainTextToken;
        }

        return response()->json([
            'token' => $token,
            'user' => $serialized,
            'audience' => $payload['audience'],
            'provider' => $payload['provider'],
        ])->header('Cache-Control', 'no-store');
    }

    private function resolveClientUser(string $provider, array $providerUser, ClientIdentityLinker $linker): ClientAccount
    {
        $providerId = trim((string) $providerUser['id']);
        $email = $linker->normalizeEmail($providerUser['email'] ?? null);
        $name = $this->providerName($providerUser, 'Client');
        $avatar = $this->providerAvatar($providerUser);

        $account = ClientAccount::query()
            ->where('provider', $provider)
            ->where('provider_id', $providerId)
            ->first();

        if (!$account && $email) {
            $account = ClientAccount::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        }

        if (!$account) {
            $account = ClientAccount::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
                'provider' => $provider,
                'provider_id' => $providerId,
                'avatar_url' => $avatar,
                'email_verified_at' => now(),
                'last_login_at' => now(),
            ]);
        } else {
            $wasUnverified = !$account->hasVerifiedEmail();
            $account->forceFill([
                'name' => $account->name ?: $name,
                'email' => $account->email ?: $email,
                'provider' => $provider,
                'provider_id' => $providerId,
                'avatar_url' => $avatar ?: $account->avatar_url,
                'email_verified_at' => $account->email_verified_at ?: now(),
                'last_login_at' => now(),
            ])->save();

            // A social provider proves control of the email address. If an
            // unverified local account had claimed it first, invalidate that
            // account's password and sessions before attaching the provider.
            if ($wasUnverified) {
                $account->forceFill(['password' => Hash::make(Str::random(64))])->save();
                $account->tokens()->delete();
            }
        }

        $linker->syncLinkedClients($account);
        return $account->fresh();
    }

    private function resolveBusinessUser(string $provider, array $providerUser, array $context): array
    {
        $providerId = trim((string) $providerUser['id']);
        $email = mb_strtolower(trim((string) $providerUser['email']));
        $name = $this->providerName($providerUser, 'Owner');
        $avatar = $this->providerAvatar($providerUser);

        $user = User::query()
            ->with('business')
            ->where(function ($query) use ($provider, $providerId, $email) {
                $query->where(function ($providerQuery) use ($provider, $providerId) {
                    $providerQuery->where('provider', $provider)
                        ->where('provider_id', $providerId);
                })->orWhereRaw('LOWER(email) = ?', [$email]);
            })
            ->first();

        if (!$user && ($context['mode'] ?? 'login') === 'login') {
            throw ValidationException::withMessages([
                'social' => 'Այս email-ով business account չգտնվեց։ Օգտագործիր business register-ը։',
            ]);
        }

        if (!$user) {
            return [
                null,
                [
                    'provider' => $provider,
                    'provider_id' => $providerId,
                    'email' => $email,
                    'name' => $name,
                    'avatar_url' => $avatar,
                    'business_type' => BusinessVertical::normalize(
                        $context['business_type'] ?? BusinessVertical::SERVICES
                    ),
                    'plan_code' => trim((string) ($context['plan_code'] ?? 'start')) ?: 'start',
                    'fingerprint' => ($context['fingerprint'] ?? '') !== '' ? $context['fingerprint'] : null,
                    'ip' => ($context['ip'] ?? '') !== '' ? $context['ip'] : null,
                ],
                true,
            ];
        }

        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'social' => 'Քո հաշիվը անջատված է։ Կապ հաստատիր ադմինի հետ։',
            ]);
        }

        $user->forceFill([
            'name' => $user->name ?: $name,
            'provider' => $provider,
            'provider_id' => $providerId,
            'avatar_url' => $avatar ?: $user->avatar_url,
            'email_verified_at' => $user->email_verified_at ?: now(),
        ])->save();

        $needsBusinessProfile = ($context['mode'] ?? 'login') === 'register'
            && $this->businessProfileNeedsCompletion($user);

        return [$user->fresh()->load('business'), null, $needsBusinessProfile];
    }

    private function createBusinessUser(array $registration, array $data): User
    {
        $vertical = BusinessVertical::normalize(
            $registration['business_type'] ?? BusinessVertical::SERVICES
        );
        $planCode = trim((string) ($registration['plan_code'] ?? 'start')) ?: 'start';
        $plan = $this->selfServePlan($planCode, $vertical);
        if (!$plan) {
            throw ValidationException::withMessages([
                'plan_code' => 'The selected plan is not available for self-service registration.',
            ]);
        }

        return DB::transaction(function () use ($registration, $data, $vertical, $plan) {
            $businessName = trim($data['business_name']);
            $business = Business::query()->create([
                'name' => $businessName,
                'slug' => $this->uniqueBusinessSlug($businessName),
                'business_type' => $vertical,
                'vertical' => $vertical,
                'phone' => $data['business_phone'],
                'address' => trim($data['business_address']),
                'status' => 'active',
            ]);

            $location = $business->locations()->create([
                'name' => $businessName,
                'address' => $business->address,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'phone' => $business->phone,
                'is_primary' => true,
                'is_active' => true,
                'sort_order' => 1,
            ]);

            $this->createDefaultWorkingHours($business);

            $user = User::query()->create([
                'name' => $registration['name'],
                'email' => $registration['email'],
                'password' => Hash::make(Str::random(40)),
                'role' => User::ROLE_OWNER,
                'business_id' => $business->id,
                'location_id' => $location->id,
                'provider' => $registration['provider'],
                'provider_id' => $registration['provider_id'],
                'avatar_url' => $registration['avatar_url'] ?? null,
                'email_verified_at' => now(),
                'show_in_public_team' => true,
                'is_bookable' => true,
            ]);

            $subscription = new Subscription([
                'business_id' => $business->id,
                'status' => Subscription::STATUS_TRIALING,
                'trial_ends_at' => now()->addDays($this->trialDays()),
            ]);
            $subscription->applyPlanSnapshot($plan);
            $subscription->save();

            TrialAttempt::query()->create([
                'phone_norm' => $data['business_phone'],
                'fingerprint' => $registration['fingerprint'] ?? null,
                'email' => $registration['email'],
                'ip' => $registration['ip'] ?? null,
            ]);

            return $user->load('business');
        });
    }

    private function businessProfileNeedsCompletion(User $user): bool
    {
        $business = $user->business;

        if (!$business) {
            return true;
        }

        $primaryLocation = $business->locations()->where('is_primary', true)->first();

        return trim((string) $business->phone) === ''
            || trim((string) $business->address) === ''
            || !$primaryLocation
            || $primaryLocation->latitude === null
            || $primaryLocation->longitude === null;
    }

    private function providerName(array $providerUser, string $fallback): string
    {
        $name = trim((string) ($providerUser['name'] ?? $providerUser['nickname'] ?? $fallback));

        return mb_substr($name !== '' ? $name : $fallback, 0, 120);
    }

    private function providerAvatar(array $providerUser): ?string
    {
        $avatar = trim((string) ($providerUser['avatar'] ?? ''));
        $scheme = strtolower((string) parse_url($avatar, PHP_URL_SCHEME));
        if (
            $avatar === ''
            || !filter_var($avatar, FILTER_VALIDATE_URL)
            || !in_array($scheme, ['http', 'https'], true)
        ) {
            return null;
        }

        return mb_substr($avatar, 0, 255);
    }

    private function trialDays(): int
    {
        $days = (int) config('billing.trial_days', 14);

        return $days >= 1 && $days <= 30 ? $days : 14;
    }

    private function completeBusinessProfile(User $user, array $data): void
    {
        $business = $user->business;
        if (!$business) {
            throw ValidationException::withMessages(['business' => 'Business account-ը չի գտնվել։']);
        }

        DB::transaction(function () use ($business, $user, $data) {
            $business->forceFill([
                'name' => trim($data['business_name']),
                'slug' => $this->uniqueBusinessSlug(trim($data['business_name']), (int) $business->id),
                'phone' => $data['business_phone'],
                'address' => trim($data['business_address']),
            ])->save();

            $location = $business->locations()->where('is_primary', true)->first()
                ?? $business->locations()->orderBy('sort_order')->orderBy('id')->first();

            $locationData = [
                'name' => $business->name,
                'address' => $business->address,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'phone' => $business->phone,
                'is_primary' => true,
                'is_active' => true,
                'sort_order' => 1,
            ];

            if ($location) {
                $location->forceFill($locationData)->save();
            } else {
                $location = $business->locations()->create($locationData);
            }

            $user->forceFill(['location_id' => $location->id])->save();

            $trialAttemptId = TrialAttempt::query()
                ->where('email', $user->email)
                ->whereNull('phone_norm')
                ->latest('id')
                ->value('id');

            if ($trialAttemptId) {
                TrialAttempt::query()
                    ->whereKey($trialAttemptId)
                    ->update(['phone_norm' => $data['business_phone']]);
            }
        });
    }

    private function createDefaultWorkingHours(Business $business): void
    {
        $rows = [];
        foreach (range(1, 7) as $weekday) {
            $closed = $weekday === 7;
            $rows[] = [
                'business_id' => $business->id,
                'weekday' => $weekday,
                'is_closed' => $closed,
                'start' => $closed ? null : '09:00',
                'end' => $closed ? null : '18:00',
                'break_start' => null,
                'break_end' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('business_working_hours')->insert($rows);
    }

    private function sendOwnerRegisteredMail(User $user): void
    {
        try {
            Mail::to($user->email)->send(new OwnerRegistered(
                $user,
                $user->business,
                $this->trialDays()
            ));
        } catch (\Throwable $e) {
            Log::warning('Social business registration email failed.', [
                'user_id' => $user->id,
                'exception' => $e::class,
            ]);
        }
    }

    private function fetchProviderUser(string $provider, string $authorizationCode): array
    {
        $redirectUri = $this->providerRedirectUri($provider);

        if ($provider === 'google') {
            $tokenResponse = Http::asForm()
                ->acceptJson()
                ->timeout(15)
                ->post((string) config('services.google.token_url'), [
                    'client_id' => config('services.google.client_id'),
                    'client_secret' => config('services.google.client_secret'),
                    'code' => $authorizationCode,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $redirectUri,
                ]);

            $tokenResponse->throw();
            $accessToken = trim((string) $tokenResponse->json('access_token', ''));
            if ($accessToken === '') {
                throw new HttpException(502, 'Google did not return an access token.');
            }

            $profileResponse = Http::acceptJson()
                ->withToken($accessToken)
                ->timeout(15)
                ->get((string) config('services.google.userinfo_url'));
            $profileResponse->throw();

            if (!filter_var($profileResponse->json('email_verified', false), FILTER_VALIDATE_BOOL)) {
                throw ValidationException::withMessages([
                    'social' => 'Google հաշվի email-ը հաստատված չէ։',
                ]);
            }

            return [
                'id' => $profileResponse->json('sub'),
                'email' => $profileResponse->json('email'),
                'name' => $profileResponse->json('name'),
                'nickname' => $profileResponse->json('given_name'),
                'avatar' => $profileResponse->json('picture'),
            ];
        }

        $tokenResponse = Http::acceptJson()
            ->timeout(15)
            ->get((string) config('services.facebook.token_url'), [
                'client_id' => config('services.facebook.client_id'),
                'client_secret' => config('services.facebook.client_secret'),
                'code' => $authorizationCode,
                'redirect_uri' => $redirectUri,
            ]);

        $tokenResponse->throw();
        $accessToken = trim((string) $tokenResponse->json('access_token', ''));
        if ($accessToken === '') {
            throw new HttpException(502, 'Facebook did not return an access token.');
        }

        $profileResponse = Http::acceptJson()
            ->withToken($accessToken)
            ->timeout(15)
            ->get((string) config('services.facebook.userinfo_url'), [
                'fields' => 'id,name,email,picture.type(large)',
            ]);
        $profileResponse->throw();

        return [
            'id' => $profileResponse->json('id'),
            'email' => $profileResponse->json('email'),
            'name' => $profileResponse->json('name'),
            'nickname' => null,
            'avatar' => $profileResponse->json('picture.data.url'),
        ];
    }

    private function authorizationUrl(string $provider, string $state): string
    {
        $redirectUri = $this->providerRedirectUri($provider);

        if ($provider === 'google') {
            $params = [
                'client_id' => config('services.google.client_id'),
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => 'openid profile email',
                'state' => $state,
                'include_granted_scopes' => 'true',
                'prompt' => 'select_account',
            ];

            return config('services.google.authorize_url') . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        $params = [
            'client_id' => config('services.facebook.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'email,public_profile',
            'state' => $state,
        ];

        return config('services.facebook.authorize_url') . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function providerRedirectUri(string $provider): string
    {
        $configured = trim((string) config("services.{$provider}.redirect"));
        if ($configured === '') {
            $configured = "/api/auth/social/{$provider}/callback";
        }

        if (filter_var($configured, FILTER_VALIDATE_URL)) {
            return $configured;
        }

        return rtrim((string) config('app.url'), '/') . '/' . ltrim($configured, '/');
    }

    private function providerIsReady(string $provider): bool
    {
        $redirectUri = $this->isSupportedProvider($provider)
            ? $this->providerRedirectUri($provider)
            : '';

        return $this->isSupportedProvider($provider)
            && (bool) config('services.social_auth.enabled')
            && (bool) config("services.social_auth.providers.{$provider}.enabled")
            && trim((string) config("services.{$provider}.client_id")) !== ''
            && trim((string) config("services.{$provider}.client_secret")) !== ''
            && filter_var($redirectUri, FILTER_VALIDATE_URL)
            && (!app()->environment('production') || str_starts_with($redirectUri, 'https://'));
    }

    private function ensureProviderReady(string $provider): void
    {
        if (!$this->providerIsReady($provider)) {
            throw new HttpException(503, 'Social auth is not configured for this provider.');
        }
    }

    private function isSupportedProvider(string $provider): bool
    {
        return in_array($provider, ['google', 'facebook'], true);
    }

    private function readContext(Request $request, string $provider): array
    {
        $raw = $request->cookie(self::CONTEXT_COOKIE);
        if (!$raw) {
            throw ValidationException::withMessages([
                'social' => 'Սոցիալական auth context-ը չի գտնվել։ Սկսիր login-ը նորից։',
            ]);
        }

        $decoded = json_decode(Crypt::decryptString($raw), true, 512, JSON_THROW_ON_ERROR);
        if (
            !is_array($decoded)
            || ($decoded['provider'] ?? null) !== $provider
            || empty($decoded['state'])
            || empty($decoded['callback_url'])
            || empty($decoded['created_at'])
            || Carbon::parse($decoded['created_at'])->lt(now()->subMinutes(10))
        ) {
            throw ValidationException::withMessages([
                'social' => 'Սոցիալական auth context-ը անվավեր է։',
            ]);
        }

        return $decoded;
    }

    private function redirectToFrontend(string $callbackUrl, array $params, bool $forgetCookie = false)
    {
        $url = $callbackUrl . (str_contains($callbackUrl, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $response = redirect()->away($url);

        if ($forgetCookie) {
            $response->withCookie(Cookie::forget(self::CONTEXT_COOKIE, '/', null));
        }

        return $response;
    }

    private function extractValidationMessage(ValidationException $e): string
    {
        $errors = $e->errors();
        if ($errors !== []) {
            $first = reset($errors);
            if (is_array($first) && !empty($first[0])) {
                return (string) $first[0];
            }
        }

        return 'Սոցիալական մուտքը չհաջողվեց։';
    }

    private function isAllowedCallbackUrl(string $callbackUrl): bool
    {
        $target = parse_url($callbackUrl);
        if (
            !$target
            || empty($target['scheme'])
            || empty($target['host'])
            || isset($target['query'])
            || isset($target['fragment'])
            || isset($target['user'])
            || isset($target['pass'])
        ) {
            return false;
        }

        if (rtrim((string) ($target['path'] ?? ''), '/') !== '/auth/social/callback') {
            return false;
        }

        $allowed = array_filter(array_map('trim', config('services.social_auth.frontend_urls', [])));
        if ($allowed === []) {
            $allowed = array_filter([(string) config('app.frontend_url', '')]);
        }

        foreach ($allowed as $url) {
            $parsed = parse_url($url);
            if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
                continue;
            }

            if (
                strtolower($target['scheme']) === strtolower($parsed['scheme'])
                && strtolower($target['host']) === strtolower($parsed['host'])
                && (string) ($target['port'] ?? '') === (string) ($parsed['port'] ?? '')
            ) {
                return true;
            }
        }

        return false;
    }

    private function defaultFrontendCallbackUrl(): string
    {
        return rtrim((string) config('app.frontend_url', 'https://vizit.am'), '/') . '/auth/social/callback';
    }

    private function serializeClient(ClientAccount $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'email' => $account->email,
            'phone' => $account->phone,
            'role' => ClientAccount::ROLE,
            'audience' => 'client',
            'business_id' => null,
            'business_slug' => null,
            'business_type' => null,
            'needs_onboarding' => false,
            'email_verified' => $account->hasVerifiedEmail(),
            'requires_email_verification' => !$account->hasVerifiedEmail(),
        ];
    }

    private function serializeBusiness(User $user): array
    {
        $business = $user->business;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'audience' => 'business',
            'business_id' => $user->business_id,
            'business_name' => $business?->name,
            'business_slug' => $business?->slug,
            'business_type' => $business?->business_type,
            'vertical' => $business?->normalizedVertical(),
            'needs_onboarding' => !$business || !$business->is_onboarding_completed,
            'billing_status' => $business?->billing_status ?? 'active',
            'is_billable' => ($business?->billing_status ?? 'active') === 'active',
        ];
    }

    private function uniqueBusinessSlug(string $businessName, ?int $ignoreBusinessId = null): string
    {
        $baseSlug = Str::slug($businessName) ?: 'business';
        $slug = $baseSlug;
        $counter = 1;

        while (Business::query()
            ->where('slug', $slug)
            ->when($ignoreBusinessId, fn ($query) => $query->where('id', '!=', $ignoreBusinessId))
            ->exists()) {
            $counter++;
            $slug = $baseSlug . '-' . $counter;
        }

        return $slug;
    }

    private function selfServePlan(string $code, string $businessType): ?Plan
    {
        $plan = Plan::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->where('is_visible', true)
            ->first();

        if (!$plan || !$plan->isSelfServe() || !$plan->supportsBusinessType($businessType)) {
            return null;
        }

        return $plan;
    }
}
