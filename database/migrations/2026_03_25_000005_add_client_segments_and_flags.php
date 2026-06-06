<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'group_name')) {
                $table->string('group_name')->nullable()->after('notes');
            }
            if (!Schema::hasColumn('clients', 'is_vip')) {
                $table->boolean('is_vip')->default(false)->after('group_name');
            }
            if (!Schema::hasColumn('clients', 'is_blacklisted')) {
                $table->boolean('is_blacklisted')->default(false)->after('is_vip');
            }
            if (!Schema::hasColumn('clients', 'blacklist_reason')) {
                $table->text('blacklist_reason')->nullable()->after('is_blacklisted');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            foreach (['blacklist_reason', 'is_blacklisted', 'is_vip', 'group_name'] as $column) {
                if (Schema::hasColumn('clients', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
