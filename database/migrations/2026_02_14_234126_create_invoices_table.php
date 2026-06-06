<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('amount');
            $table->string('currency', 10)->default('AMD');
            $table->string('status')->default('pending'); // pending, paid, overdue, cancelled
            $table->string('payment_method')->nullable(); // bank_transfer, idram, card, cash
            $table->string('note')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('invoices');
    }
};
