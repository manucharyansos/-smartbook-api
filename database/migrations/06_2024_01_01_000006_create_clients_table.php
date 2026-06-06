<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();

            // Dental fields
            $table->date('birth_date')->nullable();
            $table->string('blood_type')->nullable();
            $table->text('medical_history')->nullable();
            $table->text('allergies')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->json('medical_notes')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'phone']);
            $table->index(['business_id', 'email']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('clients');
    }
};
