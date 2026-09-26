<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contact_requests', function (Blueprint $table) {
            // Nullable keeps historical requests valid while all new requests require consent.
            $table->timestamp('privacy_accepted_at')->nullable()->after('status');
            $table->string('privacy_version', 32)->nullable()->after('privacy_accepted_at');
            $table->string('legal_acceptance_ip', 45)->nullable()->after('privacy_version');
            $table->string('legal_acceptance_user_agent', 1024)->nullable()->after('legal_acceptance_ip');
        });
    }

    public function down(): void
    {
        Schema::table('contact_requests', function (Blueprint $table) {
            $table->dropColumn([
                'privacy_accepted_at',
                'privacy_version',
                'legal_acceptance_ip',
                'legal_acceptance_user_agent',
            ]);
        });
    }
};
