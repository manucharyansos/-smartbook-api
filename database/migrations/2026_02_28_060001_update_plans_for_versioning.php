<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (!Schema::hasColumn('plans', 'version')) {
                $table->unsignedInteger('version')->default(1)->after('code');
            }
            if (!Schema::hasColumn('plans', 'staff_limit')) {
                $table->unsignedSmallInteger('staff_limit')->nullable()->after('seats');
            }
            if (!Schema::hasColumn('plans', 'allowed_business_types')) {
                $table->json('allowed_business_types')->nullable()->after('business_type');
            }
        });

        // Backfill staff_limit from seats
        DB::table('plans')->whereNull('staff_limit')->update([
            'staff_limit' => DB::raw('seats'),
        ]);

        // Backfill allowed_business_types from legacy business_type
        // legacy: null => both, beauty => salon, dental => clinic
        $plans = DB::table('plans')->select('id','business_type')->get();
        foreach ($plans as $p) {
            $types = null;
            if ($p->business_type === null) {
                $types = json_encode(['salon','clinic']);
            } elseif ($p->business_type === 'beauty') {
                $types = json_encode(['salon']);
            } elseif ($p->business_type === 'dental') {
                $types = json_encode(['clinic']);
            } elseif ($p->business_type === 'salon') {
                $types = json_encode(['salon']);
            } elseif ($p->business_type === 'clinic') {
                $types = json_encode(['clinic']);
            } else {
                $types = json_encode(['salon','clinic']);
            }

            DB::table('plans')->where('id', $p->id)->update([
                'allowed_business_types' => $types,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (Schema::hasColumn('plans', 'allowed_business_types')) {
                $table->dropColumn('allowed_business_types');
            }
            if (Schema::hasColumn('plans', 'staff_limit')) {
                $table->dropColumn('staff_limit');
            }
            if (Schema::hasColumn('plans', 'version')) {
                $table->dropColumn('version');
            }
        });
    }
};
