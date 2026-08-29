<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('booking_mode', 20)->default('individual')->after('duration_minutes');
            $table->unsignedSmallInteger('capacity')->default(1)->after('booking_mode');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedSmallInteger('party_size')->default(1)->after('group_id');
            $table->uuid('recurrence_id')->nullable()->after('party_size');
            $table->string('recurrence_frequency', 20)->nullable()->after('recurrence_id');
            $table->unsignedSmallInteger('recurrence_index')->default(1)->after('recurrence_frequency');
            $table->unsignedSmallInteger('recurrence_count')->default(1)->after('recurrence_index');
            $table->index(['business_id', 'recurrence_id']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('marketing_opt_in')->default(false)->after('email');
            $table->timestamp('marketing_opted_in_at')->nullable()->after('marketing_opt_in');
            $table->timestamp('marketing_unsubscribed_at')->nullable()->after('marketing_opted_in_at');
        });

        Schema::table('gift_cards', function (Blueprint $table) {
            $table->string('issued_to_email', 150)->nullable()->after('issued_to_phone');
            $table->string('purchased_by_email', 150)->nullable()->after('purchased_by_phone');
            $table->text('delivery_message')->nullable()->after('notes');
            $table->string('delivery_status', 20)->default('not_requested')->after('delivery_message');
            $table->timestamp('delivered_at')->nullable()->after('delivery_status');
        });

        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('business_locations')->nullOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('customer_name', 120);
            $table->string('customer_phone', 40);
            $table->string('customer_email', 150);
            $table->date('desired_date');
            $table->time('window_start')->nullable();
            $table->time('window_end')->nullable();
            $table->unsignedSmallInteger('party_size')->default(1);
            $table->string('status', 20)->default('waiting');
            $table->foreignId('offered_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('offered_starts_at')->nullable();
            $table->timestamp('offered_ends_at')->nullable();
            $table->string('offer_token_hash', 64)->nullable();
            $table->timestamp('offer_expires_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->foreignId('booked_booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('source', 30)->default('website');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status', 'desired_date']);
            $table->index(['service_id', 'staff_id', 'desired_date']);
            $table->index(['business_id', 'offered_staff_id', 'status', 'offer_expires_at'], 'waitlist_active_offers_idx');
        });

        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('channel', 20)->default('email');
            $table->string('segment', 30)->default('all');
            $table->string('subject', 180);
            $table->text('body');
            $table->string('status', 20)->default('draft');
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status', 'scheduled_for']);
        });

        Schema::create('marketing_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('email', 150);
            $table->string('status', 20)->default('pending');
            $table->string('unsubscribe_token_hash', 64)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'email']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_deliveries');
        Schema::dropIfExists('marketing_campaigns');
        Schema::dropIfExists('waitlist_entries');

        Schema::table('gift_cards', function (Blueprint $table) {
            $table->dropColumn(['issued_to_email', 'purchased_by_email', 'delivery_message', 'delivery_status', 'delivered_at']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['marketing_opt_in', 'marketing_opted_in_at', 'marketing_unsubscribed_at']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'recurrence_id']);
            $table->dropColumn(['party_size', 'recurrence_id', 'recurrence_frequency', 'recurrence_index', 'recurrence_count']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['booking_mode', 'capacity']);
        });
    }
};
