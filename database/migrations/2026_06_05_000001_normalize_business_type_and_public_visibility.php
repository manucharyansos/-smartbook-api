<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('businesses')) {
            return;
        }

        $addVertical = !Schema::hasColumn('businesses', 'vertical');
        $addPublicProfile = !Schema::hasColumn('businesses', 'is_public_profile_enabled');
        $addMarketplace = !Schema::hasColumn('businesses', 'is_marketplace_visible');

        if ($addVertical || $addPublicProfile || $addMarketplace) {
            Schema::table('businesses', function (Blueprint $table) use ($addVertical, $addPublicProfile, $addMarketplace) {
                if ($addVertical) {
                    $table->string('vertical', 40)->default('services')->index();
                }
                if ($addPublicProfile) {
                    $table->boolean('is_public_profile_enabled')->default(false);
                }
                if ($addMarketplace) {
                    $table->boolean('is_marketplace_visible')->default(false);
                }
            });
        }

        $columns = array_values(array_filter([
            'id',
            Schema::hasColumn('businesses', 'business_type') ? 'business_type' : null,
            Schema::hasColumn('businesses', 'vertical') ? 'vertical' : null,
            Schema::hasColumn('businesses', 'is_public') ? 'is_public' : null,
            Schema::hasColumn('businesses', 'is_onboarding_completed') ? 'is_onboarding_completed' : null,
            Schema::hasColumn('businesses', 'is_public_profile_enabled') ? 'is_public_profile_enabled' : null,
            Schema::hasColumn('businesses', 'is_marketplace_visible') ? 'is_marketplace_visible' : null,
        ]));

        DB::table('businesses')->select($columns)->orderBy('id')->chunkById(200, function ($businesses) {
            foreach ($businesses as $business) {
                $raw = strtolower(trim((string) (($business->vertical ?? null) ?: ($business->business_type ?? null))));
                $vertical = in_array($raw, ['healthcare', 'medical', 'clinic', 'dental', 'doctor', 'health'], true)
                    ? 'healthcare'
                    : 'services';
                $visible = (bool) ($business->is_public ?? false)
                    || (bool) ($business->is_onboarding_completed ?? false)
                    || (bool) ($business->is_public_profile_enabled ?? false)
                    || (bool) ($business->is_marketplace_visible ?? false);

                $updates = [];
                if (Schema::hasColumn('businesses', 'business_type')) $updates['business_type'] = $vertical;
                if (Schema::hasColumn('businesses', 'vertical')) $updates['vertical'] = $vertical;
                if (Schema::hasColumn('businesses', 'is_public') && $visible) $updates['is_public'] = true;
                if (Schema::hasColumn('businesses', 'is_onboarding_completed') && $visible) $updates['is_onboarding_completed'] = true;
                if (Schema::hasColumn('businesses', 'is_public_profile_enabled') && $visible) $updates['is_public_profile_enabled'] = true;
                if (Schema::hasColumn('businesses', 'is_marketplace_visible') && $visible) $updates['is_marketplace_visible'] = true;

                if ($updates) {
                    DB::table('businesses')->where('id', $business->id)->update($updates);
                }
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('businesses')) {
            return;
        }

        Schema::table('businesses', function (Blueprint $table) {
            if (Schema::hasColumn('businesses', 'is_marketplace_visible')) {
                $table->dropColumn('is_marketplace_visible');
            }
            if (Schema::hasColumn('businesses', 'is_public_profile_enabled')) {
                $table->dropColumn('is_public_profile_enabled');
            }
        });
    }
};
