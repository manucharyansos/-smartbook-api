<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminLog;
use Illuminate\Http\Request;

class LogController extends Controller
{
    /**
     * Display a listing of admin logs.
     */
    public function index(Request $request)
    {
        $query = AdminLog::with('admin');

        // Filter by admin
        if ($request->has('admin_id')) {
            $query->where('admin_id', $request->admin_id);
        }

        // Filter by action
        if ($request->has('action')) {
            $query->where('action', 'like', '%' . $request->action . '%');
        }

        // Filter by date range
        if ($request->has('from')) {
            $query->where('created_at', '>=', $request->from);
        }
        if ($request->has('to')) {
            $query->where('created_at', '<=', $request->to);
        }

        // Filter by model
        if ($request->has('model_type')) {
            $query->where('model_type', $request->model_type);
        }

        $logs = $query->latest()->paginate($request->get('per_page', 20));

        return response()->json([
            'data' => $logs,
            'meta' => [
                'total' => $logs->total(),
                'per_page' => $logs->perPage(),
                'current_page' => $logs->currentPage(),
            ]
        ]);
    }

    /**
     * Display the specified log.
     */
    public function show($id)
    {
        $log = AdminLog::with('admin')->findOrFail($id);

        return response()->json([
            'data' => $log
        ]);
    }

    /**
     * Get logs for a specific admin.
     */
    public function adminLogs($adminId, Request $request)
    {
        $logs = AdminLog::where('admin_id', $adminId)
            ->with('admin')
            ->latest()
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'data' => $logs
        ]);
    }

    /**
     * Get logs for a specific model.
     */
    public function modelLogs(Request $request)
    {
        $request->validate([
            'model_type' => 'required|string',
            'model_id' => 'required|integer',
        ]);

        $logs = AdminLog::where('model_type', $request->model_type)
            ->where('model_id', $request->model_id)
            ->with('admin')
            ->latest()
            ->get();

        return response()->json([
            'data' => $logs
        ]);
    }

    /**
     * Get logs summary (grouped by action).
     */
    public function summary()
    {
        $summary = AdminLog::selectRaw('action, COUNT(*) as count')
            ->groupBy('action')
            ->orderByDesc('count')
            ->get();

        $daily = AdminLog::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'data' => [
                'by_action' => $summary,
                'daily' => $daily,
                'total' => AdminLog::count(),
                'today' => AdminLog::whereDate('created_at', today())->count(),
            ]
        ]);
    }

    /**
     * Clear old logs (optional - super admin only).
     */
    public function clear(Request $request)
    {
        $request->validate([
            'days' => 'required|integer|min:30|max:365',
        ]);

        $cutoff = now()->subDays($request->days);
        $deleted = AdminLog::where('created_at', '<', $cutoff)->delete();

        return response()->json([
            'message' => "Old logs cleared successfully",
            'deleted_count' => $deleted
        ]);
    }
}
