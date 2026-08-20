<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\ClientIdentityLinker;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClientAuthController extends Controller
{
    public function register(Request $request, ClientIdentityLinker $linker)
    {
        if ($request->filled('email')) {
            $request->merge(['email' => $linker->normalizeEmail($request->input('email'))]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['nullable', 'email', 'max:190', 'unique:client_accounts,email'],
            'phone' => ['nullable', 'string', 'min:5', 'max:40', 'unique:client_accounts,phone'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        if (empty($data['email']) && empty($data['phone'])) {
            throw ValidationException::withMessages([
                'email' => 'Նշիր էլ. փոստ կամ հեռախոսահամար։',
            ]);
        }

        $account = ClientAccount::query()->create([
            'name' => $data['name'],
            'email' => $linker->normalizeEmail($data['email'] ?? null),
            'phone' => $linker->normalizePhone($data['phone'] ?? null),
            'password' => Hash::make($data['password']),
        ]);

        $linker->syncLinkedClients($account);
        $account->forceFill(['last_login_at' => now()])->save();

        $token = $account->createToken('client-api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->serialize($account),
        ]);
    }

    public function login(Request $request, ClientIdentityLinker $linker)
    {
        $data = $request->validate([
            'identity' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string'],
        ]);

        $identity = trim($data['identity']);
        $email = $linker->normalizeEmail($identity);
        $phone = $linker->normalizePhone($identity);

        $account = ClientAccount::query()
            ->where(function ($q) use ($email, $phone, $identity) {
                if ($email) {
                    $q->orWhereRaw('LOWER(email) = ?', [$email]);
                }
                if ($phone) {
                    $q->orWhere('phone', $phone);
                }
                $q->orWhere('email', $identity)
                  ->orWhere('phone', $identity);
            })
            ->first();

        if (!$account || !$account->password || !Hash::check($data['password'], $account->password)) {
            throw ValidationException::withMessages([
                'identity' => 'Սխալ email/հեռախոս կամ գաղտնաբառ։',
            ]);
        }

        $linker->syncLinkedClients($account);
        $account->forceFill(['last_login_at' => now()])->save();

        $token = $account->createToken('client-api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->serialize($account),
        ]);
    }

    public function forgotPassword(Request $request, ClientIdentityLinker $linker)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            $email = $linker->normalizeEmail($data['email']);
            $account = ClientAccount::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            if ($account) {
                Password::broker('clients')->sendResetLink(['email' => $account->email]);
            }
        } catch (\Throwable $e) {
            Log::error('Client password reset email could not be sent.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'message' => 'If the account exists, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request, ClientIdentityLinker $linker)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $email = $linker->normalizeEmail($data['email']);
        $account = ClientAccount::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        $status = Password::broker('clients')->reset(
            [
                'email' => $account?->email ?? $email,
                'password' => $data['password'],
                'password_confirmation' => $data['password_confirmation'],
                'token' => $data['token'],
            ],
            function (ClientAccount $clientAccount) use ($data) {
                $clientAccount->forceFill([
                    'password' => Hash::make($data['password']),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($clientAccount));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        return response()->json(['message' => __($status)]);
    }

    public function me(Request $request)
    {
        /** @var ClientAccount $account */
        $account = $request->user();

        return response()->json([
            'user' => $this->serialize($account),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    protected function serialize(ClientAccount $account): array
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
}
