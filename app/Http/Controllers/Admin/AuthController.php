<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // Log the request for debugging
        Log::info('Admin login attempt', ['email' => $request->email]);

        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Find admin by email
        $admin = Admin::where('email', $request->email)->first();

        // Log if admin found
        if (!$admin) {
            Log::warning('Admin not found', ['email' => $request->email]);
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Check password
        if (!Hash::check($request->password, $admin->password)) {
            Log::warning('Invalid password for admin', ['email' => $request->email]);
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Check if active
        if (!$admin->is_active) {
            Log::warning('Inactive admin attempted login', ['email' => $request->email]);
            return response()->json([
                'message' => 'Account is deactivated. Contact super admin.'
            ], 403);
        }

        // Update last login
        $admin->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        // Create token
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
}
