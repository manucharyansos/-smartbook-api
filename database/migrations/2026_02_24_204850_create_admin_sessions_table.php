<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('admin_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->text('payload');
            $table->string('token')->unique();
            $table->timestamp('last_activity')->nullable();
            $table->timestamps();

            $table->index(['admin_id', 'last_activity']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('admin_sessions');
    }
};
