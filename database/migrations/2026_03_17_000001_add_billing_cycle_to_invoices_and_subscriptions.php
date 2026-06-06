<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'billing_cycle')) {
                $table->string('billing_cycle', 20)->default('monthly')->after('currency');
            }
            if (!Schema::hasColumn('invoices', 'meta')) {
                $table->json('meta')->nullable()->after('note');
            }
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'billing_cycle')) {
                $table->string('billing_cycle', 20)->default('monthly')->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'billing_cycle')) {
                $table->dropColumn('billing_cycle');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'meta')) {
                $table->dropColumn('meta');
            }
            if (Schema::hasColumn('invoices', 'billing_cycle')) {
                $table->dropColumn('billing_cycle');
            }
        });
    }
};
