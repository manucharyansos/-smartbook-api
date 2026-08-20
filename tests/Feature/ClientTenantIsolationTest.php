<?php

use App\Models\Business;
use App\Models\Client;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->businessA = Business::factory()->billable()->create();
    $this->businessB = Business::factory()->billable()->create();
    $this->ownerA = User::factory()->owner($this->businessA->id)->create();
    $this->clientA = Client::factory()->create([
        'business_id' => $this->businessA->id,
        'name' => 'Business A Client',
        'email' => 'same-client@example.com',
        'phone' => '+37498123456',
    ]);
    $this->clientB = Client::factory()->create([
        'business_id' => $this->businessB->id,
        'name' => 'Business B Client',
        'email' => 'same-client@example.com',
        'phone' => '+37498123456',
    ]);

    Sanctum::actingAs($this->ownerA);
});

it('lists only clients belonging to the authenticated business', function () {
    $this->getJson('/api/clients')
        ->assertOk()
        ->assertJsonFragment(['id' => $this->clientA->id, 'name' => 'Business A Client'])
        ->assertJsonMissing(['id' => $this->clientB->id, 'name' => 'Business B Client'])
        ->assertJsonPath('total', 1);
});

it('allows the same person to have independent profiles in different businesses', function () {
    expect(Client::query()->withoutGlobalScopes()->where('phone', '+37498123456')->count())->toBe(2)
        ->and(Client::query()->withoutGlobalScopes()->where('email', 'same-client@example.com')->count())->toBe(2)
        ->and($this->clientA->business_id)->not->toBe($this->clientB->business_id);
});

it('ignores a submitted foreign business id when creating a client', function () {
    $response = $this->postJson('/api/clients', [
        'business_id' => $this->businessB->id,
        'name' => 'New Client',
        'phone' => '+37498111111',
    ])->assertCreated();

    $createdId = $response->json('data.id');

    expect(Client::query()->withoutGlobalScopes()->findOrFail($createdId)->business_id)
        ->toBe($this->businessA->id);
});

it('does not expose another business client detail bookings or mutations', function () {
    $id = $this->clientB->id;

    $this->getJson("/api/clients/{$id}")->assertNotFound();
    $this->getJson("/api/clients/{$id}/bookings")->assertNotFound();
    $this->putJson("/api/clients/{$id}", ['name' => 'Leaked update'])->assertNotFound();
    $this->postJson("/api/clients/{$id}/notes", ['body' => 'Leaked note'])->assertNotFound();
    $this->postJson("/api/clients/{$id}/reminders", [
        'title' => 'Leaked reminder',
        'remind_at' => now()->addDay()->toISOString(),
    ])->assertNotFound();

    $foreignClient = Client::query()->withoutGlobalScopes()->findOrFail($id);

    expect($foreignClient->name)->toBe('Business B Client');
    $this->assertDatabaseMissing('client_notes', ['client_id' => $id]);
    $this->assertDatabaseMissing('client_reminders', ['client_id' => $id]);
});
