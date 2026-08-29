<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('clients', 'telegram_chat_id')) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->string('telegram_chat_id', 80)->nullable()->after('email')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clients', 'telegram_chat_id')) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->dropColumn('telegram_chat_id');
            });
        }
    }
};
