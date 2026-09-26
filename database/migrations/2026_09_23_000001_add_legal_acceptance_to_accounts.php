<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 20)->nullable();
            $table->string('privacy_version', 20)->nullable();
            $table->string('legal_acceptance_ip', 45)->nullable();
            $table->string('legal_acceptance_user_agent', 500)->nullable();
        });

        Schema::table('client_accounts', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 20)->nullable();
            $table->string('privacy_version', 20)->nullable();
            $table->string('legal_acceptance_ip', 45)->nullable();
            $table->string('legal_acceptance_user_agent', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'terms_accepted_at',
                'terms_version',
                'privacy_version',
                'legal_acceptance_ip',
                'legal_acceptance_user_agent',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'terms_accepted_at',
                'terms_version',
                'privacy_version',
                'legal_acceptance_ip',
                'legal_acceptance_user_agent',
            ]);
        });
    }
};
