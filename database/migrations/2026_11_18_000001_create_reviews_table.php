<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->string('author_name', 80);
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->string('report_reason', 500)->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->unsignedBigInteger('hidden_by_admin_id')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'hidden_at', 'created_at']);
            $table->index(['staff_id', 'hidden_at']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('review_token_hash')->nullable();
            $table->timestamp('review_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['review_token_hash', 'review_requested_at']);
        });
        Schema::dropIfExists('reviews');
    }
};
