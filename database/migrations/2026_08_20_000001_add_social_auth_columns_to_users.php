<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'provider')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('provider', 40)->nullable()->after('password');
            });
        }

        if (!Schema::hasColumn('users', 'provider_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('provider_id', 191)->nullable()->after('provider');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique(['provider', 'provider_id'], 'users_provider_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_provider_identity_unique');
        });

        $columns = array_values(array_filter(
            ['provider', 'provider_id'],
            fn (string $column) => Schema::hasColumn('users', $column)
        ));

        if ($columns !== []) {
            Schema::table('users', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
