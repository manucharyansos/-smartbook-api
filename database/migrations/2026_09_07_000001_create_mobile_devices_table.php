<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_devices', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('audience', 20)->index();
            $table->string('expo_push_token')->unique();
            $table->string('platform', 20);
            $table->string('device_id')->nullable();
            $table->string('app_version', 40)->nullable();
            $table->string('locale', 8)->nullable();
            $table->string('timezone', 80)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('disabled_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_devices');
    }
};
