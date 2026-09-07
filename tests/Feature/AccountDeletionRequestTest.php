<?php

use App\Models\Business;
use App\Models\ClientAccount;
use App\Models\MobileDevice;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

it('accepts a client deletion request and revokes devices', function () {
    $client = ClientAccount::query()->create(['name' => 'Delete Me', 'email' => 'delete@example.com', 'password' => Hash::make('password')]);
    MobileDevice::query()->create(['owner_type' => $client->getMorphClass(), 'owner_id' => $client->id, 'audience' => 'client', 'expo_push_token' => 'ExponentPushToken[delete-client]', 'platform' => 'ios']);
    Sanctum::actingAs($client);

    $this->postJson('/api/mobile/account-deletion-request')->assertStatus(202)->assertJsonPath('data.status', 'pending');
    $this->assertDatabaseHas('account_deletion_requests', ['requester_id' => $client->id, 'audience' => 'client', 'status' => 'pending']);
    $this->assertDatabaseMissing('mobile_devices', ['expo_push_token' => 'ExponentPushToken[delete-client]']);
});

it('derives the business audience on deletion requests', function () {
    $business = Business::factory()->create();
    $owner = User::factory()->owner($business->id)->create();
    Sanctum::actingAs($owner);
    $this->postJson('/api/mobile/account-deletion-request', ['reason' => 'Closing the business'])->assertStatus(202);
    $this->assertDatabaseHas('account_deletion_requests', ['requester_id' => $owner->id, 'audience' => 'business']);
});
