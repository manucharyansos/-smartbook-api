<?php

use App\Models\Business;

it('does not list or expose reserved test businesses publicly', function () {
    config()->set('services.public_booking.excluded_slugs', ['test', 'test-2']);
    config()->set('services.public_booking.excluded_slug_prefixes', ['vizit-e2e-', 'vizit-medical-qa']);

    Business::factory()->onboardingCompleted()->create([
        'name' => 'Internal Test Business',
        'slug' => 'test',
        'status' => 'active',
        'is_public' => true,
        'is_public_profile_enabled' => true,
    ]);

    Business::factory()->onboardingCompleted()->create([
        'name' => 'Vizit E2E Services',
        'slug' => 'vizit-e2e-services-generated',
        'status' => 'active',
        'is_public' => true,
        'is_public_profile_enabled' => true,
        'is_marketplace_visible' => true,
    ]);

    $this->getJson('/api/public/businesses')
        ->assertOk()
        ->assertJsonMissing(['slug' => 'test'])
        ->assertJsonMissing(['slug' => 'vizit-e2e-services-generated']);

    $this->getJson('/api/public/businesses/map')
        ->assertOk()
        ->assertJsonMissing(['slug' => 'test'])
        ->assertJsonMissing(['slug' => 'vizit-e2e-services-generated']);

    $this->getJson('/api/public/businesses/test')
        ->assertNotFound();

    $this->getJson('/api/public/businesses/test/services')
        ->assertNotFound();

    $this->getJson('/api/public/businesses/vizit-e2e-services-generated')
        ->assertNotFound();

    $this->getJson('/api/public/seo/meta?path=/businesses/test')
        ->assertNotFound()
        ->assertJsonPath('status', 404);
});

it('keeps profile and marketplace visibility controls independent', function () {
    $profileOnly = Business::factory()->onboardingCompleted()->create([
        'name' => 'Profile Only Business',
        'slug' => 'profile-only',
        'status' => 'active',
        'is_public' => true,
        'is_public_profile_enabled' => true,
        'is_marketplace_visible' => false,
    ]);

    Business::factory()->onboardingCompleted()->create([
        'name' => 'Marketplace Flag Without Profile',
        'slug' => 'marketplace-without-profile',
        'status' => 'active',
        'is_public' => true,
        'is_public_profile_enabled' => false,
        'is_marketplace_visible' => true,
    ]);

    $directory = $this->getJson('/api/public/businesses')->assertOk();
    $directory
        ->assertJsonMissing(['slug' => $profileOnly->slug])
        ->assertJsonMissing(['slug' => 'marketplace-without-profile']);

    $this->getJson('/api/public/businesses/profile-only')->assertOk();
    $this->getJson('/api/public/businesses/marketplace-without-profile')->assertNotFound();
    $this->getJson('/api/public/businesses/marketplace-without-profile/services')->assertNotFound();
});

it('exposes the weekly business hours needed by public map details', function () {
    $business = Business::factory()->onboardingCompleted()->create([
        'name' => 'Map Schedule Studio',
        'slug' => 'map-schedule-studio',
        'status' => 'active',
        'is_public' => true,
        'is_public_profile_enabled' => true,
        'is_marketplace_visible' => true,
    ]);

    $business->workingHours()->createMany([
        ['weekday' => 1, 'is_closed' => false, 'start' => '09:00', 'end' => '18:00'],
        ['weekday' => 2, 'is_closed' => false, 'start' => '09:00', 'end' => '18:00'],
        ['weekday' => 7, 'is_closed' => true, 'start' => null, 'end' => null],
    ]);

    $this->getJson('/api/public/businesses')
        ->assertOk()
        ->assertJsonFragment([
            'slug' => 'map-schedule-studio',
        ])
        ->assertJsonPath('data.0.working_hours.0.weekday', 1)
        ->assertJsonPath('data.0.working_hours.0.start', '09:00:00')
        ->assertJsonPath('data.0.working_hours.2.is_closed', true);

    $this->getJson('/api/public/businesses/map-schedule-studio')
        ->assertOk()
        ->assertJsonPath('working_hours.1.weekday', 2)
        ->assertJsonPath('working_hours.2.is_closed', true);
});

it('returns non-indexable metadata with a real not-found status for unknown pages', function () {
    $this->getJson('/api/public/seo/meta?path=/definitely-missing')
        ->assertNotFound()
        ->assertJsonPath('status', 404)
        ->assertJsonPath('robots', 'noindex,nofollow');
});
