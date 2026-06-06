<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('loyalty_programs', function (Blueprint $table) {
            if (!Schema::hasColumn('loyalty_programs', 'redeem_points_step')) {
                $table->unsignedInteger('redeem_points_step')->default(10)->after('points_per_currency_unit');
            }
            if (!Schema::hasColumn('loyalty_programs', 'redeem_currency_amount')) {
                $table->unsignedInteger('redeem_currency_amount')->default(100)->after('redeem_points_step');
            }
            if (!Schema::hasColumn('loyalty_programs', 'max_redeem_percent')) {
                $table->unsignedTinyInteger('max_redeem_percent')->default(50)->after('redeem_currency_amount');
            }
            if (!Schema::hasColumn('loyalty_programs', 'allow_gift_card_with_points')) {
                $table->boolean('allow_gift_card_with_points')->default(true)->after('max_redeem_percent');
            }
            if (!Schema::hasColumn('loyalty_programs', 'points_expire_after_days')) {
                $table->unsignedInteger('points_expire_after_days')->default(0)->after('allow_gift_card_with_points');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_programs', function (Blueprint $table) {
            foreach (['redeem_points_step', 'redeem_currency_amount', 'max_redeem_percent', 'allow_gift_card_with_points', 'points_expire_after_days'] as $column) {
                if (Schema::hasColumn('loyalty_programs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
