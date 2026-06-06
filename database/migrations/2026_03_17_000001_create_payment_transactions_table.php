<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('idbank_mock');
            $table->string('provider_transaction_id')->unique();
            $table->string('provider_payment_id')->nullable()->index();
            $table->string('payment_method')->nullable();
            $table->unsignedInteger('amount');
            $table->string('currency', 10)->default('AMD');
            $table->string('status')->default('pending');
            $table->text('checkout_url')->nullable();
            $table->json('checkout_payload')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'status']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
