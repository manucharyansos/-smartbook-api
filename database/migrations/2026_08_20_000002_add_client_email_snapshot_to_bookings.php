<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('bookings', 'client_email')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('client_email', 190)->nullable()->after('client_phone');
            });
        }

        // The original email of old bookings cannot always be reconstructed,
        // but backfilling the current client email keeps legacy records useful.
        DB::table('bookings')
            ->whereNull('client_email')
            ->chunkById(500, function ($bookings) {
                $clientIds = $bookings->pluck('client_id')->filter()->unique()->values();
                $emails = DB::table('clients')
                    ->whereIn('id', $clientIds)
                    ->pluck('email', 'id');

                foreach ($bookings as $booking) {
                    $email = $emails->get($booking->client_id);
                    if ($email) {
                        DB::table('bookings')
                            ->where('id', $booking->id)
                            ->update(['client_email' => mb_strtolower(trim((string) $email))]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'client_email')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('client_email');
            });
        }
    }
};
