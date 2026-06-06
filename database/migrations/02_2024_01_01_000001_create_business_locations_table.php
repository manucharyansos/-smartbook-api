<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('address')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('district', 120)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('phone', 40)->nullable();
            $table->json('working_hours')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->timestamps();

            $table->index(['business_id', 'sort_order']);
            $table->index(['business_id', 'is_primary']);
            $table->index(['business_id', 'is_active']);
            $table->index(['city', 'district']);
            $table->index(['latitude', 'longitude']);
        });

        // Kept for compatibility if this migration is ever run on a non-empty database.
        $businesses = DB::table('businesses')
            ->select('id', 'name', 'address', 'phone')
            ->whereNotNull('address')
            ->where('address', '!=', '')
            ->orderBy('id')
            ->get();

        $now = now();
        $rows = [];
        foreach ($businesses as $business) {
            $rows[] = [
                'business_id' => $business->id,
                'name' => $business->name,
                'address' => $business->address,
                'phone' => $business->phone,
                'is_primary' => true,
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('business_locations')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('business_locations');
    }
};
