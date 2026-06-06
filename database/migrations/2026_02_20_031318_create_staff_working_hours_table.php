<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('staff_working_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 1..7
            $table->boolean('is_closed')->default(false);
            $table->time('start')->nullable();
            $table->time('end')->nullable();
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'weekday']);
            $table->index(['business_id', 'weekday']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('staff_working_hours');
    }
};
