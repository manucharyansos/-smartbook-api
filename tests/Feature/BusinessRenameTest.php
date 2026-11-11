<?php

use App\Models\Business;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lets business administrators rename only their own business without changing its link', function (string $role) {
    $business = Business::factory()->billable()->create(['slug' => 'existing-booking-link']);
    $other = Business::factory()->create(['name' => 'Other business']);
    Sanctum::actingAs(User::factory()->create(['business_id' => $business->id, 'role' => $role, 'is_active' => true]));

    $this->patchJson('/api/business/settings', ['name' => '  Նոր անուն  ', 'business_id' => $other->id, 'slug' => 'changed-link'])
        ->assertOk()->assertJsonPath('data.name', 'Նոր անուն')->assertJsonPath('data.slug', 'existing-booking-link');
    expect($business->fresh()->name)->toBe('Նոր անուն');
    expect($other->fresh()->name)->toBe('Other business');
    $this->getJson('/api/business/settings')->assertOk()->assertJsonPath('data.name', 'Նոր անուն');
})->with(['owner', 'manager']);

it('rejects invalid names without modifying the business', function ($name) {
    $business = Business::factory()->billable()->create(['name' => 'Original']);
    Sanctum::actingAs(User::factory()->create(['business_id' => $business->id, 'role' => 'owner', 'is_active' => true]));
    $this->patchJson('/api/business/settings', ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
    expect($business->fresh()->name)->toBe('Original');
})->with(['empty' => [''], 'whitespace' => ['   '], 'null' => [null], 'too long' => [str_repeat('a', 256)]]);

it('does not allow staff to rename a business', function () {
    $business = Business::factory()->billable()->create(['name' => 'Original']);
    Sanctum::actingAs(User::factory()->create(['business_id' => $business->id, 'role' => User::ROLE_STAFF, 'is_active' => true]));
    $this->patchJson('/api/business/settings', ['name' => 'Unauthorized'])->assertForbidden();
    expect($business->fresh()->name)->toBe('Original');
});
