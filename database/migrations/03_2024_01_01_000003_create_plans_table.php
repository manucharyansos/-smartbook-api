<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Starter, Pro, Enterprise
            $table->string('code')->unique();
            $table->string('business_type')->nullable();
            $table->index('business_type');
            $table->text('description')->nullable();
            $table->unsignedInteger('price')->default(0);
            $table->unsignedInteger('price_beauty')->nullable();
            $table->unsignedInteger('price_dental')->nullable();
            $table->string('currency', 10)->default('AMD');
            $table->unsignedSmallInteger('seats')->default(5); // staff limit
            $table->unsignedInteger('duration_days')->default(30);
            $table->unsignedSmallInteger('locations')->default(1);
            $table->json('features')->nullable(); // additional features
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void {
        Schema::dropIfExists('plans');
    }
};
