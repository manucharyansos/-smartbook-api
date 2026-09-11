<?php

namespace Tests\Feature\Auth;

use App\Models\Business;
use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class MobileUnifiedLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_credentials_route_to_client_audience(): void
    {
        ClientAccount::query()->create([
            'name' => 'Client User',
            'email' => 'client@example.com',
            'phone' => '+37499111222',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/mobile/auth/login', [
            'identity' => 'client@example.com',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonPath('audience', 'client')
            ->assertJsonPath('user.audience', 'client')
            ->assertJsonStructure(['token']);
    }

    public function test_business_credentials_route_to_business_audience(): void
    {
        $business = Business::factory()->create(['is_onboarding_completed' => true]);
        User::factory()->owner($business->id)->create([
            'email' => 'owner@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/mobile/auth/login', [
            'identity' => 'owner@example.com',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonPath('audience', 'business')
            ->assertJsonPath('user.audience', 'business')
            ->assertJsonPath('user.needs_onboarding', false)
            ->assertJsonStructure(['token']);
    }

    public function test_same_credentials_for_both_audiences_require_selection_without_creating_tokens(): void
    {
        $business = Business::factory()->create();
        User::factory()->owner($business->id)->create([
            'email' => 'shared@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
        ClientAccount::query()->create([
            'name' => 'Shared Client',
            'email' => 'shared@example.com',
            'phone' => '+37499111333',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/mobile/auth/login', [
            'identity' => 'shared@example.com',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonPath('requires_selection', true)
            ->assertJsonPath('audiences.0', 'client')
            ->assertJsonPath('audiences.1', 'business')
            ->assertJsonMissing(['token']);

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_selected_audience_issues_only_that_session(): void
    {
        $business = Business::factory()->create();
        User::factory()->owner($business->id)->create([
            'email' => 'shared@example.com',
            'password' => Hash::make('secret123'),
            'is_active' => true,
        ]);
        ClientAccount::query()->create([
            'name' => 'Shared Client',
            'email' => 'shared@example.com',
            'phone' => '+37499111444',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/mobile/auth/login', [
            'identity' => 'shared@example.com',
            'password' => 'secret123',
            'audience' => 'business',
        ]);

        $response->assertOk()
            ->assertJsonPath('audience', 'business')
            ->assertJsonStructure(['token']);

        $this->assertSame(1, PersonalAccessToken::query()->count());
    }

    public function test_invalid_credentials_do_not_reveal_account_type(): void
    {
        ClientAccount::query()->create([
            'name' => 'Client User',
            'email' => 'client@example.com',
            'phone' => '+37499111555',
            'password' => Hash::make('secret123'),
        ]);

        $this->postJson('/api/mobile/auth/login', [
            'identity' => 'client@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['identity']);
    }
}
