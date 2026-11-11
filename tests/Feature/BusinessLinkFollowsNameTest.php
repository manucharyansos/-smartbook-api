<?php

use App\Models\Business;
use App\Models\BusinessSlugAlias;
use App\Models\User;
use App\Support\BusinessSlug;
use Laravel\Sanctum\Sanctum;

// A business's public link (vizit.am/book/<slug>) follows its name, and links shared before a
// rename keep opening the business. Found 2026-11-12: a business first named "Test" got the
// link "test", which production hides from the public site, and renaming did not help.

function visibleBusiness(array $attributes = []): Business
{
    return Business::factory()->billable()->create(array_merge([
        'is_public' => true, 'is_public_profile_enabled' => true, 'is_marketplace_visible' => true,
    ], $attributes));
}

function signInOwnerOf(Business $business): void
{
    Sanctum::actingAs(User::factory()->create(['business_id' => $business->id, 'role' => User::ROLE_OWNER, 'is_active' => true]));
}

it('moves the link to the new name and keeps the old link working', function () {
    $business = visibleBusiness(['name' => 'Old Salon', 'slug' => 'old-salon']);
    signInOwnerOf($business);

    $this->patchJson('/api/business/settings', ['name' => 'Beauty Salon'])->assertOk()->assertJsonPath('data.slug', 'beauty-salon');
    expect(BusinessSlugAlias::where('slug', 'old-salon')->value('business_id'))->toBe($business->id);

    $this->getJson('/api/public/businesses/old-salon')->assertOk()->assertJsonPath('slug', 'beauty-salon');
    $this->getJson('/api/public/businesses/old-salon/services')->assertOk();
    $this->getJson('/api/public/businesses/beauty-salon')->assertOk()->assertJsonPath('name', 'Beauty Salon');
    expect($this->getJson('/api/public/seo/meta?path=/book/old-salon')->assertOk()->json('canonical'))
        ->toEndWith('/businesses/beauty-salon');
});

it('keeps the link when the name does not change it', function () {
    $business = visibleBusiness(['name' => 'Beauty Salon', 'slug' => 'beauty-salon-2']);
    signInOwnerOf($business);

    $this->patchJson('/api/business/settings', ['name' => 'beauty salon'])->assertOk()->assertJsonPath('data.slug', 'beauty-salon-2');
    $this->patchJson('/api/business/settings', ['phone' => '+37499111222'])->assertOk()->assertJsonPath('data.slug', 'beauty-salon-2');
    expect(BusinessSlugAlias::count())->toBe(0);
});

it('never takes a link another business uses now or used before', function () {
    $other = visibleBusiness(['name' => 'Beauty Salon', 'slug' => 'beauty-salon']);
    BusinessSlugAlias::create(['business_id' => $other->id, 'slug' => 'beauty-salon-2']);
    $business = visibleBusiness(['name' => 'Old', 'slug' => 'old']);
    signInOwnerOf($business);

    $this->patchJson('/api/business/settings', ['name' => 'Beauty Salon'])->assertOk()->assertJsonPath('data.slug', 'beauty-salon-3');
    $this->getJson('/api/public/businesses/beauty-salon-2')->assertOk()->assertJsonPath('id', $other->id);
});

it('takes back its own old link when the old name returns', function () {
    $business = visibleBusiness(['name' => 'Old Salon', 'slug' => 'old-salon']);
    signInOwnerOf($business);

    $this->patchJson('/api/business/settings', ['name' => 'Beauty Salon'])->assertOk()->assertJsonPath('data.slug', 'beauty-salon');
    $this->patchJson('/api/business/settings', ['name' => 'Old Salon'])->assertOk()->assertJsonPath('data.slug', 'old-salon');
    expect(BusinessSlugAlias::pluck('slug')->all())->toBe(['beauty-salon']);
});

it('replaces a reserved test link on the next save and never hands it out again', function () {
    config([
        'services.public_booking.excluded_slugs' => ['test', 'test-2'],
        'services.public_booking.excluded_slug_prefixes' => ['vizit-e2e-'],
    ]);
    $business = visibleBusiness(['name' => 'Beauty salon', 'slug' => 'test']);
    signInOwnerOf($business);

    $this->getJson('/api/v1/public/businesses')->assertOk()->assertJsonMissing(['id' => $business->id]);

    // Any save fixes it, also one that does not touch the name.
    $this->patchJson('/api/business/settings', ['phone' => '+37499111222'])->assertOk()->assertJsonPath('data.slug', 'beauty-salon');
    expect(BusinessSlugAlias::count())->toBe(0);
    $this->getJson('/api/v1/public/businesses')->assertOk()->assertJsonFragment(['slug' => 'beauty-salon']);
    $this->getJson('/api/public/businesses/test')->assertNotFound();

    // A new business named "Test" gets a link that the public site shows.
    expect(BusinessSlug::unique('Test'))->toBe('test-3');
});

it('keeps QA businesses hidden whatever they are renamed to', function () {
    config(['services.public_booking.excluded_slug_prefixes' => ['vizit-e2e-']]);
    $business = visibleBusiness(['name' => 'E2E salon', 'slug' => 'vizit-e2e-salon-42']);
    signInOwnerOf($business);

    $this->patchJson('/api/business/settings', ['name' => 'Real Salon'])->assertOk()->assertJsonPath('data.slug', 'vizit-e2e-salon-42');
    expect(BusinessSlugAlias::count())->toBe(0);
});
