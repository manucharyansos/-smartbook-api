<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('businesses', 'text_translations')) {
            return;
        }

        Schema::table('businesses', function (Blueprint $table) {
            // {"short_description": {"hy": "...", "ru": "...", "en": "..."}, "description": {...}}
            $table->json('text_translations')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('businesses', 'text_translations')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->dropColumn('text_translations');
            });
        }
    }
};
