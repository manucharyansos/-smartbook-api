<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dental_client_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->text('chief_complaint')->nullable();
            $table->text('dental_history')->nullable();
            $table->text('current_medications')->nullable();
            $table->text('treatment_alerts')->nullable();
            $table->string('insurance_provider')->nullable();
            $table->string('insurance_number')->nullable();
            $table->string('preferred_doctor')->nullable();
            $table->unsignedTinyInteger('pain_level')->nullable();
            $table->string('oral_hygiene_status')->nullable();
            $table->string('periodontal_risk')->nullable();
            $table->dateTime('last_xray_at')->nullable();
            $table->dateTime('next_follow_up_at')->nullable();
            $table->timestamps();

            $table->unique(['client_id']);
            $table->index(['business_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dental_client_profiles');
    }
};
