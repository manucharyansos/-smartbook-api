<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'is_bookable') && Schema::hasColumn('users', 'show_in_public_team')) {
            DB::table('users')
                ->where('role', 'staff')
                ->where('is_active', true)
                ->update([
                    'is_bookable' => true,
                    'show_in_public_team' => true,
                ]);

            if (Schema::hasTable('businesses')) {
                DB::table('businesses')->select('id')->orderBy('id')->chunkById(200, function ($businesses) {
                    foreach ($businesses as $business) {
                        $hasBookableProvider = DB::table('users')
                            ->where('business_id', $business->id)
                            ->where('is_active', true)
                            ->where('is_bookable', true)
                            ->exists();

                        if (! $hasBookableProvider) {
                            DB::table('users')
                                ->where('business_id', $business->id)
                                ->where('role', 'owner')
                                ->where('is_active', true)
                                ->limit(1)
                                ->update([
                                    'is_bookable' => true,
                                    'show_in_public_team' => true,
                                ]);
                        }
                    }
                });
            }
        }

        if (! Schema::hasTable('businesses') || ! Schema::hasTable('business_locations')) {
            return;
        }

        DB::table('businesses')->select('id')->orderBy('id')->chunkById(200, function ($businesses) {
            foreach ($businesses as $business) {
                $locations = DB::table('business_locations')
                    ->where('business_id', $business->id)
                    ->when(Schema::hasColumn('business_locations', 'is_active'), fn ($query) => $query->where('is_active', true))
                    ->orderByDesc('is_primary')
                    ->orderBy('id')
                    ->pluck('id');

                if ($locations->count() !== 1) {
                    continue;
                }

                $locationId = (int) $locations->first();

                if (Schema::hasTable('services') && Schema::hasColumn('services', 'location_id')) {
                    DB::table('services')
                        ->where('business_id', $business->id)
                        ->whereNull('location_id')
                        ->update(['location_id' => $locationId]);
                }

                if (Schema::hasTable('users') && Schema::hasColumn('users', 'location_id')) {
                    DB::table('users')
                        ->where('business_id', $business->id)
                        ->whereNull('location_id')
                        ->update(['location_id' => $locationId]);
                }
            }
        });
    }

    public function down(): void
    {
        // Data repair migration: do not make existing providers or services unavailable on rollback.
    }
};
