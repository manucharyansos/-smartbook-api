<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\ClientIdentityLinker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ClientAuthController extends Controller
{
    public function register(Request $request, ClientIdentityLinker $linker)
    {
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
                    $q->orWhere('email', $email);
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
