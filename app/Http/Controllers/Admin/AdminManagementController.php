<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminManagementController extends Controller
{
    /**
     * Display a listing of admins.
     */
    public function index(Request $request)
    {
        $query = Admin::query();

        if ($request->has('search')) {
            $query->where(function($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('email', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->has('role')) {
            $query->where('role', $request->role);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $admins = $query->latest()->paginate(max(1, min(100, $request->integer('per_page', 20))));

        return response()->json([
            'data' => $admins
        ]);
    }

    /**
     * Store a newly created admin.
     */
    public function store(Request $request)
    {
        if ($request->filled('email')) {
            $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:admins',
            'password' => ['required', 'confirmed', Password::min(12)],
            'role' => ['required', Rule::in([
                Admin::ROLE_SUPER_ADMIN,
                Admin::ROLE_ADMIN,
                Admin::ROLE_SUPPORT,
                Admin::ROLE_FINANCE
            ])],
            'is_active' => 'boolean',
        ]);

        $validated['password'] = Hash::make($validated['password']);

        $admin = Admin::create($validated);

        // Log the action
        AdminLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'create_admin',
            'model_type' => Admin::class,
            'model_id' => $admin->id,
            'new_values' => collect($validated)->except('password')->all(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Admin created successfully',
            'data' => $admin
        ], 201);
    }

    /**
     * Display the specified admin.
     */
    public function show($id)
    {
        $admin = Admin::findOrFail($id);

        $recentLogs = AdminLog::where('admin_id', $admin->id)
            ->with('admin')
            ->latest()
            ->limit(20)
            ->get();

        return response()->json([
            'data' => [
                'admin' => $admin,
                'recent_logs' => $recentLogs
            ]
        ]);
    }

    /**
     * Update the specified admin.
     */
    public function update(Request $request, $id)
    {
        $admin = Admin::findOrFail($id);

        if ($request->filled('email')) {
            $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', Rule::unique('admins')->ignore($admin->id)],
            'role' => ['sometimes', Rule::in([
                Admin::ROLE_SUPER_ADMIN,
                Admin::ROLE_ADMIN,
                Admin::ROLE_SUPPORT,
                Admin::ROLE_FINANCE
            ])],
            'is_active' => 'sometimes|boolean',
        ]);

        if ((int) $request->user()->id === (int) $admin->id && (
            (array_key_exists('is_active', $validated) && !$validated['is_active']) ||
            (array_key_exists('role', $validated) && $validated['role'] !== $admin->role)
        )) {
            return response()->json(['message' => 'You cannot change your own role or deactivate your account'], 422);
        }

        $removesActiveSuperAdmin = $admin->isSuperAdmin() && $admin->is_active && (
            (array_key_exists('role', $validated) && $validated['role'] !== Admin::ROLE_SUPER_ADMIN) ||
            (array_key_exists('is_active', $validated) && !$validated['is_active'])
        );

        if ($removesActiveSuperAdmin && $this->activeSuperAdminCount() <= 1) {
            return response()->json(['message' => 'Cannot remove the only active super admin'], 422);
        }

        $oldValues = $admin->only(array_keys($validated));

        $admin->update($validated);
        if (
            (array_key_exists('role', $validated) && $oldValues['role'] !== $validated['role']) ||
            (array_key_exists('is_active', $validated) && !$validated['is_active'])
        ) {
            $admin->tokens()->delete();
        }

        // Log the action
        AdminLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'update_admin',
            'model_type' => Admin::class,
            'model_id' => $admin->id,
            'old_values' => $oldValues,
            'new_values' => $validated,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Admin updated successfully',
            'data' => $admin
        ]);
    }

    /**
     * Remove the specified admin.
     */
    public function destroy(Request $request, $id)
    {
        $admin = Admin::findOrFail($id);

        if ((int) $request->user()->id === (int) $admin->id) {
            return response()->json(['message' => 'You cannot delete your own admin account'], 422);
        }

        if ($admin->isSuperAdmin() && $admin->is_active && $this->activeSuperAdminCount() <= 1) {
            return response()->json(['message' => 'Cannot delete the only active super admin'], 422);
        }

        // Log before deletion
        AdminLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'delete_admin',
            'model_type' => Admin::class,
            'model_id' => $admin->id,
            'old_values' => $admin->toArray(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $admin->tokens()->delete();
        $admin->delete();

        return response()->json([
            'message' => 'Admin deleted successfully'
        ]);
    }

    /**
     * Update admin password.
     */
    public function updatePassword(Request $request, $id)
    {
        $admin = Admin::findOrFail($id);

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $admin->update([
            'password' => Hash::make($validated['password'])
        ]);

        $tokens = $admin->tokens();
        if ((int) $request->user()->id === (int) $admin->id) {
            $currentToken = $request->user()->currentAccessToken();
            $currentTokenId = $currentToken && method_exists($currentToken, 'getKey')
                ? $currentToken->getKey()
                : null;
            if ($currentTokenId) {
                $tokens->where('id', '!=', $currentTokenId);
            }
        }
        $tokens->delete();

        AdminLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'update_admin_password',
            'model_type' => Admin::class,
            'model_id' => $admin->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Password updated successfully'
        ]);
    }

    /**
     * Toggle admin active status.
     */
    public function toggleActive(Request $request, $id)
    {
        $admin = Admin::findOrFail($id);

        if ((int) $request->user()->id === (int) $admin->id && $admin->is_active) {
            return response()->json(['message' => 'You cannot deactivate your own admin account'], 422);
        }

        if ($admin->isSuperAdmin() && $admin->is_active && $this->activeSuperAdminCount() <= 1) {
            return response()->json(['message' => 'Cannot deactivate the only active super admin'], 422);
        }

        $admin->update([
            'is_active' => !$admin->is_active
        ]);
        if (!$admin->is_active) {
            $admin->tokens()->delete();
        }

        AdminLog::create([
            'admin_id' => $request->user()->id,
            'action' => $admin->is_active ? 'activate_admin' : 'deactivate_admin',
            'model_type' => Admin::class,
            'model_id' => $admin->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => $admin->is_active ? 'Admin activated' : 'Admin deactivated',
            'is_active' => $admin->is_active
        ]);
    }

    private function activeSuperAdminCount(): int
    {
        return Admin::query()
            ->where('role', Admin::ROLE_SUPER_ADMIN)
            ->where('is_active', true)
            ->count();
    }
}
