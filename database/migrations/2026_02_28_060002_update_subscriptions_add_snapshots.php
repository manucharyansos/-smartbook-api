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
        DB::table('subscriptions')
            ->orderBy('id')
            ->chunkById(200, function ($subscriptions) {
                $plans = DB::table('plans')
                    ->whereIn('id', $subscriptions->pluck('plan_id')->filter()->unique()->values())
                    ->get()
                    ->keyBy('id');

                foreach ($subscriptions as $subscription) {
                    $plan = $plans->get($subscription->plan_id);
                    if (!$plan) continue;

                    $updates = [];
                    if ($subscription->plan_version === null) {
                        $updates['plan_version'] = $plan->version ?? 1;
                    }
                    if ($subscription->seats_limit_snapshot === null) {
                        $updates['seats_limit_snapshot'] = $plan->staff_limit ?? $plan->seats;
                    }
                    if ($subscription->features_snapshot === null) {
                        $updates['features_snapshot'] = $plan->features;
                    }

                    if ($updates !== []) {
                        DB::table('subscriptions')->where('id', $subscription->id)->update($updates);
                    }
                }
            });

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
