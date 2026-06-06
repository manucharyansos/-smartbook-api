<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
//            $table->string('instagram_url')->nullable()->after('address');
//            $table->string('facebook_url')->nullable()->after('instagram_url');
            $table->string('whatsapp_phone', 40)->nullable()->after('facebook_url');
//            $table->string('messenger_url')->nullable()->after('whatsapp_phone');
        });

        Schema::table('bookings', function (Blueprint $table) {
//            $table->string('source', 40)->nullable()->after('currency');
//            $table->json('source_meta')->nullable()->after('source');
            $table->index(['business_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['business_id']);
            $table->dropColumn(['source']);
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([ 'whatsapp_phone']);
        });
    }
};
