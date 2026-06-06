<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('client_reminder_deliveries')) {
            Schema::create('client_reminder_deliveries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_reminder_id')->constrained('client_reminders')->cascadeOnDelete();
                $table->string('channel', 30);
                $table->string('status', 30)->default('pending');
                $table->string('recipient')->nullable();
                $table->string('provider')->nullable();
                $table->dateTime('scheduled_for')->nullable();
                $table->dateTime('sent_at')->nullable();
                $table->dateTime('failed_at')->nullable();
                $table->text('error_message')->nullable();
                $table->json('payload')->nullable();
                $table->timestamps();

                $table->index(['client_reminder_id', 'channel']);
                $table->index(['status', 'scheduled_for']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_reminder_deliveries');
    }
};
