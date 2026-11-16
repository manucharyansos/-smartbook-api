<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_media', function (Blueprint $table) {
            $table->string('collection', 20)->default('gallery')->after('kind');
            $table->foreignId('staff_id')->nullable()->after('collection')->constrained('users')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->after('staff_id')->constrained('services')->nullOnDelete();
            $table->index(['business_id', 'collection', 'sort_order'], 'business_media_collection_index');
        });

        Schema::table('business_locations', function (Blueprint $table) {
            $table->json('amenities')->nullable()->after('working_hours');
            $table->unsignedSmallInteger('capacity_people')->nullable()->after('amenities');
            $table->unsignedInteger('area_sqm')->nullable()->after('capacity_people');
        });
    }

    public function down(): void
    {
        Schema::table('business_locations', function (Blueprint $table) {
            $table->dropColumn(['amenities', 'capacity_people', 'area_sqm']);
        });

        Schema::table('business_media', function (Blueprint $table) {
            $table->dropIndex('business_media_collection_index');
            $table->dropConstrainedForeignId('service_id');
            $table->dropConstrainedForeignId('staff_id');
            $table->dropColumn('collection');
        });
    }
};
