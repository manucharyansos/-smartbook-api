<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('trial_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('phone_norm')->nullable()->index();
            $table->string('fingerprint')->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('ip')->nullable();
            $table->timestamps();

            $table->index(['phone_norm', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_attempts');
    }
};
