<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('code', 40)->unique();

            $table->unsignedInteger('initial_amount'); // store in minor units? we use integer AMD
            $table->unsignedInteger('balance');
            $table->string('currency', 8)->default('AMD');

            $table->string('issued_to_name', 120)->nullable();
            $table->string('issued_to_phone', 40)->nullable();
            $table->string('purchased_by_name', 120)->nullable();
            $table->string('purchased_by_phone', 40)->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->enum('status', ['active', 'redeemed', 'cancelled'])->default('active');
            $table->text('notes')->nullable();

            $table->timestamp('last_redeemed_at')->nullable();
            $table->unsignedInteger('redeemed_total')->default(0);

            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_cards');
    }
};
