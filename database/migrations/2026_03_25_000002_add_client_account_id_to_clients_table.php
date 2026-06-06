<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('client_account_id')
                ->nullable()
                ->after('business_id')
                ->constrained('client_accounts')
                ->nullOnDelete();

            $table->index(['client_account_id', 'business_id']);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_account_id');
        });
    }
};
