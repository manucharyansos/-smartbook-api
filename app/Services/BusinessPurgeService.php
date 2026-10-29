<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Permanently removes one business and everything it owns. Meant for test and
 * demo businesses; callers must show preview() and get explicit confirmation.
 * Global customer accounts, plans, categories and admins are never touched.
 */
class BusinessPurgeService
{
    public function preview(Business $business): array
    {
        $id = $business->id;
        $count = fn (string $table) => DB::table($table)->where('business_id', $id)->count();
        $userIds = DB::table('users')->where('business_id', $id)->pluck('id');

        return [
            'business' => ['id' => $id, 'name' => $business->name, 'slug' => $business->slug, 'status' => $business->status],
            'counts' => [
                'users' => $userIds->count(),
                'locations' => $count('business_locations'),
                'services' => $count('services'),
                'clients' => $count('clients'),
                'bookings' => $count('bookings'),
                'invoices' => $count('invoices'),
                'payment_transactions' => $count('payment_transactions'),
                'gift_cards' => $count('gift_cards'),
                'gallery_files' => $count('business_media'),
                'stored_files' => count(Storage::disk('public')->allFiles($this->folder($business))),
            ],
            'warnings' => array_values(array_filter([
                DB::table('invoices')->where('business_id', $id)->where('status', 'approved')->where('amount', '>', 0)->exists()
                    ? 'Բիզնեսը Vizit-ին իրական վճարումներ է կատարել։ Ջնջելով՝ այդ հաշիվներն էլ կջնջվեն։' : null,
                DB::table('payment_transactions')->where('business_id', $id)->where('status', 'paid')->exists()
                    ? 'Կան հաստատված առցանց վճարումներ (Idram)։' : null,
                DB::table('bookings')->where('business_id', $id)->where('starts_at', '>', now())->whereNotIn('status', ['cancelled', 'no_show'])->exists()
                    ? 'Կան ապագա ամրագրումներ։ Հաճախորդները ծանուցում չեն ստանա։' : null,
            ])),
        ];
    }

    /** @return array the preview counts of what was removed */
    public function purge(Business $business): array
    {
        $summary = $this->preview($business);
        $folder = $this->folder($business);

        DB::transaction(function () use ($business) {
            $id = $business->id;
            $bookingIds = DB::table('bookings')->where('business_id', $id)->pluck('id');
            $userIds = DB::table('users')->where('business_id', $id)->pluck('id');
            $references = DB::table('payment_transactions')->where('business_id', $id)->pluck('provider_transaction_id');

            // booking_items restrict service deletion, so remove them before the cascade reaches services.
            $bookingIds->chunk(500)->each(fn ($chunk) => DB::table('booking_items')->whereIn('booking_id', $chunk)->delete());
            $references->filter()->chunk(500)->each(fn ($chunk) => DB::table('payment_webhook_events')->whereIn('transaction_reference', $chunk)->delete());
            $userIds->chunk(500)->each(function ($chunk) {
                DB::table('personal_access_tokens')->where('tokenable_type', (new User)->getMorphClass())->whereIn('tokenable_id', $chunk)->delete();
                DB::table('mobile_devices')->where('owner_type', (new User)->getMorphClass())->whereIn('owner_id', $chunk)->delete();
            });
            DB::table('bookings')->where('business_id', $id)->delete();
            DB::table('users')->where('business_id', $id)->delete();
            DB::table('businesses')->where('id', $id)->delete();
        }, 3);

        // Files are removed only after the database commit succeeded.
        Storage::disk('public')->deleteDirectory($folder);

        return $summary;
    }

    private function folder(Business $business): string
    {
        return 'businesses/'.$business->id;
    }
}
