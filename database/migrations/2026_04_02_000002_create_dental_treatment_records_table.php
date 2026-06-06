<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dental_treatment_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('visit_date')->nullable();
            $table->string('procedure_name');
            $table->string('procedure_code')->nullable();
            $table->string('diagnosis')->nullable();
            $table->json('treated_teeth')->nullable();
            $table->json('surfaces')->nullable();
            $table->text('notes')->nullable();
            $table->text('recommendation')->nullable();
            $table->string('treatment_status')->default('completed');
            $table->string('priority')->default('routine');
            $table->unsignedInteger('cost')->nullable();
            $table->dateTime('follow_up_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'client_id', 'visit_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dental_treatment_records');
    }
};
