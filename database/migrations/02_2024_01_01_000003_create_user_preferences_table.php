<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('locale', 8)->default('hy');
            $table->string('theme', 20)->default('system'); // light | dark | system
            $table->string('timezone', 64)->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['locale', 'theme']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
