<?php

use App\Models\Admin;
use App\Models\AdminLog;
use App\Models\Booking;
use App\Models\Business;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

function purgeFixture(string $slug): Business
{
    $business = Business::factory()->onboardingCompleted()->create(['slug' => $slug]);
    $owner = User::factory()->create(['business_id' => $business->id, 'role' => 'owner']);
    $owner->createToken('api');
    $service = Service::factory()->create(['business_id' => $business->id]);
    $client = Client::factory()->create(['business_id' => $business->id]);
    $booking = Booking::factory()->create(['business_id' => $business->id, 'service_id' => $service->id, 'client_id' => $client->id, 'staff_id' => $owner->id]);
    DB::table('booking_items')->insert(['booking_id' => $booking->id, 'service_id' => $service->id, 'position' => 1, 'duration_minutes' => 30, 'price' => 1000, 'currency' => 'AMD', 'created_at' => now(), 'updated_at' => now()]);
    $invoice = Invoice::query()->withoutGlobalScopes()->create(['business_id' => $business->id, 'plan_id' => Plan::query()->value('id'), 'amount' => 1000, 'currency' => 'AMD', 'status' => 'pending']);
    PaymentTransaction::create(['business_id' => $business->id, 'invoice_id' => $invoice->id, 'provider' => 'idram', 'provider_transaction_id' => 'pay_'.$slug, 'amount' => 1000, 'currency' => 'AMD', 'status' => 'pending']);
    PaymentWebhookEvent::create(['provider' => 'idram', 'event_type' => 'payment_confirmation', 'transaction_reference' => 'pay_'.$slug, 'payload' => [], 'status' => 'received', 'deduplication_key' => 'idram:'.$slug]);
    Storage::disk('public')->put("businesses/{$business->id}/gallery/a.jpg", 'x');

    return $business;
}

beforeEach(fn () => Storage::fake('public'));

it('previews then removes only the selected test business and all of its data', function () {
    $test = purgeFixture('qa-test-space');
    $keep = purgeFixture('real-salon');
    $this->artisan('vizit:purge-businesses', ['slugs' => ['qa-test-space'], '--dry-run' => true])->assertSuccessful();
    expect(Business::whereKey($test->id)->exists())->toBeTrue();

    $this->artisan('vizit:purge-businesses', ['slugs' => ['qa-test-space'], '--force' => true])->assertSuccessful();

    expect(Business::whereKey($test->id)->exists())->toBeFalse()
        ->and(User::where('business_id', $test->id)->count())->toBe(0)
        ->and(DB::table('bookings')->where('business_id', $test->id)->count())->toBe(0)
        ->and(DB::table('payment_webhook_events')->where('transaction_reference', 'pay_qa-test-space')->count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->count())->toBe(1);
    Storage::disk('public')->assertMissing("businesses/{$test->id}/gallery/a.jpg");
    Storage::disk('public')->assertExists("businesses/{$keep->id}/gallery/a.jpg");
    expect(DB::table('bookings')->where('business_id', $keep->id)->count())->toBe(1)
        ->and(DB::table('booking_items')->count())->toBe(1);
});

it('refuses unknown slugs without deleting anything', function () {
    purgeFixture('qa-one');
    $this->artisan('vizit:purge-businesses', ['slugs' => ['qa-one', 'missing'], '--force' => true])->assertFailed();
    expect(Business::where('slug', 'qa-one')->exists())->toBeTrue();
});

it('lets only a super admin purge from the panel after retyping the slug, and audits it', function () {
    $test = purgeFixture('qa-panel');
    Sanctum::actingAs(Admin::create(['name' => 'Support', 'email' => 'support@vizit.test', 'password' => Hash::make('x-secure-pass-1'), 'role' => Admin::ROLE_SUPPORT, 'is_active' => true]));
    $this->deleteJson("/api/admin/businesses/{$test->id}", ['confirm_slug' => 'qa-panel'])->assertForbidden();
    Sanctum::actingAs(Admin::create(['name' => 'Owner', 'email' => 'owner@vizit.test', 'password' => Hash::make('x-secure-pass-1'), 'role' => Admin::ROLE_SUPER_ADMIN, 'is_active' => true]));
    $this->getJson("/api/admin/businesses/{$test->id}/purge-preview")->assertOk()->assertJsonPath('data.counts.bookings', 1)->assertJsonPath('data.counts.stored_files', 1);
    $this->deleteJson("/api/admin/businesses/{$test->id}", ['confirm_slug' => 'wrong'])->assertUnprocessable();
    expect(Business::whereKey($test->id)->exists())->toBeTrue();
    $this->deleteJson("/api/admin/businesses/{$test->id}", ['confirm_slug' => 'qa-panel'])->assertOk();
    expect(Business::whereKey($test->id)->exists())->toBeFalse()
        ->and(AdminLog::where('action', 'purge_business')->sole()->old_values['business']['slug'])->toBe('qa-panel');
});
