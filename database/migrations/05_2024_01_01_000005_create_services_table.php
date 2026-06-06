<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('business_locations')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->unsignedInteger('price')->nullable();
            $table->string('currency', 10)->default('AMD');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'name']);
            $table->index(['business_id', 'is_active']);
            $table->index(['business_id', 'location_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('services');
    }
};
