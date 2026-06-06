<?php
// app/Http/Controllers/Admin/DashboardController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Business; // Փոխել Salon-ից Business
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        // Business statistics
        $totalBusinesses = Business::count(); // Փոխել $totalSalons-ից $totalBusinesses
        $activeBusinesses = Business::where('status', 'active')->count();
        $suspendedBusinesses = Business::where('status', 'suspended')->count();
        $pendingBusinesses = Business::where('status', 'pending')->count();

        // User statistics
        $totalUsers = User::count();
        $totalOwners = User::where('role', User::ROLE_OWNER)->count();
        $totalManagers = User::where('role', User::ROLE_MANAGER)->count();
        $totalStaff = User::where('role', User::ROLE_STAFF)->count();

        // Booking statistics
        $totalBookings = Booking::count();
        $todayBookings = Booking::whereDate('starts_at', today())->count(); // created_at -> starts_at
        $confirmedBookings = Booking::whereIn('status', ['confirmed', 'done'])->count();

        // Revenue statistics
        $totalRevenue = Booking::whereIn('status', ['confirmed', 'done'])
            ->sum('final_price');

        $monthlyRevenue = Booking::whereIn('status', ['confirmed', 'done'])
            ->whereMonth('starts_at', now()->month) // created_at -> starts_at
            ->sum('final_price');

        $todayRevenue = Booking::whereIn('status', ['confirmed', 'done'])
            ->whereDate('starts_at', today())
            ->sum('final_price');

        // Subscription statistics
        $activeSubscriptions = Subscription::where('status', 'active')->count();
        $trialingSubscriptions = Subscription::where('status', 'trialing')->count();
        $canceledSubscriptions = Subscription::where('status', 'canceled')->count();

        // Recent businesses
        $recentBusinesses = Business::withCount(['users', 'bookings']) // Փոխել $recentSalons-ից $recentBusinesses
        ->latest()
            ->limit(10)
            ->get()
            ->map(function($business) {
                return [
                    'id' => $business->id,
                    'name' => $business->name,
                    'slug' => $business->slug,
                    'business_type' => $business->business_type,
                    'status' => $business->status,
                    'users_count' => $business->users_count ?? 0,
                    'bookings_count' => $business->bookings_count ?? 0,
                    'created_at' => $business->created_at,
                ];
            });

        // Business type breakdown
        $beautyBusinesses = Business::where('vertical', 'services')->orWhereIn('business_type', ['services', 'beauty', 'salon'])->count();
        $dentalBusinesses = Business::where('vertical', 'healthcare')->orWhereIn('business_type', ['healthcare', 'dental', 'clinic'])->count();

        return response()->json([
            'success' => true,
            'data' => [
                'businesses' => [ // Փոխել 'salons' -> 'businesses'
                    'total' => $totalBusinesses,
                    'active' => $activeBusinesses,
                    'suspended' => $suspendedBusinesses,
                    'pending' => $pendingBusinesses,
                    'by_type' => [
                        'beauty' => $beautyBusinesses,
                        'dental' => $dentalBusinesses,
                    ],
                ],
                'users' => [
                    'total' => $totalUsers,
                    'owners' => $totalOwners,
                    'managers' => $totalManagers,
                    'staff' => $totalStaff,
                ],
                'bookings' => [
                    'total' => $totalBookings,
                    'today' => $todayBookings,
                    'confirmed' => $confirmedBookings,
                ],
                'revenue' => [
                    'total' => (float) $totalRevenue,
                    'monthly' => (float) $monthlyRevenue,
                    'today' => (float) $todayRevenue,
                    'currency' => 'AMD',
                ],
                'subscriptions' => [
                    'active' => $activeSubscriptions,
                    'trialing' => $trialingSubscriptions,
                    'canceled' => $canceledSubscriptions,
                ],
                'recent_businesses' => $recentBusinesses, // Փոխել 'recent_salons' -> 'recent_businesses'
            ]
        ]);
    }

    public function analytics(Request $request)
    {
        $days = $request->get('days', 30);

        // Businesses growth over time
        $businessesGrowth = Business::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Revenue growth over time
        $revenueGrowth = Booking::selectRaw('DATE(starts_at) as date, SUM(final_price) as revenue') // created_at -> starts_at
        ->whereIn('status', ['confirmed', 'done'])
            ->where('starts_at', '>=', now()->subDays($days)) // created_at -> starts_at
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Bookings growth over time
        $bookingsGrowth = Booking::selectRaw('DATE(starts_at) as date, COUNT(*) as count')
            ->where('starts_at', '>=', now()->subDays($days))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Users growth over time
        $usersGrowth = User::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'businesses_growth' => $businessesGrowth, // Փոխել 'salons_growth' -> 'businesses_growth'
                'revenue_growth' => $revenueGrowth,
                'bookings_growth' => $bookingsGrowth,
                'users_growth' => $usersGrowth,
            ]
        ]);
    }
}
