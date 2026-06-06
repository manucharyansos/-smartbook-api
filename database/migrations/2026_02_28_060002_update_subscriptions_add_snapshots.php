<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'plan_version')) {
                $table->unsignedInteger('plan_version')->nullable()->after('plan_id');
            }
            if (!Schema::hasColumn('subscriptions', 'seats_limit_snapshot')) {
                $table->unsignedSmallInteger('seats_limit_snapshot')->nullable()->after('plan_version');
            }
            if (!Schema::hasColumn('subscriptions', 'features_snapshot')) {
                $table->json('features_snapshot')->nullable()->after('seats_limit_snapshot');
            }
            if (!Schema::hasColumn('subscriptions', 'cancel_at_period_end')) {
                $table->boolean('cancel_at_period_end')->default(false)->after('current_period_ends_at');
            }
            if (!Schema::hasColumn('subscriptions', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->after('canceled_at');
            }
        });

        // Backfill snapshots from plans
        // plan_version := plans.version (or 1)
        // seats_limit_snapshot := plans.staff_limit (or plans.seats)
        // features_snapshot := plans.features
        DB::statement(
            "UPDATE subscriptions s\n".
            "JOIN plans p ON p.id = s.plan_id\n".
            "SET s.plan_version = COALESCE(s.plan_version, p.version, 1),\n".
            "    s.seats_limit_snapshot = COALESCE(s.seats_limit_snapshot, p.staff_limit, p.seats),\n".
            "    s.features_snapshot = COALESCE(s.features_snapshot, p.features)"
        );

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->index(['business_id', 'status'], 'subs_business_status');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'suspended_at')) {
                $table->dropColumn('suspended_at');
            }
            if (Schema::hasColumn('subscriptions', 'cancel_at_period_end')) {
                $table->dropColumn('cancel_at_period_end');
            }
            if (Schema::hasColumn('subscriptions', 'features_snapshot')) {
                $table->dropColumn('features_snapshot');
            }
            if (Schema::hasColumn('subscriptions', 'seats_limit_snapshot')) {
                $table->dropColumn('seats_limit_snapshot');
            }
            if (Schema::hasColumn('subscriptions', 'plan_version')) {
                $table->dropColumn('plan_version');
            }
        });
    }
};
