<?php

use App\Models\Admin;
use App\Models\Business;
use App\Models\SiteEvent;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

const BROWSER_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';

it('stores anonymous events without an IP address and links business pages', function () {
    $business = Business::factory()->create(['slug' => 'beauty-room']);
    $this->withHeaders(['User-Agent' => BROWSER_AGENT])->postJson('/api/v1/public/analytics/events', ['events' => [
        ['event' => 'page_view', 'path' => '/businesses/beauty-room?utm_source=ig&phone=099', 'referrer' => 'https://www.instagram.com/p/1', 'utm_source' => 'IG', 'locale' => 'hy'],
        ['event' => 'booking_started', 'path' => '/book/beauty-room'],
        ['event' => 'page_view', 'path' => '/pricing', 'referrer' => 'https://vizit.am/'],
    ]])->assertNoContent();

    $rows = SiteEvent::orderBy('id')->get();
    expect($rows)->toHaveCount(3)
        ->and($rows[0]->path)->toBe('/businesses/beauty-room')
        ->and($rows[0]->business_id)->toBe($business->id)
        ->and($rows[0]->referrer_host)->toBe('instagram.com')
        ->and($rows[0]->utm_source)->toBe('ig')
        ->and($rows[0]->device)->toBe('mobile')
        ->and($rows[2]->referrer_host)->toBeNull()
        ->and(strlen($rows[0]->visitor))->toBe(32)
        ->and(json_encode($rows->toArray()))->not->toContain('127.0.0.1');
});

it('ignores bots and rejects unknown events or oversized batches', function () {
    $this->withHeaders(['User-Agent' => 'Googlebot/2.1'])->postJson('/api/v1/public/analytics/events', ['events' => [['event' => 'page_view', 'path' => '/']]])->assertNoContent();
    expect(SiteEvent::count())->toBe(0);
    $this->withHeaders(['User-Agent' => BROWSER_AGENT])->postJson('/api/v1/public/analytics/events', ['events' => [['event' => 'drop_table']]])->assertUnprocessable();
    $this->withHeaders(['User-Agent' => BROWSER_AGENT])->postJson('/api/v1/public/analytics/events', ['events' => array_fill(0, 21, ['event' => 'page_view'])])->assertUnprocessable();
});

it('summarises visits, pages, sources and businesses for the super admin only', function () {
    $business = Business::factory()->create(['slug' => 'dental-one', 'name' => 'Dental One']);
    foreach ([BROWSER_AGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120 Safari/537.36'] as $agent) {
        $this->withHeaders(['User-Agent' => $agent])->postJson('/api/v1/public/analytics/events', ['events' => [
            ['event' => 'page_view', 'path' => '/', 'referrer' => 'https://google.com/search'],
            ['event' => 'page_view', 'path' => '/businesses/dental-one'],
        ]])->assertNoContent();
    }
    $this->withHeaders(['User-Agent' => 'okhttp/4.12'])->postJson('/api/v1/public/analytics/events', ['source' => 'mobile', 'events' => [['event' => 'screen_view', 'path' => '/business/dental-one']]])->assertNoContent();
    $this->withHeaders(['User-Agent' => 'okhttp/4.12'])->postJson('/api/v1/public/analytics/events', ['source' => 'mobile', 'device' => 'mobile', 'events' => [['event' => 'app_open']]])->assertNoContent();

    Sanctum::actingAs(Admin::create(['name' => 'Support', 'email' => 'sa-support@vizit.test', 'password' => Hash::make('x-secure-pass-1'), 'role' => Admin::ROLE_SUPPORT, 'is_active' => true]));
    $this->getJson('/api/admin/site-analytics')->assertForbidden();
    Sanctum::actingAs(Admin::create(['name' => 'Owner', 'email' => 'sa@vizit.test', 'password' => Hash::make('x-secure-pass-1'), 'role' => Admin::ROLE_SUPER_ADMIN, 'is_active' => true]));
    $response = $this->getJson('/api/admin/site-analytics?days=7')->assertOk()
        ->assertJsonPath('data.totals.views', 5)->assertJsonPath('data.totals.visitors', 3)->assertJsonPath('data.totals.app_opens', 1)
        ->assertJsonPath('data.top_businesses.0.name', 'Dental One')->assertJsonPath('data.top_businesses.0.total', 3)
        ->assertJsonPath('data.top_pages.0.label', '/')->assertJsonPath('data.referrers.0.label', 'google.com');
    expect($response->json('data.series'))->toHaveCount(7)
        ->and(collect($response->json('data.sources'))->pluck('label')->all())->toContain('web', 'mobile');
});

it('prunes events past the retention period', function () {
    SiteEvent::create(['event' => 'page_view', 'path' => '/', 'visitor' => str_repeat('a', 32), 'created_at' => now()->subDays(500)]);
    SiteEvent::create(['event' => 'page_view', 'path' => '/', 'visitor' => str_repeat('b', 32), 'created_at' => now()->subDay()]);
    $this->artisan('vizit:prune-site-events')->assertSuccessful();
    expect(SiteEvent::count())->toBe(1);
});
