<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('business_categories')) {
            $now = now();
            $categories = [
                ['services', 'beauty-salon', 'Գեղեցկության սրահ', 'Салон красоты', 'Beauty salon', 'sparkles', 10],
                ['services', 'barber-shop', 'Բարբերշոփ', 'Барбершоп', 'Barber shop', 'scissors', 20],
                ['services', 'nail-studio', 'Մատնահարդարման ստուդիա', 'Ногтевая студия', 'Nail studio', 'hand', 30],
                ['services', 'massage-spa', 'Մերսում և SPA', 'Массаж и SPA', 'Massage & SPA', 'spa', 40],
                ['services', 'fitness-trainer', 'Ֆիթնես մարզիչ', 'Фитнес-тренер', 'Fitness trainer', 'dumbbell', 50],
                ['services', 'car-wash', 'Ավտոլվացում', 'Автомойка', 'Car wash', 'car', 60],
                ['services', 'auto-service', 'Ավտոսերվիս', 'Автосервис', 'Auto service', 'wrench', 70],
                ['services', 'consulting', 'Խորհրդատվություն', 'Консультации', 'Consulting', 'messages', 80],
                ['services', 'courses', 'Դասընթացներ', 'Курсы', 'Courses', 'book-open', 90],
                ['services', 'photo-studio', 'Ֆոտոստուդիա', 'Фотостудия', 'Photo studio', 'camera', 100],
                ['services', 'other-services', 'Այլ ծառայություն', 'Другая услуга', 'Other service', 'grid', 999],
                ['healthcare', 'clinic', 'Կլինիկա', 'Клиника', 'Clinic', 'hospital', 10],
                ['healthcare', 'dental-clinic', 'Ատամնաբուժարան', 'Стоматология', 'Dental clinic', 'tooth', 20],
                ['healthcare', 'private-doctor', 'Մասնավոր բժիշկ', 'Частный врач', 'Private doctor', 'stethoscope', 30],
                ['healthcare', 'diagnostic-center', 'Ախտորոշիչ կենտրոն', 'Диагностический центр', 'Diagnostic center', 'activity', 40],
                ['healthcare', 'laboratory', 'Լաբորատորիա', 'Лаборатория', 'Laboratory', 'test-tube', 50],
                ['healthcare', 'physiotherapy', 'Ֆիզիոթերապիա', 'Физиотерапия', 'Physiotherapy', 'heart-pulse', 60],
                ['healthcare', 'rehabilitation', 'Ռեաբիլիտացիա', 'Реабилитация', 'Rehabilitation', 'accessibility', 70],
                ['healthcare', 'other-healthcare', 'Այլ բժշկական ծառայություն', 'Другая медицинская услуга', 'Other healthcare', 'plus-circle', 999],
            ];

            foreach ($categories as [$vertical, $slug, $hy, $ru, $en, $icon, $sort]) {
                DB::table('business_categories')->updateOrInsert(
                    ['slug' => $slug],
                    [
                        'vertical' => $vertical,
                        'name_hy' => $hy,
                        'name_ru' => $ru,
                        'name_en' => $en,
                        'icon' => $icon,
                        'is_active' => true,
                        'sort_order' => $sort,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }

        if (!Schema::hasTable('businesses')) {
            return;
        }

        $addProfile = !Schema::hasColumn('businesses', 'is_public_profile_enabled');
        $addMarketplace = !Schema::hasColumn('businesses', 'is_marketplace_visible');
        if ($addProfile || $addMarketplace) {
            Schema::table('businesses', function (Blueprint $table) use ($addProfile, $addMarketplace) {
                if ($addProfile) {
                    $table->boolean('is_public_profile_enabled')->default(false)->after('is_public');
                }
                if ($addMarketplace) {
                    $table->boolean('is_marketplace_visible')->default(false)->after('is_public_profile_enabled');
                }
            });
        }

        $columns = array_values(array_filter([
            'id',
            Schema::hasColumn('businesses', 'business_type') ? 'business_type' : null,
            Schema::hasColumn('businesses', 'vertical') ? 'vertical' : null,
            Schema::hasColumn('businesses', 'business_category_id') ? 'business_category_id' : null,
            Schema::hasColumn('businesses', 'custom_category_name') ? 'custom_category_name' : null,
            Schema::hasColumn('businesses', 'is_public') ? 'is_public' : null,
            Schema::hasColumn('businesses', 'is_onboarding_completed') ? 'is_onboarding_completed' : null,
            Schema::hasColumn('businesses', 'is_public_profile_enabled') ? 'is_public_profile_enabled' : null,
            Schema::hasColumn('businesses', 'is_marketplace_visible') ? 'is_marketplace_visible' : null,
        ]));

        $categoryIds = Schema::hasTable('business_categories')
            ? DB::table('business_categories')->pluck('id', 'slug')
            : collect();

        DB::table('businesses')->select($columns)->orderBy('id')->chunkById(200, function ($businesses) use ($categoryIds) {
            foreach ($businesses as $business) {
                $raw = strtolower(trim((string) (($business->vertical ?? null) ?: ($business->business_type ?? null))));
                $vertical = in_array($raw, ['healthcare', 'medical', 'clinic', 'dental', 'doctor', 'health'], true)
                    ? 'healthcare'
                    : 'services';

                $legacyPublic = (bool) ($business->is_public ?? false);
                $completed = (bool) ($business->is_onboarding_completed ?? false);
                $profile = (bool) ($business->is_public_profile_enabled ?? false);
                $marketplace = (bool) ($business->is_marketplace_visible ?? false);
                $visible = $legacyPublic || $completed || $profile || $marketplace;

                $updates = [];
                if (Schema::hasColumn('businesses', 'business_type')) $updates['business_type'] = $vertical;
                if (Schema::hasColumn('businesses', 'vertical')) $updates['vertical'] = $vertical;
                if (Schema::hasColumn('businesses', 'is_public') && $visible) $updates['is_public'] = true;
                if (Schema::hasColumn('businesses', 'is_onboarding_completed') && $visible) $updates['is_onboarding_completed'] = true;
                if (Schema::hasColumn('businesses', 'is_public_profile_enabled') && $visible) $updates['is_public_profile_enabled'] = true;
                if (Schema::hasColumn('businesses', 'is_marketplace_visible') && $visible) $updates['is_marketplace_visible'] = true;

                if (Schema::hasColumn('businesses', 'business_category_id') && empty($business->business_category_id) && $categoryIds->isNotEmpty()) {
                    $custom = mb_strtolower(trim((string) ($business->custom_category_name ?? '')));
                    $slug = match (true) {
                        str_contains($custom, 'ավտոլվ') || str_contains($custom, 'автомой') || str_contains($custom, 'car wash') => 'car-wash',
                        str_contains($custom, 'ավտոսերվ') || str_contains($custom, 'автосерв') || str_contains($custom, 'auto service') => 'auto-service',
                        str_contains($custom, 'ատամ') || str_contains($custom, 'стомат') || str_contains($custom, 'dental') => 'dental-clinic',
                        str_contains($custom, 'կլինիկ') || str_contains($custom, 'клиник') || str_contains($custom, 'clinic') => 'clinic',
                        default => $vertical === 'healthcare' ? 'other-healthcare' : 'other-services',
                    };
                    $updates['business_category_id'] = $categoryIds[$slug] ?? null;
                }

                if ($updates) {
                    DB::table('businesses')->where('id', $business->id)->update($updates);
                }
            }
        });
    }

    public function down(): void
    {
        // Data repair and default categories are intentionally kept on rollback.
    }
};
