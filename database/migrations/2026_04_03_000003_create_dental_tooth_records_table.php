<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dental_tooth_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('tooth_number', 8);
            $table->string('status')->default('healthy');
            $table->string('condition_label')->nullable();
            $table->json('surface_summary')->nullable();
            $table->text('notes')->nullable();
            $table->text('recommendation')->nullable();
            $table->dateTime('last_treated_at')->nullable();
            $table->dateTime('next_action_due_at')->nullable();
            $table->string('priority')->default('routine');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'tooth_number']);
            $table->index(['business_id', 'client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dental_tooth_records');
    }
};
