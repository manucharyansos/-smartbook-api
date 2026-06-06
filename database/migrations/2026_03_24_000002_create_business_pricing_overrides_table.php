<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_pricing_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('custom_monthly_price')->nullable();
            $table->unsignedInteger('custom_yearly_price')->nullable();
            $table->string('discount_type', 30)->nullable();
            $table->decimal('discount_value', 10, 2)->nullable();
            $table->unsignedInteger('billing_cycles_limit')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'plan_id', 'is_active'], 'biz_plan_override_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_pricing_overrides');
    }
};
