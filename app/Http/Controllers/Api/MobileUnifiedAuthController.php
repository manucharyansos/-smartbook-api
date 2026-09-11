<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\ClientIdentityLinker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MobileUnifiedAuthController extends Controller
{
    public function login(Request $request, ClientIdentityLinker $linker)
    {
        $data = $request->validate([
            'identity' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string'],
            'audience' => ['nullable', 'string', 'in:client,business'],
        ]);

        $identity = trim($data['identity']);
        $password = $data['password'];
        $requestedAudience = $data['audience'] ?? null;
        $normalizedEmail = $linker->normalizeEmail($identity);
        $normalizedPhone = $linker->normalizePhone($identity);

        $businessUser = null;
        if ($normalizedEmail) {
            $businessUser = User::with('business:id,name,slug,is_onboarding_completed,business_type,vertical,business_category_id,custom_category_name')
                ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
                ->first();
        }

        $clientAccount = ClientAccount::query()
            ->where(function ($query) use ($normalizedEmail, $normalizedPhone, $identity) {
                if ($normalizedEmail) {
                    $query->orWhereRaw('LOWER(email) = ?', [$normalizedEmail]);
                }
                if ($normalizedPhone) {
                    $query->orWhere('phone', $normalizedPhone);
                }
                $query->orWhere('email', $identity)
                    ->orWhere('phone', $identity);
            })
            ->first();

        $businessPasswordMatches = $businessUser
            && Hash::check($password, $businessUser->password);
        $businessMatches = $businessPasswordMatches && $businessUser->is_active;
        $clientMatches = $clientAccount
            && $clientAccount->password
            && Hash::check($password, $clientAccount->password);

        if ($requestedAudience === 'business') {
            if ($businessPasswordMatches && !$businessUser->is_active) {
                throw ValidationException::withMessages([
                    'identity' => 'Your account is disabled. Please contact admin.',
                ]);
            }
            if (!$businessMatches) {
                $this->invalidCredentials();
            }

            return $this->businessResponse($businessUser);
        }

        if ($requestedAudience === 'client') {
            if (!$clientMatches) {
                $this->invalidCredentials();
            }

            return $this->clientResponse($clientAccount, $linker);
        }

        $audiences = [];
        if ($clientMatches) {
            $audiences[] = 'client';
        }
        if ($businessMatches) {
            $audiences[] = 'business';
        }

        if (count($audiences) === 2) {
            return response()->json([
                'requires_selection' => true,
                'audiences' => $audiences,
            ]);
        }

        if ($clientMatches) {
            return $this->clientResponse($clientAccount, $linker);
        }

        if ($businessMatches) {
            return $this->businessResponse($businessUser);
        }

        if ($businessPasswordMatches && !$businessUser->is_active) {
            throw ValidationException::withMessages([
                'identity' => 'Your account is disabled. Please contact admin.',
            ]);
        }

        $this->invalidCredentials();
    }

    private function clientResponse(ClientAccount $account, ClientIdentityLinker $linker)
    {
        $linker->syncLinkedClients($account);
        $account->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'requires_selection' => false,
            'audience' => 'client',
            'token' => $account->createToken('client-api')->plainTextToken,
            'user' => [
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
            ],
        ]);
    }

    private function businessResponse(User $user)
    {
        return response()->json([
            'requires_selection' => false,
            'audience' => 'business',
            'token' => $user->createToken('api')->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
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

    private function invalidCredentials(): never
    {
        throw ValidationException::withMessages([
            'identity' => 'Invalid credentials',
        ]);
    }
}
