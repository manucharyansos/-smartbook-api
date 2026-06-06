<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->uuid('group_id')->nullable();
            $table->string('booking_code', 32)->nullable()->unique();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('business_locations')->nullOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            // ✅ Ավելացնենք client_name և client_phone (պատմական տվյալների համար)
            $table->string('client_name')->nullable();
            $table->string('client_phone')->nullable();

            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            $table->enum('status', ['pending', 'confirmed', 'in_progress', 'done','completed', 'cancelled', 'no_show'])
                ->default('pending');
            $table->text('notes')->nullable();
            $table->unsignedInteger('final_price')->nullable();
            $table->string('currency', 10)->default('AMD');

            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->text('clinical_notes')->nullable();
            $table->json('treatment_codes')->nullable();
            $table->boolean('is_emergency')->default(false);

            $table->timestamps();

            $table->index(['business_id', 'starts_at']);
            $table->index(['business_id', 'location_id', 'starts_at']);
            $table->index(['business_id', 'status']);
            $table->index(['staff_id', 'starts_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('bookings');
    }
};
