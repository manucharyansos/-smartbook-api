<?php

use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function subscribedOwner(Business $business): User
{
    $plan = Plan::where('code', 'starter')->first() ?? Plan::query()->firstOrFail();
    $sub = new Subscription(['business_id' => $business->id, 'status' => 'active', 'current_period_ends_at' => now()->addMonth()]);
    $sub->applyPlanSnapshot($plan);
    $sub->save();

    return User::factory()->create(['business_id' => $business->id, 'role' => User::ROLE_OWNER, 'is_active' => true]);
}

it('stores per-language profile texts and shows them publicly next to the main text', function () {
    $business = Business::factory()->onboardingCompleted()->create(['description' => 'Основной текст', 'is_public' => true, 'is_public_profile_enabled' => true, 'is_marketplace_visible' => true, 'status' => 'active']);
    Sanctum::actingAs(subscribedOwner($business));

    $this->patchJson('/api/business/settings', [
        'text_translations' => [
            'description' => ['hy' => '  Հայերեն տեքստ ', 'en' => 'English text', 'ru' => ''],
            'short_description' => ['en' => 'Short'],
        ],
    ])->assertOk();

    expect($business->fresh()->textTranslations())->toBe([
        'short_description' => ['en' => 'Short'],
        'description' => ['hy' => 'Հայերեն տեքստ', 'en' => 'English text'],
    ]);

    // Sending one language leaves the others; an empty value removes it.
    $this->patchJson('/api/business/settings', ['text_translations' => ['description' => ['en' => '']]])->assertOk();
    expect($business->fresh()->textTranslations()['description'])->toBe(['hy' => 'Հայերեն տեքստ']);

    $this->getJson('/api/business/settings')->assertOk()->assertJsonPath('data.text_translations.description.hy', 'Հայերեն տեքստ');
    $this->getJson("/api/public/businesses/{$business->slug}")->assertOk()
        ->assertJsonPath('description', 'Основной текст')
        ->assertJsonPath('text_translations.description.hy', 'Հայերեն տեքստ');
});

it('refuses an over-long translation', function () {
    $business = Business::factory()->onboardingCompleted()->create();
    Sanctum::actingAs(subscribedOwner($business));

    $this->patchJson('/api/business/settings', ['text_translations' => ['short_description' => ['ru' => str_repeat('a', 256)]]])
        ->assertUnprocessable()->assertJsonValidationErrors('text_translations.short_description.ru');
});
