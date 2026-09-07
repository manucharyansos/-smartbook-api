<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('reference', 64)->unique();
            $table->unsignedInteger('amount');
            $table->string('currency', 10)->default('AMD');
            $table->string('status', 20)->default('pending');
            $table->text('checkout_url')->nullable();
            $table->text('return_url');
            $table->text('cancel_url');
            $table->json('provider_payload')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
            $table->index(['booking_id', 'status']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void { Schema::dropIfExists('booking_payments'); }
};
