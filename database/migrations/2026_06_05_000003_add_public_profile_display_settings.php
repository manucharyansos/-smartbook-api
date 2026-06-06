<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('businesses')) {
            return;
        }

        Schema::table('businesses', function (Blueprint $table) {
            if (! Schema::hasColumn('businesses', 'description')) {
                $table->text('description')->nullable();
            }
            if (! Schema::hasColumn('businesses', 'show_logo')) {
                $table->boolean('show_logo')->default(true);
            }
            if (! Schema::hasColumn('businesses', 'show_cover')) {
                $table->boolean('show_cover')->default(true);
            }
            if (! Schema::hasColumn('businesses', 'show_staff')) {
                $table->boolean('show_staff')->default(true);
            }
            if (! Schema::hasColumn('businesses', 'show_services')) {
                $table->boolean('show_services')->default(true);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('businesses')) {
            return;
        }

        Schema::table('businesses', function (Blueprint $table) {
            foreach (['show_services', 'show_staff', 'show_cover', 'show_logo', 'description'] as $column) {
                if (Schema::hasColumn('businesses', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
