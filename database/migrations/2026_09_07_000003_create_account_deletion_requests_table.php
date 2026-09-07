<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('requester');
            $table->string('audience', 20)->index();
            $table->string('email')->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('requested_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('account_deletion_requests'); }
};
