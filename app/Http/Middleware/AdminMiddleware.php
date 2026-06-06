<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        // Sanctum token-ից user-ը գալիս է այստեղ
        $user = $request->user();

        // Համոզվենք՝ դա Admin մոդել է
        if (!$user || !($user instanceof Admin)) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Account deactivated'], 403);
        }

        // roles check (admin:super_admin / admin:finance etc.)
        if (!empty($roles) && !in_array($user->role, $roles, true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return $next($request);
    }
}
