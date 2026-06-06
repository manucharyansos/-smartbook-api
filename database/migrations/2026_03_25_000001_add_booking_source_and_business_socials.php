<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'source')) {
                $table->string('source', 40)->nullable()->after('notes');
            }
            if (!Schema::hasColumn('bookings', 'source_meta')) {
                $table->json('source_meta')->nullable()->after('source');
            }
        });

        Schema::table('businesses', function (Blueprint $table) {
            foreach ([
                'instagram_url' => 2048,
                'facebook_url' => 2048,
                'website_url' => 2048,
                'messenger_url' => 2048,
                'whatsapp_url' => 2048,
            ] as $column => $length) {
                if (!Schema::hasColumn('businesses', $column)) {
                    $table->string($column, $length)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            foreach (["source_meta", "source"] as $column) {
                if (Schema::hasColumn('bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('businesses', function (Blueprint $table) {
            foreach (["instagram_url", "facebook_url", "website_url", "messenger_url", "whatsapp_url"] as $column) {
                if (Schema::hasColumn('businesses', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
