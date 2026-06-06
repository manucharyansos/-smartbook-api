<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('phone_verification_code_hash')->nullable()->after('client_phone');
            $table->dateTime('phone_verification_expires_at')->nullable()->after('phone_verification_code_hash');
            $table->dateTime('phone_verified_at')->nullable()->after('phone_verification_expires_at');
            $table->unsignedSmallInteger('phone_verification_attempts')->default(0)->after('phone_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'phone_verification_code_hash',
                'phone_verification_expires_at',
                'phone_verified_at',
                'phone_verification_attempts',
            ]);
        });
    }
};
