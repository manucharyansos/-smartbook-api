<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_location_id')->constrained('business_locations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['staff_id', 'business_location_id'], 'staff_location_unique');
            $table->index('business_location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_locations');
    }
};
