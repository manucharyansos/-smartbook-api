<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

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

        $admins = $query->latest()->paginate($request->get('per_page', 20));

        return response()->json([
            'data' => $admins
        ]);
    }

    /**
     * Store a newly created admin.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:admins',
            'password' => 'required|string|min:8|confirmed',
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
            'admin_id' => $request->user('admin')->id,
            'action' => 'create_admin',
            'model_type' => Admin::class,
            'model_id' => $admin->id,
            'new_values' => $validated,
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

        // Prevent editing last super admin
        if ($admin->isSuperAdmin() &&
            Admin::where('role', Admin::ROLE_SUPER_ADMIN)->count() === 1 &&
            $request->has('role') &&
            $request->role !== Admin::ROLE_SUPER_ADMIN) {
            return response()->json([
                'message' => 'Cannot change role of the only super admin'
            ], 400);
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

        $oldValues = $admin->only(array_keys($validated));

        $admin->update($validated);

        // Log the action
        AdminLog::create([
            'admin_id' => $request->user('admin')->id,
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

        // Prevent deleting last super admin
        if ($admin->isSuperAdmin() &&
            Admin::where('role', Admin::ROLE_SUPER_ADMIN)->count() === 1) {
            return response()->json([
                'message' => 'Cannot delete the only super admin'
            ], 400);
        }

        // Log before deletion
        AdminLog::create([
            'admin_id' => $request->user('admin')->id,
            'action' => 'delete_admin',
            'model_type' => Admin::class,
            'model_id' => $admin->id,
            'old_values' => $admin->toArray(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

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
            'password' => 'required|string|min:8|confirmed',
        ]);

        $admin->update([
            'password' => Hash::make($validated['password'])
        ]);

        AdminLog::create([
            'admin_id' => $request->user('admin')->id,
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

        // Prevent toggling last super admin
        if ($admin->isSuperAdmin() &&
            Admin::where('role', Admin::ROLE_SUPER_ADMIN)->count() === 1 &&
            $admin->is_active) {
            return response()->json([
                'message' => 'Cannot deactivate the only super admin'
            ], 400);
        }

        $admin->update([
            'is_active' => !$admin->is_active
        ]);

        AdminLog::create([
            'admin_id' => $request->user('admin')->id,
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
}
