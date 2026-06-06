<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->string('key')->index();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('scope', 40)->default('global'); // global | business | plan
            $table->foreignId('business_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->json('config')->nullable();
            $table->timestamps();

            $table->unique(['key', 'scope', 'business_id'], 'feature_flags_unique_scope');
            $table->index(['scope', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
