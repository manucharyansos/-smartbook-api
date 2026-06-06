<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class EnsureSeatAvailable
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) return $next($request);

        $business = $user->business;
        if (!$business) return $next($request);

        $incomingRole = (string) ($request->input('role') ?? '');
        $routeUser = $request->route('user');

        $shouldCheck = false;

        if ($incomingRole !== '') {
            $shouldCheck = $incomingRole === User::ROLE_STAFF;
        } elseif ($routeUser instanceof User) {
            $shouldCheck = $routeUser->role === User::ROLE_STAFF && !$routeUser->is_active;
        }

        if ($shouldCheck && !$business->hasAvailableSeat()) {
            return response()->json([
                'message' => 'Active staff limit reached. Upgrade the plan or deactivate another staff member.',
                'code' => 'seat_limit_reached',
                'limit' => $business->seatLimit(),
                'current' => $business->activeSeatCount(),
            ], 409);
        }

        return $next($request);
    }
}
