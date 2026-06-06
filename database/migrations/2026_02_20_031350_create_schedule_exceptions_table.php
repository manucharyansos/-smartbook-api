<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date');
            $table->boolean('is_closed')->default(true);
            $table->time('start')->nullable();
            $table->time('end')->nullable();
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'user_id', 'date']);
            $table->index(['business_id', 'date']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('schedule_exceptions');
    }
};
