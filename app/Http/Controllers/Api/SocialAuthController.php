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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SocialAuthController extends Controller
{
    private const CONTEXT_COOKIE = 'sb_social_auth_ctx';
    private const EXCHANGE_CACHE_PREFIX = 'sb_social_auth_exchange:';

    public function redirect(Request $request, string $provider)
    {
        abort_unless($this->isSupportedProvider($provider), 404);
        $this->ensureProviderEnabled($provider);
        $this->ensureSocialiteInstalled();

        $data = $request->validate([
            'callback_url' => ['required', 'url'],
            'mode' => ['nullable', 'in:login,register'],
            'audience' => ['nullable', 'in:business,client'],
            'business_type' => ['nullable', 'in:beauty,dental,salon,clinic,services,healthcare'],
        ]);

        $callbackUrl = $data['callback_url'];
        if (!$this->isAllowedCallbackUrl($callbackUrl)) {
            throw ValidationException::withMessages([
                'callback_url' => 'Սոցիալական callback URL-ը թույլատրված չէ։',
            ]);
        }

        $context = [
            'provider' => $provider,
            'callback_url' => $callbackUrl,
            'audience' => $data['audience'] ?? 'client',
            'mode' => $data['mode'] ?? 'login',
            'business_type' => BusinessVertical::canonicalBusinessType($data['business_type'] ?? BusinessVertical::SERVICES),
            'fingerprint' => trim((string) ($request->header('X-Device-Fingerprint') ?? $request->query('device_fingerprint', ''))),
            'created_at' => now()->toIso8601String(),
        ];

        $cookie = Cookie::make(
            self::CONTEXT_COOKIE,
            Crypt::encryptString(json_encode($context, JSON_UNESCAPED_UNICODE)),
            10,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax'
        );

        $driver = $this->driver($provider);

        return $driver->redirect()->withCookie($cookie);
    }

    public function callback(Request $request, string $provider, ClientIdentityLinker $linker)
    {
        abort_unless($this->isSupportedProvider($provider), 404);
        $this->ensureProviderEnabled($provider);
        $this->ensureSocialiteInstalled();

        $context = $this->readContext($request, $provider);

        try {
            $providerUser = $this->driver($provider)->stateless()->user();
        } catch (\Throwable $e) {
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

        $providerId = trim((string) $providerUser->getId());
        $email = $linker->normalizeEmail($providerUser->getEmail());

        if ($providerId === '') {
            return $this->redirectToFrontend(
                $context['callback_url'],
                [
                    'provider' => $provider,
                    'audience' => $context['audience'],
                    'message' => 'Provider-ը չվերադարձրեց օգտատիրոջ նույնականացուցիչը։',
                ],
                true
            );
        }

        if (!$email) {
            return $this->redirectToFrontend(
                $context['callback_url'],
                [
                    'provider' => $provider,
                    'audience' => $context['audience'],
                    'message' => 'Provider-ը email չվերադարձրեց։ Այս պահին social login-ը email է պահանջում։',
                ],
                true
            );
        }

        try {
            if (($context['audience'] ?? 'client') === 'business') {
                [$token, $user] = $this->resolveBusinessUser($provider, $providerUser, $context);
            } else {
                [$token, $user] = $this->resolveClientUser($provider, $providerUser, $context, $linker);
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
            return $this->redirectToFrontend(
                $context['callback_url'],
                [
                    'provider' => $provider,
                    'audience' => $context['audience'],
                    'message' => 'Սոցիալական մուտքը չհաջողվեց։',
                ],
                true
            );
        }

        $exchangeCode = Str::random(80);
        Cache::put(self::EXCHANGE_CACHE_PREFIX . $exchangeCode, [
            'token' => $token,
            'user' => $user,
            'audience' => $context['audience'],
            'provider' => $provider,
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
        $data = $request->validate([
            'code' => ['required', 'string', 'min:32', 'max:120'],
        ]);

        $payload = Cache::pull(self::EXCHANGE_CACHE_PREFIX . $data['code']);
        if (!$payload) {
            throw ValidationException::withMessages([
                'code' => 'Social login code-ը սպառված է կամ անվավեր է։',
            ]);
        }

        return response()->json($payload);
    }

    private function resolveClientUser(string $provider, $providerUser, array $context, ClientIdentityLinker $linker): array
    {
        $providerId = trim((string) $providerUser->getId());
        $email = $linker->normalizeEmail($providerUser->getEmail());
        $name = trim((string) ($providerUser->getName() ?: $providerUser->getNickname() ?: 'Client'));
        $avatar = $providerUser->getAvatar();

        $account = ClientAccount::query()
            ->where('provider', $provider)
            ->where('provider_id', $providerId)
            ->first();

        if (!$account && $email) {
            $account = ClientAccount::query()->where('email', $email)->first();
        }

        if (!$account && ($context['mode'] ?? 'login') === 'login') {
            // Client social sign-in can create the account on first successful auth.
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
            $account->forceFill([
                'name' => $account->name ?: $name,
                'email' => $account->email ?: $email,
                'provider' => $provider,
                'provider_id' => $providerId,
                'avatar_url' => $avatar ?: $account->avatar_url,
                'email_verified_at' => $account->email_verified_at ?: now(),
                'last_login_at' => now(),
            ])->save();
        }

        $linker->syncLinkedClients($account);

        $token = $account->createToken('client-api')->plainTextToken;

        return [$token, $this->serializeClient($account->fresh())];
    }

    private function resolveBusinessUser(string $provider, $providerUser, array $context): array
    {
        $providerId = trim((string) $providerUser->getId());
        $email = mb_strtolower(trim((string) $providerUser->getEmail()));
        $name = trim((string) ($providerUser->getName() ?: $providerUser->getNickname() ?: 'Owner'));
        $avatar = $providerUser->getAvatar();

        $user = User::query()
            ->with('business:id,name,slug,is_onboarding_completed,business_type,billing_status')
            ->where(function ($query) use ($provider, $providerId, $email) {
                $query->where(function ($providerQuery) use ($provider, $providerId) {
                    $providerQuery->where('provider', $provider)
                        ->where('provider_id', $providerId);
                })->orWhere('email', $email);
            })
            ->first();

        if (!$user && ($context['mode'] ?? 'login') === 'login') {
            throw ValidationException::withMessages([
                'social' => 'Այս email-ով business account չգտնվեց։ Օգտագործիր business register-ը։',
            ]);
        }

        if (!$user) {
            [$user] = DB::transaction(function () use ($name, $email, $avatar, $provider, $providerId, $context) {
                $businessName = $this->guessBusinessName($name);
                $baseSlug = Str::slug($businessName) ?: 'business';
                $slug = $baseSlug;
                $i = 1;
                while (Business::query()->where('slug', $slug)->exists()) {
                    $i++;
                    $slug = $baseSlug . '-' . $i;
                }

                $vertical = BusinessVertical::normalize($context['business_type'] ?? BusinessVertical::SERVICES);

                $business = Business::query()->create([
                    'name' => $businessName,
                    'slug' => $slug,
                    'business_type' => $vertical,
                    'vertical' => $vertical,
                    'status' => 'active',
                ]);

                $defaults = [
                    1 => ['09:00', '18:00', false],
                    2 => ['09:00', '18:00', false],
                    3 => ['09:00', '18:00', false],
                    4 => ['09:00', '18:00', false],
                    5 => ['09:00', '18:00', false],
                    6 => ['09:00', '18:00', false],
                    7 => [null, null, true],
                ];

                foreach ($defaults as $weekday => [$start, $end, $closed]) {
                    DB::table('business_working_hours')->insert([
                        'business_id' => $business->id,
                        'weekday' => $weekday,
                        'is_closed' => $closed,
                        'start' => $start,
                        'end' => $end,
                        'break_start' => null,
                        'break_end' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make(Str::random(40)),
                    'role' => User::ROLE_OWNER,
                    'business_id' => $business->id,
                    'provider' => $provider,
                    'provider_id' => $providerId,
                    'avatar_url' => $avatar,
                    'email_verified_at' => now(),
                ]);

                $plan = Plan::query()->where('code', 'start')->first();

                $subscription = new Subscription([
                    'business_id' => $business->id,
                    'status' => Subscription::STATUS_TRIALING,
                    'trial_ends_at' => now()->addDays((int) config('billing.trial_days', 14) ?: 14),
                ]);

                if ($plan) {
                    $subscription->applyPlanSnapshot($plan);
                } else {
                    $subscription->plan_id = 1;
                }

                $subscription->save();

                TrialAttempt::query()->create([
                    'phone_norm' => null,
                    'fingerprint' => ($context['fingerprint'] ?? '') !== '' ? $context['fingerprint'] : null,
                    'email' => $email,
                    'ip' => request()->ip(),
                ]);

                try {
                    Mail::to($user->email)->send(new OwnerRegistered($user, $business, (int) config('billing.trial_days', 14) ?: 14));
                } catch (\Throwable $e) {
                    // Do not fail signup because of email transport problems.
                }

                return [$user];
            });

            $user->load('business:id,name,slug,is_onboarding_completed,business_type,billing_status');
        } else {
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

            $user->loadMissing('business:id,name,slug,is_onboarding_completed,business_type,billing_status');
        }

        $token = $user->createToken('api')->plainTextToken;

        return [$token, $this->serializeBusiness($user->fresh()->load('business:id,name,slug,is_onboarding_completed,business_type,billing_status'))];
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
        ];
    }

    private function serializeBusiness(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'audience' => 'business',
            'business_id' => $user->business_id,
            'business_name' => $user->business?->name,
            'business_slug' => $user->business?->slug,
            'business_type' => $user->business?->business_type,
            'needs_onboarding' => !$user->business || !$user->business->is_onboarding_completed,
            'billing_status' => $user->business?->billing_status ?? 'active',
            'is_billable' => ($user->business?->billing_status ?? 'active') === 'active',
        ];
    }

    private function guessBusinessName(string $ownerName): string
    {
        $ownerName = trim($ownerName);
        return $ownerName !== '' ? sprintf('%s Studio', $ownerName) : 'My Business';
    }

    private function driver(string $provider)
    {
        $driver = Socialite::driver($provider);

        if ($provider === 'google') {
            return $driver->scopes(['openid', 'profile', 'email']);
        }

        if ($provider === 'facebook') {
            return $driver->scopes(['email']);
        }

        return $driver;
    }

    private function ensureProviderEnabled(string $provider): void
    {
        if (!config('services.social_auth.enabled') || !config("services.social_auth.providers.{$provider}.enabled")) {
            throw new HttpException(503, 'Social auth is disabled for this provider.');
        }
    }

    private function ensureSocialiteInstalled(): void
    {
        if (!class_exists(\Laravel\Socialite\Facades\Socialite::class)) {
            throw new HttpException(503, 'Social auth package is not installed on this deployment.');
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

        $decoded = json_decode(Crypt::decryptString($raw), true);
        if (!is_array($decoded) || ($decoded['provider'] ?? null) !== $provider || empty($decoded['callback_url'])) {
            throw ValidationException::withMessages([
                'social' => 'Սոցիալական auth context-ը անվավեր է։',
            ]);
        }

        return $decoded;
    }

    private function redirectToFrontend(string $callbackUrl, array $params, bool $forgetCookie = false)
    {
        $url = $callbackUrl . (str_contains($callbackUrl, '?') ? '&' : '?') . http_build_query($params);
        $response = redirect()->away($url);

        if ($forgetCookie) {
            $response->withCookie(Cookie::forget(self::CONTEXT_COOKIE, '/', null));
        }

        return $response;
    }

    private function extractValidationMessage(ValidationException $e): string
    {
        $errors = $e->errors();
        if (!empty($errors)) {
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
        if (!$target || empty($target['scheme']) || empty($target['host'])) {
            return false;
        }

        $allowed = array_filter(array_map('trim', config('services.social_auth.frontend_urls', [])));
        if (empty($allowed)) {
            $allowed = array_filter(array_map('trim', [
                (string) config('app.frontend_url', ''),
                (string) env('FRONTEND_APP_URL', ''),
            ]));
        }

        foreach ($allowed as $url) {
            $parsed = parse_url($url);
            if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
                continue;
            }

            $targetPort = $target['port'] ?? null;
            $allowedPort = $parsed['port'] ?? null;

            if (
                strtolower($target['scheme']) === strtolower($parsed['scheme']) &&
                strtolower($target['host']) === strtolower($parsed['host']) &&
                (string) $targetPort === (string) $allowedPort
            ) {
                return true;
            }
        }

        return false;
    }
}
