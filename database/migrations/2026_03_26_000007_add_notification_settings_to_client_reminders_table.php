<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('client_reminders', function (Blueprint $table) {
            if (!Schema::hasColumn('client_reminders', 'is_enabled')) {
                $table->boolean('is_enabled')->default(true)->after('status');
            }
            if (!Schema::hasColumn('client_reminders', 'lead_minutes')) {
                $table->unsignedInteger('lead_minutes')->default(0)->after('is_enabled');
            }
            if (!Schema::hasColumn('client_reminders', 'notify_via')) {
                $table->json('notify_via')->nullable()->after('lead_minutes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_reminders', function (Blueprint $table) {
            $drops = [];
            foreach (['is_enabled', 'lead_minutes', 'notify_via'] as $col) {
                if (Schema::hasColumn('client_reminders', $col)) {
                    $drops[] = $col;
                }
            }
            if ($drops) {
                $table->dropColumn($drops);
            }
        });
    }
};
