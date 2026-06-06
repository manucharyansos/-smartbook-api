<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Preserve legacy business_type compatibility, but keep the new canonical vertical populated.
        if (Schema::hasColumn('businesses', 'vertical')) {
            DB::table('businesses')
                ->whereIn('business_type', ['dental', 'clinic'])
                ->update(['vertical' => 'healthcare']);

            DB::table('businesses')
                ->whereIn('business_type', ['beauty', 'salon'])
                ->update(['vertical' => 'services']);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('businesses', 'vertical')) {
            DB::table('businesses')->update(['vertical' => 'services']);
        }
    }
};
