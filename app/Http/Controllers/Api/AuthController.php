<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\OwnerRegistered;
use App\Models\Business;
use App\Models\BusinessCategory;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TrialAttempt;
use App\Models\User;
use App\Support\BusinessVertical;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/auth/forgot-password
     * Sends reset link to email (uses Laravel notifications).
     */
    public function forgotPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            $email = mb_strtolower(trim($data['email']));
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($user) {
                Password::sendResetLink(['email' => $user->email]);
            }
        } catch (\Throwable $e) {
            Log::error('Password reset email could not be sent.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        // Always return the same response so this endpoint cannot be used to
        // discover which email addresses have a Vizit account.
        return response()->json([
            'ok' => true,
            'message' => 'If the account exists, a password reset link has been sent.',
        ]);
    }

    /**
     * POST /api/auth/reset-password
     */
    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        $status = Password::reset(
            [
                'email' => $user?->email ?? $email,
                'password' => $data['password'],
                'password_confirmation' => $data['password_confirmation'],
                'token' => $data['token'],
            ],
            function ($user) use ($data) {
                $user->forceFill([
                    'password' => Hash::make($data['password']),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => __($status),
            ], 422);
        }

        return response()->json([
            'message' => __($status),
        ]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required','email'],
            'password' => ['required','string'],
        ]);

        $email = mb_strtolower(trim($data['email']));
        $user = User::with('business:id,name,slug,is_onboarding_completed,business_type,vertical,business_category_id,custom_category_name')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Invalid credentials']);
        }

        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'Your account is disabled. Please contact admin.'
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'audience' => 'business',
                'business_id' => $user->business_id,
                'business_name' => $user->business?->name,
                'business_slug' => $user->business?->slug,
                'business_type' => $user->business?->business_type,
                'vertical' => $user->business?->normalizedVertical(),
                'business_category_id' => $user->business?->business_category_id,
                'custom_category_name' => $user->business?->custom_category_name,
                'needs_onboarding' => $user->business ? !$user->business->is_onboarding_completed : true,
            ],
        ]);
    }

    public function register(Request $request)
    {
        if ($request->filled('email')) {
            $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        }

        // Fingerprint should be generated on frontend and passed as header or field.
        $fingerprint = (string)($request->header('X-Device-Fingerprint') ?? $request->input('device_fingerprint') ?? '');
        $fingerprint = trim($fingerprint);
        if ($fingerprint === '') $fingerprint = null;

        $data = $request->validate([
            'business_name' => ['required','string','min:2','max:120'],
            'business_phone' => ['required','string','max:40'],
            'business_address' => ['required','string','max:255'],
            'business_city' => ['nullable','string','max:120'],
            'business_district' => ['nullable','string','max:120'],
            'latitude' => ['required','numeric','between:-90,90'],
            'longitude' => ['required','numeric','between:-180,180'],
            'business_type' => ['nullable','string','in:beauty,dental,salon,clinic,services,healthcare'],
            'vertical' => ['nullable','string','in:services,healthcare'],
            'business_category_id' => ['nullable','integer','exists:business_categories,id'],
            'business_category_slug' => ['nullable','string','max:120'],
            'custom_category_name' => ['nullable','string','max:120'],
            'plan_code' => ['nullable','string','max:80'],

            'name' => ['required','string','min:2','max:120'],
            'email' => ['required','email','max:255','unique:users,email'],
            'password' => ['required','string','min:8','max:255','confirmed'],
        ]);

        $phoneNorm = Phone::normalizeAM($data['business_phone'] ?? null);
        if (!$phoneNorm) {
            throw ValidationException::withMessages(['business_phone' => 'Invalid phone number']);
        }

        // We still log trial attempts for diagnostics, but do not hard-block
        // legitimate registrations that reuse the same business phone number.
        // In production this is safer UX than locking an entire salon out after
        // a previous test signup.

        $trialDays = (int) config('billing.trial_days', 14);
        if ($trialDays < 1 || $trialDays > 30) $trialDays = 14;

        $vertical = BusinessVertical::normalize($data['vertical'] ?? $data['business_type'] ?? null);
        // Canonical value: services | healthcare. Specific type (for example car-wash)
        // belongs to business_category_id, not to the legacy beauty/dental field.
        $businessType = $vertical;

        $requestedPlanCode = trim((string) ($data['plan_code'] ?? 'start')) ?: 'start';
        $plan = Plan::query()
            ->where('code', $requestedPlanCode)
            ->where('is_active', true)
            ->where('is_visible', true)
            ->first();

        if (!$plan || !$plan->isSelfServe() || !$plan->supportsBusinessType($businessType)) {
            throw ValidationException::withMessages([
                'plan_code' => 'The selected plan is not available for self-service registration.',
            ]);
        }

        $category = null;
        if (!empty($data['business_category_id'])) {
            $category = BusinessCategory::query()
                ->forVertical($vertical)
                ->whereKey((int) $data['business_category_id'])
                ->first();
        } elseif (!empty($data['business_category_slug'])) {
            $category = BusinessCategory::query()
                ->forVertical($vertical)
                ->where('slug', $data['business_category_slug'])
                ->first();
        }

        if ((!empty($data['business_category_id']) || !empty($data['business_category_slug'])) && !$category) {
            throw ValidationException::withMessages([
                'business_category_slug' => 'The selected category does not belong to this business area.',
            ]);
        }

        $categoryId = $category?->id;
        $customCategoryName = trim((string) ($data['custom_category_name'] ?? ''));
        if ($category && str_starts_with($category->slug, 'other-') && $customCategoryName === '') {
            throw ValidationException::withMessages([
                'custom_category_name' => 'Please specify the business category.',
            ]);
        }
        $customCategoryName = $category && str_starts_with($category->slug, 'other-')
            ? $customCategoryName
            : null;

        $out = DB::transaction(function () use ($data, $phoneNorm, $fingerprint, $request, $trialDays, $vertical, $businessType, $categoryId, $customCategoryName, $plan) {
            // unique slug
            $baseSlug = Str::slug($data['business_name']);
            $slug = $baseSlug ?: 'business';
            $i = 1;
            while (Business::where('slug', $slug)->exists()) {
                $i++;
                $slug = ($baseSlug ?: 'business') . '-' . $i;
            }

            $business = Business::create([
                'name' => $data['business_name'],
                'slug' => $slug,
                'business_type' => $businessType,
                'vertical' => $vertical,
                'business_category_id' => $categoryId,
                'custom_category_name' => $customCategoryName,
                'phone' => $phoneNorm,
                'address' => $data['business_address'] ?? null,
                'status' => 'active',
            ]);

            $primaryLocation = $business->locations()->create([
                'name' => $business->name,
                'address' => $data['business_address'],
                'city' => $data['business_city'] ?? null,
                'district' => $data['business_district'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'phone' => $phoneNorm,
                'is_primary' => true,
                'is_active' => true,
                'sort_order' => 1,
            ]);

            // working hours defaults
            $defaults = [
                1 => ['09:00','18:00', false],
                2 => ['09:00','18:00', false],
                3 => ['09:00','18:00', false],
                4 => ['09:00','18:00', false],
                5 => ['09:00','18:00', false],
                6 => ['09:00','18:00', false],
                7 => [null, null, true],
            ];

            foreach ($defaults as $weekday => [$start,$end,$closed]) {
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

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => User::ROLE_OWNER,
                'business_id' => $business->id,
                'location_id' => $primaryLocation->id,
                'show_in_public_team' => true,
                'is_bookable' => true,
            ]);

            $sub = new Subscription([
                'business_id' => $business->id,
                'status' => Subscription::STATUS_TRIALING,
                'trial_ends_at' => now()->addDays($trialDays),
            ]);

            // ✅ snapshot features/seats from the selected plan
            $sub->applyPlanSnapshot($plan);

            $sub->save();

            TrialAttempt::query()->create([
                'phone_norm' => $phoneNorm,
                'fingerprint' => $fingerprint,
                'email' => $data['email'],
                'ip' => (string)$request->ip(),
            ]);

            return [$user, $business, $sub];
        });

        /** @var User $user */
        /** @var Business $business */
        [$user, $business, $sub] = $out;

        // Send email to owner
        try {
            Mail::to($user->email)->send(new OwnerRegistered($user, $business, $trialDays));
        } catch (\Throwable $e) {
            report($e);
            // Registration must still succeed when mail delivery is temporarily unavailable.
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'audience' => 'business',
                'business_id' => $user->business_id,
                'business_name' => $user->business?->name,
                'business_slug' => $user->business?->slug,
                'business_type' => $user->business?->business_type,
                'vertical' => $user->business?->normalizedVertical(),
                'business_category_id' => $user->business?->business_category_id,
                'custom_category_name' => $user->business?->custom_category_name,
                'needs_onboarding' => !$user->business?->is_onboarding_completed,
            ],
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load('business:id,name,slug,is_onboarding_completed,business_type,vertical,business_category_id,custom_category_name');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'audience' => 'business',
                'business_id' => $user->business_id,
                'business_name' => $user->business?->name,
                'business_slug' => $user->business?->slug,
                'business_type' => $user->business?->business_type,
                'vertical' => $user->business?->normalizedVertical(),
                'business_category_id' => $user->business?->business_category_id,
                'custom_category_name' => $user->business?->custom_category_name,
                'needs_onboarding' => !$user->business || !$user->business->is_onboarding_completed,
                'billing_status' => $user->business?->billing_status ?? 'active',
                'is_billable' => ($user->business?->billing_status ?? 'active') === 'active',
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['ok' => true]);
    }
}
