<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('loyalty_point_ledgers', function (Blueprint $table) {
            if (!Schema::hasColumn('loyalty_point_ledgers', 'entry_type')) {
                $table->string('entry_type', 40)->default('adjustment')->after('delta_points');
            }
            if (!Schema::hasColumn('loyalty_point_ledgers', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('reason');
            }
            if (!Schema::hasColumn('loyalty_point_ledgers', 'meta')) {
                $table->json('meta')->nullable()->after('expires_at');
            }
            if (!Schema::hasColumn('loyalty_point_ledgers', 'reverted_ledger_id')) {
                $table->unsignedBigInteger('reverted_ledger_id')->nullable()->after('meta');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_point_ledgers', function (Blueprint $table) {
            foreach (['entry_type', 'expires_at', 'meta', 'reverted_ledger_id'] as $column) {
                if (Schema::hasColumn('loyalty_point_ledgers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
