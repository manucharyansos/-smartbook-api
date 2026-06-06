<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (!Schema::hasColumn('businesses', 'short_description')) {
                $table->string('short_description', 255)->nullable()->after('address');
            }
            if (!Schema::hasColumn('businesses', 'logo_url')) {
                $table->string('logo_url')->nullable()->after('short_description');
            }
            if (!Schema::hasColumn('businesses', 'cover_url')) {
                $table->string('cover_url')->nullable()->after('logo_url');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 40)->nullable()->after('email');
            }
            if (!Schema::hasColumn('users', 'whatsapp_phone')) {
                $table->string('whatsapp_phone', 40)->nullable()->after('phone');
            }
            if (!Schema::hasColumn('users', 'avatar_url')) {
                $table->string('avatar_url')->nullable()->after('deactivated_at');
            }
            if (!Schema::hasColumn('users', 'bio')) {
                $table->text('bio')->nullable()->after('avatar_url');
            }
        });

        Schema::table('services', function (Blueprint $table) {
            if (!Schema::hasColumn('services', 'image_url')) {
                $table->string('image_url')->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $drop = [];
            foreach (['short_description', 'logo_url', 'cover_url'] as $column) {
                if (Schema::hasColumn('businesses', $column)) {
                    $drop[] = $column;
                }
            }
            if ($drop) $table->dropColumn($drop);
        });

        Schema::table('users', function (Blueprint $table) {
            $drop = [];
            foreach (['phone', 'whatsapp_phone', 'avatar_url', 'bio'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $drop[] = $column;
                }
            }
            if ($drop) $table->dropColumn($drop);
        });

        Schema::table('services', function (Blueprint $table) {
            if (Schema::hasColumn('services', 'image_url')) {
                $table->dropColumn('image_url');
            }
        });
    }
};
