<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_point_ledgers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->integer('delta_points');
            $table->string('reason', 255)->nullable();

            $table->timestamps();

            $table->index(['business_id', 'client_id']);
            $table->index(['business_id', 'booking_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_point_ledgers');
    }
};
