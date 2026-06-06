<?php
// tests/Feature/ServiceTest.php

use App\Models\Business; // Փոխել Salon-ից Business
use App\Models\Service;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('owner can list only own business services (tenant isolation)', function () {
    $businessA = Business::factory()->create(); // Փոխել Salon-ից Business
    $businessB = Business::factory()->create();

    $ownerA = User::factory()->create([
        'business_id' => $businessA->id, // Փոխել salon_id-ից business_id
        'role' => 'owner',
    ]);

    Service::factory()->count(2)->create(['business_id' => $businessA->id]); // Փոխել salon_id-ից business_id
    Service::factory()->count(3)->create(['business_id' => $businessB->id]);

    Sanctum::actingAs($ownerA);

    $res = $this->getJson('/api/services');

    $res->assertOk();
    expect($res->json('data'))->toHaveCount(2);
});

it('staff cannot create service', function () {
    $business = Business::factory()->create(); // Փոխել Salon-ից Business

    $staff = User::factory()->create([
        'business_id' => $business->id, // Փոխել salon_id-ից business_id
        'role' => 'staff',
    ]);

    Sanctum::actingAs($staff);

    $res = $this->postJson('/api/services', [
        'name' => 'Test Service',
        'duration_minutes' => 60,
        'price' => 9000,
        'is_active' => true,
    ]);

    $res->assertForbidden();
});

it('owner can create service', function () {
    $business = Business::factory()->create(); // Փոխել Salon-ից Business

    $owner = User::factory()->create([
        'business_id' => $business->id, // Փոխել salon_id-ից business_id
        'role' => 'owner',
    ]);

    Sanctum::actingAs($owner);

    $res = $this->postJson('/api/services', [
        'name' => 'Manicure',
        'duration_minutes' => 60,
        'price' => 8000,
        'is_active' => true,
    ]);

    $res->assertCreated()
        ->assertJsonPath('data.name', 'Manicure');

    $this->assertDatabaseHas('services', [
        'business_id' => $business->id, // Փոխել salon_id-ից business_id
        'name' => 'Manicure',
    ]);
});

it('owner cannot access other business service via show', function () {
    $businessA = Business::factory()->create(); // Փոխել Salon-ից Business
    $businessB = Business::factory()->create();

    $ownerA = User::factory()->create([
        'business_id' => $businessA->id, // Փոխել salon_id-ից business_id
        'role' => 'owner',
    ]);

    $serviceB = Service::factory()->create(['business_id' => $businessB->id]); // Փոխել salon_id-ից business_id

    Sanctum::actingAs($ownerA);

    $res = $this->getJson('/api/services/'.$serviceB->id);
    $res->assertNotFound();
});
