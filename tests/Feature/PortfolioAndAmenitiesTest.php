<?php

use App\Models\Business;
use App\Models\BusinessMedia;
use App\Models\Plan;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function portfolioPng(string $name = 'work.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6oU8AAAAASUVORK5CYII='));
}

beforeEach(function () {
    Storage::fake('public');
    $this->business = Business::factory()->billable()->create([
        'slug' => 'portfolio-salon', 'is_onboarding_completed' => true, 'is_public' => true,
        'is_public_profile_enabled' => true, 'is_marketplace_visible' => true,
    ]);
    $this->owner = User::factory()->create(['business_id' => $this->business->id, 'role' => 'owner', 'is_active' => true]);
    $this->staff = User::factory()->staff($this->business->id)->create(['is_active' => true, 'is_bookable' => true, 'show_in_public_team' => true]);
    $this->service = Service::factory()->create(['business_id' => $this->business->id, 'is_active' => true, 'name' => 'Gel polish']);
    $this->location = $this->business->locations()->firstOrCreate([], ['name' => 'Main', 'address' => 'Yerevan', 'is_primary' => true, 'is_active' => true]);
});

it('uploads one work photo that shows on the business, the specialist and the service', function () {
    $response = $this->actingAs($this->owner)->postJson('/api/portfolio', [
        'file' => portfolioPng(), 'caption' => 'Gel polish', 'staff_id' => $this->staff->id, 'service_id' => $this->service->id,
    ])->assertCreated()
        ->assertJsonPath('data.staff.id', $this->staff->id)
        ->assertJsonPath('data.service.id', $this->service->id);
    $id = $response->json('data.id');
    $media = BusinessMedia::findOrFail($id);
    expect($media->collection)->toBe('portfolio');
    Storage::disk('public')->assertExists($media->getRawOriginal('path'));

    $this->getJson('/api/public/businesses/portfolio-salon')->assertOk()
        ->assertJsonPath('portfolio_count', 1)
        ->assertJsonPath('portfolio_preview.0.id', $id)
        ->assertJsonPath('portfolio_preview.0.staff.id', $this->staff->id)
        ->assertJsonPath('portfolio_preview.0.staff.is_bookable', true)
        ->assertJsonPath('portfolio_preview.0.service.id', $this->service->id)
        ->assertJsonMissingPath('portfolio_preview.0.path');
    $this->getJson('/api/public/businesses/portfolio-salon/portfolio?staff_id='.$this->staff->id)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/public/businesses/portfolio-salon/portfolio?service_id='.$this->service->id)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/public/businesses/portfolio-salon/portfolio?service_id=999999')->assertOk()->assertJsonCount(0, 'data');
    expect(collect($this->getJson('/api/public/businesses/portfolio-salon/staff')->json('data'))->firstWhere('id', $this->staff->id)['portfolio_count'])->toBe(1);
    expect(collect($this->getJson('/api/public/businesses/portfolio-salon/services')->json('data'))->firstWhere('id', $this->service->id)['portfolio_count'])->toBe(1);
});

it('keeps work photos out of the rental gallery and the gallery out of the portfolio', function () {
    BusinessMedia::create(['business_id' => $this->business->id, 'path' => 'x/gallery.png', 'mime_type' => 'image/png', 'kind' => 'image', 'sort_order' => 1]);
    $this->actingAs($this->owner)->postJson('/api/portfolio', ['file' => portfolioPng()])->assertCreated();
    expect($this->business->gallery()->count())->toBe(1)->and($this->business->portfolio()->count())->toBe(1);
    $this->getJson('/api/portfolio')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.limit', 60);
});

it('rejects tags from another business, staff role uploads, videos and other tenants editing', function () {
    $other = Business::factory()->billable()->create(['is_onboarding_completed' => true]);
    $otherStaff = User::factory()->staff($other->id)->create();
    $otherService = Service::factory()->create(['business_id' => $other->id]);
    $this->actingAs($this->owner)->postJson('/api/portfolio', ['file' => portfolioPng(), 'staff_id' => $otherStaff->id])->assertUnprocessable()->assertJsonValidationErrors('staff_id');
    $this->actingAs($this->owner)->postJson('/api/portfolio', ['file' => portfolioPng(), 'service_id' => $otherService->id])->assertUnprocessable()->assertJsonValidationErrors('service_id');
    $this->actingAs($this->owner)->postJson('/api/portfolio', ['file' => UploadedFile::fake()->create('clip.mp4', 100, 'video/mp4')])->assertUnprocessable();
    $this->actingAs($this->staff)->postJson('/api/portfolio', ['file' => portfolioPng()])->assertForbidden();

    $id = $this->actingAs($this->owner)->postJson('/api/portfolio', ['file' => portfolioPng()])->assertCreated()->json('data.id');
    $otherOwner = User::factory()->create(['business_id' => $other->id, 'role' => 'owner', 'is_active' => true]);
    $this->actingAs($otherOwner)->patchJson('/api/portfolio/'.$id, ['caption' => 'x'])->assertNotFound();
    $this->actingAs($otherOwner)->deleteJson('/api/portfolio/'.$id)->assertNotFound();
    $gallery = BusinessMedia::create(['business_id' => $this->business->id, 'path' => 'x/g.png', 'mime_type' => 'image/png', 'kind' => 'image']);
    $this->actingAs($this->owner)->deleteJson('/api/portfolio/'.$gallery->id)->assertNotFound();
});

it('retags, reorders and deletes work photos', function () {
    $first = $this->actingAs($this->owner)->postJson('/api/portfolio', ['file' => portfolioPng('a.png')])->json('data.id');
    $second = $this->postJson('/api/portfolio', ['file' => portfolioPng('b.png')])->json('data.id');
    $this->patchJson('/api/portfolio/'.$first, ['staff_id' => $this->staff->id, 'caption' => 'New'])->assertOk()
        ->assertJsonPath('data.staff_id', $this->staff->id)->assertJsonPath('data.caption', 'New');
    $this->patchJson('/api/portfolio/'.$first, ['staff_id' => null])->assertOk()->assertJsonPath('data.staff_id', null);
    $this->putJson('/api/portfolio/order', ['ids' => [$first, $second]])->assertOk()
        ->assertJsonPath('data.0.id', $first)->assertJsonPath('data.1.id', $second);
    $path = BusinessMedia::findOrFail($second)->getRawOriginal('path');
    $this->deleteJson('/api/portfolio/'.$second)->assertOk();
    Storage::disk('public')->assertMissing($path);
    expect(BusinessMedia::find($second))->toBeNull();
});

it('enforces the portfolio limit', function () {
    for ($i = 0; $i < 60; $i++) {
        BusinessMedia::create(['business_id' => $this->business->id, 'path' => "p/$i.png", 'mime_type' => 'image/png', 'kind' => 'image', 'collection' => 'portfolio']);
    }
    $this->actingAs($this->owner)->postJson('/api/portfolio', ['file' => portfolioPng()])->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('hides hidden specialists and inactive services on public photos and keeps the photo when tags are deleted', function () {
    $hidden = User::factory()->staff($this->business->id)->create(['is_active' => true, 'is_bookable' => false, 'show_in_public_team' => false]);
    $inactive = Service::factory()->create(['business_id' => $this->business->id, 'is_active' => false]);
    $this->actingAs($this->owner)->postJson('/api/portfolio', ['file' => portfolioPng(), 'staff_id' => $hidden->id, 'service_id' => $inactive->id])->assertCreated();
    $this->getJson('/api/public/businesses/portfolio-salon')->assertOk()
        ->assertJsonPath('portfolio_preview.0.staff', null)->assertJsonPath('portfolio_preview.0.service', null);
    $this->getJson('/api/public/businesses/portfolio-salon/portfolio?staff_id='.$hidden->id)->assertOk()->assertJsonCount(0, 'data');

    $tagged = $this->postJson('/api/portfolio', ['file' => portfolioPng(), 'service_id' => $this->service->id])->json('data.id');
    $this->service->delete();
    expect(BusinessMedia::find($tagged))->not->toBeNull()->and(BusinessMedia::find($tagged)->service_id)->toBeNull();
});

it('saves amenities per address and shows them publicly', function () {
    $this->actingAs($this->owner)->getJson('/api/business/settings')->assertOk()
        ->assertJsonPath('data.amenity_options.0', 'wifi');
    $this->patchJson('/api/business/locations/'.$this->location->id, ['amenities' => ['parking', 'wifi', 'wifi']])->assertOk()
        ->assertJsonPath('data.location.amenities', ['wifi', 'parking']);
    $this->patchJson('/api/business/locations/'.$this->location->id, ['amenities' => ['projector']])->assertUnprocessable();
    $this->patchJson('/api/business/locations/'.$this->location->id, ['capacity_people' => 20])->assertOk()
        ->assertJsonPath('data.location.capacity_people', null);
    $this->getJson('/api/public/businesses/portfolio-salon')->assertOk()
        ->assertJsonPath('locations.0.amenities', ['wifi', 'parking']);
    $this->patchJson('/api/business/locations/'.$this->location->id, ['name' => 'Renamed'])->assertOk()
        ->assertJsonPath('data.location.amenities', ['wifi', 'parking']);
});

it('lets rental spaces use rental amenities, capacity and area but not the portfolio', function () {
    $rental = Business::factory()->create([
        'slug' => 'portfolio-rental', 'business_type' => 'rentals', 'vertical' => 'rentals', 'is_onboarding_completed' => true,
        'is_public' => true, 'is_public_profile_enabled' => true, 'rental_hourly_price' => 2000, 'rental_min_minutes' => 60, 'rental_max_minutes' => 240,
    ]);
    $sub = new Subscription(['business_id' => $rental->id, 'status' => 'active', 'current_period_ends_at' => now()->addMonth()]);
    $sub->applyPlanSnapshot(Plan::where('code', 'rental')->firstOrFail());
    $sub->save();
    $owner = User::factory()->create(['business_id' => $rental->id, 'role' => 'owner', 'is_active' => true, 'is_bookable' => false]);
    $location = $rental->locations()->firstOrCreate([], ['name' => 'Hall', 'is_primary' => true, 'is_active' => true]);

    $this->actingAs($owner)->patchJson('/api/business/locations/'.$location->id, ['amenities' => ['projector', 'wifi'], 'capacity_people' => 40, 'area_sqm' => 120])->assertOk()
        ->assertJsonPath('data.location.amenities', ['wifi', 'projector'])
        ->assertJsonPath('data.location.capacity_people', 40)
        ->assertJsonPath('data.location.area_sqm', 120);
    $this->getJson('/api/public/businesses/portfolio-rental')->assertOk()
        ->assertJsonPath('locations.0.capacity_people', 40)->assertJsonPath('locations.0.area_sqm', 120)
        ->assertJsonPath('portfolio_count', 0);
    $this->actingAs($owner)->getJson('/api/portfolio')->assertNotFound();
});
