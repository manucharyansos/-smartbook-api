<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (!Schema::hasColumn('plans', 'monthly_price')) {
                $table->unsignedInteger('monthly_price')->nullable()->after('price_dental');
            }
            if (!Schema::hasColumn('plans', 'yearly_price')) {
                $table->unsignedInteger('yearly_price')->nullable()->after('monthly_price');
            }
        });

        DB::table('plans')
            ->whereNull('monthly_price')
            ->update([
                'monthly_price' => DB::raw('COALESCE(price, price_beauty, price_dental, 0)'),
            ]);

        DB::table('plans')
            ->whereNull('yearly_price')
            ->update([
                'yearly_price' => DB::raw('COALESCE(monthly_price, price, price_beauty, price_dental, 0) * 10'),
            ]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (Schema::hasColumn('plans', 'yearly_price')) {
                $table->dropColumn('yearly_price');
            }
            if (Schema::hasColumn('plans', 'monthly_price')) {
                $table->dropColumn('monthly_price');
            }
        });
    }
};
