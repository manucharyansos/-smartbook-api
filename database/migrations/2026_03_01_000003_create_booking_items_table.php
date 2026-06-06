<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('booking_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('service_id');
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('duration_minutes');
            $table->unsignedInteger('price')->nullable();
            $table->string('currency', 8)->default('AMD');
            $table->timestamps();

            $table->index(['booking_id', 'position']);

            $table->foreign('booking_id')
                ->references('id')->on('bookings')
                ->onDelete('cascade');

            $table->foreign('service_id')
                ->references('id')->on('services')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_items');
    }
};
