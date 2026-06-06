<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('booking_blocks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Optional: staff-specific block (if you want later)
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            $table->string('reason', 190)->nullable();

            $table->timestamps();

            $table->index(['business_id', 'starts_at']);
            $table->index(['business_id', 'ends_at']);
            $table->index(['staff_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_blocks');
    }
};
