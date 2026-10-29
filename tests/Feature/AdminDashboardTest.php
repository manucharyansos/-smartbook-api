<?php

use App\Models\Admin;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Client;
use App\Models\Service;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

it('loads every admin analytics view with real data for each period', function (string $period) {
    $business = Business::factory()->onboardingCompleted()->create(['business_type' => 'rentals', 'vertical' => 'rentals']);
    $other = Business::factory()->onboardingCompleted()->create();
    $service = Service::factory()->create(['business_id' => $other->id]);
    Booking::factory()->create(['business_id' => $other->id, 'service_id' => $service->id, 'client_id' => Client::factory()->create(['business_id' => $other->id])->id,
        'status' => 'confirmed', 'final_price' => 5000, 'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour(), 'source' => 'website']);
    Sanctum::actingAs(Admin::create(['name' => 'Owner', 'email' => 'dash@vizit.test', 'password' => Hash::make('x-secure-pass-1'), 'role' => Admin::ROLE_SUPER_ADMIN, 'is_active' => true]));

    $this->getJson('/api/admin/analytics/dashboard?period='.$period)->assertOk()
        ->assertJsonPath('data.revenue.period_total', 5000)
        ->assertJsonStructure(['data' => ['business_mix', 'top_sources', 'top_businesses', 'charts' => ['revenue', 'bookings'], 'subscriptions' => ['mrr']]]);
    expect(collect($this->getJson('/api/admin/analytics/dashboard?period='.$period)->json('data.business_mix'))->pluck('business_type')->all())->toContain('rentals');
    $this->getJson('/api/admin/dashboard')->assertOk();
    $this->getJson('/api/admin/analytics')->assertOk();
    $this->getJson('/api/admin/analytics/revenue?period='.$period)->assertOk();
    $this->getJson('/api/admin/analytics/businesses')->assertOk();
})->with(['7_days', '30_days', '90_days', '12_months']);

it('reports deployment status to the super admin only', function () {
    Sanctum::actingAs(Admin::create(['name' => 'Support', 'email' => 'sys-support@vizit.test', 'password' => Hash::make('x-secure-pass-1'), 'role' => Admin::ROLE_SUPPORT, 'is_active' => true]));
    $this->getJson('/api/admin/system')->assertForbidden();
    Sanctum::actingAs(Admin::create(['name' => 'Owner', 'email' => 'sys@vizit.test', 'password' => Hash::make('x-secure-pass-1'), 'role' => Admin::ROLE_SUPER_ADMIN, 'is_active' => true]));
    $this->getJson('/api/admin/system')->assertOk()->assertJsonPath('data.pending_migrations', [])->assertJsonStructure(['data' => ['commit', 'routes_cached', 'database']]);
});
