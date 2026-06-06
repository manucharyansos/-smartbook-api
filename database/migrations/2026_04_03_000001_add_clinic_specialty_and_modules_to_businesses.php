<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (!Schema::hasColumn('businesses', 'clinic_specialty')) {
                $table->string('clinic_specialty', 60)->nullable()->after('business_type');
            }

            if (!Schema::hasColumn('businesses', 'clinic_modules')) {
                $table->json('clinic_modules')->nullable()->after('clinic_specialty');
            }
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (Schema::hasColumn('businesses', 'clinic_modules')) {
                $table->dropColumn('clinic_modules');
            }

            if (Schema::hasColumn('businesses', 'clinic_specialty')) {
                $table->dropColumn('clinic_specialty');
            }
        });
    }
};
