<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('trialing'); // trialing, active, past_due, canceled, expired
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_starts_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();

            // Payment integration fields
            $table->string('provider')->nullable(); // stripe, arca, idram
            $table->string('provider_customer_id')->nullable();
            $table->string('provider_subscription_id')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index('trial_ends_at');
        });
    }

    public function down(): void {
        Schema::dropIfExists('subscriptions');
    }
};
