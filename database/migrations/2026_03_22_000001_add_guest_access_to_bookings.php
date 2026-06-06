<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('guest_access_token_hash')->nullable()->after('phone_verification_attempts');
            $table->dateTime('guest_access_expires_at')->nullable()->after('guest_access_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['guest_access_token_hash', 'guest_access_expires_at']);
        });
    }
};
