<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('gift_card_ledgers')) {
            return;
        }

        Schema::create('gift_card_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gift_card_id')->constrained('gift_cards')->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->integer('delta_amount');
            $table->string('entry_type', 40)->default('adjustment');
            $table->string('reason', 255)->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('reverted_ledger_id')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'gift_card_id']);
            $table->index(['booking_id', 'entry_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_card_ledgers');
    }
};
