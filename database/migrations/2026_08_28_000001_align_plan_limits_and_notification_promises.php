<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('plans')) {
            return;
        }

        $limits = [
            'start' => ['staff' => 1, 'services' => 10],
            'studio' => ['staff' => 5, 'services' => 30],
            'scale' => ['staff' => 15, 'services' => 100],
            'custom' => ['staff' => 999, 'services' => 999],
        ];

        foreach ($limits as $code => $limit) {
            $plan = DB::table('plans')->where('code', $code)->first();
            if (!$plan) {
                continue;
            }

            $features = json_decode((string) ($plan->features ?? '{}'), true);
            if (!is_array($features)) {
                $features = [];
            }

            $features['staff_limit'] = $limit['staff'];
            $features['services_limit'] = $limit['services'];
            $features['email_notifications'] = true;
            $features['telegram_notifications'] = true;
            $features['sms_reminders'] = false;
            $features['whatsapp_notifications'] = false;

            DB::table('plans')->where('id', $plan->id)->update([
                'staff_limit' => $limit['staff'],
                'seats' => $limit['staff'],
                'features' => json_encode($features, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // The previous production values may have been customized. Reverting
        // product limits automatically would risk overwriting those choices.
    }
};
