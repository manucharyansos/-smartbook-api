<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Anonymous first-party analytics: no IP address, cookie or account is stored.
        Schema::create('site_events', function (Blueprint $table) {
            $table->id();
            $table->string('event', 40);
            $table->string('path', 255)->nullable();
            $table->foreignId('business_id')->nullable()->constrained()->cascadeOnDelete();
            $table->char('visitor', 32); // daily-rotating hash, cannot follow a person across days
            $table->string('source', 10)->default('web'); // web | mobile
            $table->string('device', 10)->nullable(); // mobile | tablet | desktop
            $table->string('referrer_host', 100)->nullable();
            $table->string('utm_source', 60)->nullable();
            $table->string('locale', 5)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['created_at', 'event']);
            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_events');
    }
};
