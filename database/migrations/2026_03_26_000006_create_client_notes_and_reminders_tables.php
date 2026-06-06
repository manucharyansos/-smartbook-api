<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('client_notes')) {
            Schema::create('client_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('note_type', 50)->default('general');
                $table->boolean('is_pinned')->default(false);
                $table->text('body');
                $table->timestamps();

                $table->index(['client_id', 'created_at']);
                $table->index(['business_id', 'note_type']);
            });
        }

        if (!Schema::hasTable('client_reminders')) {
            Schema::create('client_reminders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('title');
                $table->text('note')->nullable();
                $table->dateTime('remind_at');
                $table->string('channel', 30)->nullable()->default('internal');
                $table->string('status', 20)->default('pending');
                $table->dateTime('completed_at')->nullable();
                $table->timestamps();

                $table->index(['client_id', 'remind_at']);
                $table->index(['business_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_reminders');
        Schema::dropIfExists('client_notes');
    }
};
