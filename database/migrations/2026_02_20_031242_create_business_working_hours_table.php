<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('business_working_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 1=Monday ... 7=Sunday
            $table->boolean('is_closed')->default(false);
            $table->time('start')->nullable();
            $table->time('end')->nullable();
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'weekday']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('business_working_hours');
    }
};
