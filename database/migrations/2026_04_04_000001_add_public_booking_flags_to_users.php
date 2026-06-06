<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'show_in_public_team')) {
                $table->boolean('show_in_public_team')->default(false)->after('is_active');
            }

            if (!Schema::hasColumn('users', 'is_bookable')) {
                $table->boolean('is_bookable')->default(false)->after('show_in_public_team');
            }
        });

        DB::table('users')
            ->where('role', 'staff')
            ->update([
                'show_in_public_team' => true,
                'is_bookable' => true,
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['is_bookable', 'show_in_public_team'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
