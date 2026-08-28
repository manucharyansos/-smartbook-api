<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        if ($request->filled('email')) {
            $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        }

        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Find admin by email
        $admin = Admin::query()->whereRaw('LOWER(email) = ?', [$request->email])->first();

        if (!$admin) {
            Log::warning('Admin login failed', ['email' => $request->email, 'ip' => $request->ip()]);
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!Hash::check($request->password, $admin->password)) {
            Log::warning('Admin login failed', ['email' => $request->email, 'ip' => $request->ip()]);
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$admin->is_active) {
            Log::warning('Inactive admin attempted login', ['email' => $request->email, 'ip' => $request->ip()]);
            return response()->json([
                'message' => 'Account is deactivated. Contact super admin.'
            ], 403);
        }

        $admin->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        // Keep only the newest browser session for this admin account.
        $admin->tokens()->where('name', 'admin-token')->delete();

        AdminLog::query()->create([
            'admin_id' => $admin->id,
            'action' => 'login',
            'model_type' => Admin::class,
            'model_id' => $admin->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $token = $admin->createToken('admin-token', ['admin:' . $admin->role])->plainTextToken;

        Log::info('Admin logged in successfully', ['email' => $request->email, 'role' => $admin->role]);

        return response()->json([
            'success' => true,
            'data' => [
                'admin' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'role' => $admin->role,
                    'is_active' => $admin->is_active,
                ],
                'token' => $token,
            ]
        ]);
    }

    public function me(Request $request)
    {
        /** @var Admin $admin */
        $admin = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'admin' => $this->serializeAdmin($admin),
            ],
        ]);
    }

    public function logout(Request $request)
    {
        /** @var Admin|null $admin */
        $admin = $request->user();

        $currentToken = $admin?->currentAccessToken();
        if ($currentToken instanceof PersonalAccessToken) {
            $currentToken->delete();
        } elseif ($admin && $request->bearerToken()) {
            // Resolve the bearer token explicitly as a safe fallback. This also
            // handles long-lived workers and tests where the guard instance was
            // resolved before currentAccessToken() was attached to the model.
            $resolvedToken = PersonalAccessToken::findToken($request->bearerToken());
            if (
                $resolvedToken
                && $resolvedToken->tokenable_type === $admin::class
                && (int) $resolvedToken->tokenable_id === (int) $admin->id
            ) {
                $resolvedToken->delete();
            }
        }

        if ($admin) {
            AdminLog::query()->create([
                'admin_id' => $admin->id,
                'action' => 'logout',
                'model_type' => Admin::class,
                'model_id' => $admin->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        // Never let an already-resolved Sanctum guard retain the revoked token
        // for a subsequent request in the same application worker.
        Auth::forgetGuards();

        return response()->json(['success' => true, 'ok' => true]);
    }

    private function serializeAdmin(Admin $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->role,
            'is_active' => (bool) $admin->is_active,
            'last_login_at' => $admin->last_login_at?->toISOString(),
        ];
    }
}
