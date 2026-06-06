<?php
// app/Http/Controllers/Admin/UserManagementController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Business; // Փոխել Salon-ից Business
use App\Models\Booking;
use App\Models\AdminLog;
use Illuminate\Http\Request;

class UserManagementController extends Controller
{
    /**
     * Display a listing of users.
     */
    public function index(Request $request)
    {
        $query = User::with('business'); // with('salon') -> with('business')

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%');
                // phone չկա users table-ում, հեռացնենք
            });
        }

        // Filter by business_id (ոչ թե salon_id)
        if ($request->has('business_id')) {
            $query->where('business_id', $request->business_id);
        }

        // Filter by role
        if ($request->has('role')) {
            $query->where('role', $request->role);
        }

        // Filter by status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Sort
        $sortField = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortField, $sortOrder);

        $users = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $users
        ]);
    }

    /**
     * Display the specified user.
     */
    public function show($id)
    {
        $user = User::with(['business', 'bookings' => function($q) { // with('beauty') -> with('business')
            $q->latest()->limit(20);
        }])->findOrFail($id);

        $stats = [
            'total_bookings' => Booking::where('staff_id', $user->id)->count(),
            'total_revenue' => Booking::where('staff_id', $user->id)
                ->whereIn('status', ['confirmed', 'done'])
                ->sum('final_price'),
            'completed_bookings' => Booking::where('staff_id', $user->id)
                ->where('status', 'done')
                ->count(),
            'upcoming_bookings' => Booking::where('staff_id', $user->id)
                ->where('status', 'confirmed')
                ->where('starts_at', '>', now())
                ->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'stats' => $stats
            ]
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'role' => 'sometimes|in:owner,manager,staff',
            'is_active' => 'sometimes|boolean',
        ]);

        $oldValues = $user->only(array_keys($validated));

        $user->update($validated);

        // Log the action
        AdminLog::create([
            'admin_id' => $request->user('admin')->id,
            'action' => 'update_user',
            'model_type' => User::class,
            'model_id' => $user->id,
            'old_values' => $oldValues,
            'new_values' => $validated,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $user
        ]);
    }

    /**
     * Toggle user active status.
     */
    public function toggleActive(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $user->update([
            'is_active' => !$user->is_active,
            'deactivated_at' => $user->is_active ? null : now(),
        ]);

        AdminLog::create([
            'admin_id' => $request->user('admin')->id,
            'action' => $user->is_active ? 'activate_user' : 'deactivate_user',
            'model_type' => User::class,
            'model_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'success' => true,
            'message' => $user->is_active ? 'User activated' : 'User deactivated',
            'is_active' => $user->is_active
        ]);
    }

    /**
     * Get users by business.
     */
    public function byBusiness($businessId, Request $request) // bySalon -> byBusiness
    {
        $business = Business::findOrFail($businessId); // Salon -> Business

        $users = $business->users() // $salon->users() -> $business->users()
        ->withCount('bookings')
            ->when($request->has('role'), function($q) use ($request) {
                $q->where('role', $request->role);
            })
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'business' => $business->name, // 'beauty' -> 'business'
                'users' => $users,
                'total' => $users->count()
            ]
        ]);
    }

    /**
     * Get user activity.
     */
    public function activity($id, Request $request)
    {
        $user = User::findOrFail($id);

        $days = $request->get('days', 30);

        $bookings = Booking::where('staff_id', $user->id)
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(final_price) as revenue')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user->name,
                'activity' => $bookings
            ]
        ]);
    }

    /**
     * Export users list.
     */
    public function export(Request $request)
    {
        $query = User::with('business'); // with('beauty') -> with('business')

        if ($request->has('business_id')) { // salon_id -> business_id
            $query->where('business_id', $request->business_id);
        }

        $users = $query->get();

        // Format for export
        $export = $users->map(function($user) {
            return [
                'ID' => $user->id,
                'Անուն' => $user->name,
                'Էլ․ փոստ' => $user->email,
                'Դեր' => $user->role,
                'Բիզնես' => $user->business?->name, // $user->salon?->name -> $user->business?->name
                'Տիպ' => $user->business?->business_type ?? 'services', // Ավելացնել business type
                'Ակտիվ' => $user->is_active ? 'Այո' : 'Ոչ',
                'Գրանցված' => $user->created_at->format('Y-m-d H:i'),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $export,
            'meta' => [
                'total' => $export->count(),
                'exported_at' => now()->toDateTimeString(),
            ]
        ]);
    }
}
