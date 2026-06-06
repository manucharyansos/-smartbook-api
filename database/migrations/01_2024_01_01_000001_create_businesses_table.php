<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('business_type')->default('beauty'); // legacy: beauty, dental, salon, clinic
            $table->string('vertical', 40)->default('services'); // services | healthcare
            $table->foreignId('business_category_id')->nullable()->constrained('business_categories')->nullOnDelete();
            $table->string('custom_category_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_onboarding_completed')->default(false);
            $table->enum('status', ['pending', 'active', 'suspended', 'rejected'])->default('active');
            $table->boolean('is_public')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->enum('billing_status', ['active', 'suspended'])->default('active');
            $table->timestamp('suspended_at')->nullable();
            $table->time('work_start')->nullable();
            $table->time('work_end')->nullable();
            $table->unsignedSmallInteger('slot_step_minutes')->default(15);
            $table->string('timezone', 64)->default('Asia/Yerevan');
            $table->timestamps();

            // Indexes
            $table->index('business_type');
            $table->index(['vertical', 'business_category_id']);
            $table->index(['status', 'is_public']);
            $table->index('status');
        });
    }

    public function down(): void {
        Schema::dropIfExists('businesses');
    }
};
