<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->boolean('is_enabled')->default(false);

            // Earning rule: points = floor(amount / currency_unit) * points_per_currency_unit
            $table->unsignedInteger('currency_unit')->default(1000);
            $table->unsignedInteger('points_per_currency_unit')->default(1);
            $table->unsignedInteger('min_booking_amount')->default(0);

            $table->string('notes', 255)->nullable();

            $table->timestamps();

            $table->unique('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_programs');
    }
};
