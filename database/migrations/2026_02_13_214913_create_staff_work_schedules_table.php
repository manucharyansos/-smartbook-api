<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_work_schedules', function (Blueprint $table) {
            $table->id();

            // Foreign keys
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('users')->cascadeOnDelete();

            // Schedule details
            $table->unsignedTinyInteger('day_of_week'); // 0=Sunday ... 6=Saturday
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();

            // Optional break times
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();

            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_closed')->default(false);

            $table->timestamps();

            // Unique constraint
            $table->unique(['staff_id', 'day_of_week']);

            // Indexes for performance
            $table->index(['business_id', 'day_of_week']);
            $table->index(['staff_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_work_schedules');
    }
};
