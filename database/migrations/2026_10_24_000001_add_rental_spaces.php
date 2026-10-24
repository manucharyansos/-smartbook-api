<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedInteger('rental_hourly_price')->nullable();
            $table->unsignedSmallInteger('rental_min_minutes')->default(60);
            $table->unsignedSmallInteger('rental_max_minutes')->default(720);
            $table->unsignedSmallInteger('rental_buffer_minutes')->default(0);
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id')->nullable()->change();
            $table->index(['business_id', 'ends_at'], 'bookings_business_end_index');
        });
        Schema::create('business_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('path', 500);
            $table->string('mime_type', 100);
            $table->string('kind', 10);
            $table->string('caption')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['business_id', 'sort_order']);
        });
        DB::table('business_categories')->insertOrIgnore([
            'vertical' => 'rentals', 'slug' => 'rental-space', 'name_hy' => 'Վարձով տարածքներ',
            'name_ru' => 'Аренда помещений', 'name_en' => 'Spaces for rent',
            'icon' => 'building', 'is_active' => true, 'sort_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('plans')->insertOrIgnore([
            'code' => 'rental', 'name' => 'Վարձով տարածք', 'version' => 1,
            'business_type' => null, 'allowed_business_types' => json_encode(['rentals']),
            'description' => 'Տարածքի ամրագրում, պատկերասրահ, օրացույց և վերլուծություն։',
            'price' => 25000, 'monthly_price' => 25000, 'yearly_price' => 300000,
            'currency' => 'AMD', 'seats' => 0, 'staff_limit' => 0, 'duration_days' => 30,
            'locations' => 1, 'features' => json_encode(['rentals' => true, 'staff_limit' => 0, 'services_limit' => 0]),
            'is_active' => true, 'is_visible' => true, 'sort_order' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Booking history may now contain genuine space reservations with no service.
        // Keep their nullable service_id and the rental plan/category to preserve that history.
        Schema::dropIfExists('business_media');
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn([
            'rental_hourly_price', 'rental_min_minutes', 'rental_max_minutes', 'rental_buffer_minutes',
        ]));
        Schema::table('bookings', fn (Blueprint $table) => $table->dropIndex('bookings_business_end_index'));
    }
};
