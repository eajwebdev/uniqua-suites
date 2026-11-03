<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $t) {
            $t->id();
            $t->string('full_name');
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->string('messenger_handle')->nullable();
            $t->string('company')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index('full_name');
        });

        Schema::create('bookings', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();
            $t->string('idempotency_key')->nullable()->unique();
            $t->foreignId('guest_id')->constrained()->restrictOnDelete();
            $t->foreignId('property_id')->constrained()->restrictOnDelete();
            $t->foreignId('booking_source_id')->constrained()->restrictOnDelete();
            $t->string('source_reference')->nullable();
            $t->string('referrer')->nullable();
            $t->string('import_source')->default('manual'); // manual | legacy_spreadsheet | ical | api
            $t->string('status')->default('inquiry');
            $t->date('check_in');
            $t->date('check_out'); // exclusive night boundary
            $t->time('requested_arrival_time')->nullable();
            $t->time('approved_early_time')->nullable();
            $t->timestamp('actual_check_in_at')->nullable();
            $t->timestamp('actual_check_out_at')->nullable();
            $t->unsignedTinyInteger('adults')->default(1);
            $t->unsignedTinyInteger('children')->default(0);
            $t->unsignedTinyInteger('guest_count')->default(1);
            $t->text('special_requests')->nullable();
            $t->text('internal_notes')->nullable();
            $t->timestamp('hold_expires_at')->nullable(); // UTC
            $t->unsignedInteger('pricing_version')->default(0);
            $t->bigInteger('total_cents')->default(0);
            $t->string('cancellation_reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'check_in', 'check_out']);
            $t->index(['status', 'hold_expires_at']);
            $t->index(['booking_source_id', 'created_at']);
            $t->index(['guest_id', 'check_in']);
        });

        Schema::create('booking_rooms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained()->restrictOnDelete();
            $t->foreignId('room_id')->constrained()->restrictOnDelete();
            $t->date('check_in');
            $t->date('check_out');
            $t->unsignedTinyInteger('guest_count');
            $t->timestamp('released_at')->nullable();
            $t->timestamps();
            $t->index(['room_id', 'check_in', 'check_out']);
            $t->index(['booking_id', 'released_at']);
        });

        Schema::create('booking_nights', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained()->restrictOnDelete();
            $t->foreignId('room_id')->constrained()->restrictOnDelete();
            $t->date('stay_date');
            $t->bigInteger('rate_cents');
            $t->string('rate_source');
            $t->unsignedInteger('pricing_version');
            $t->boolean('is_current')->default(true);
            $t->timestamps();
            $t->index(['stay_date', 'room_id', 'is_current']);
            $t->index(['booking_id', 'is_current']);
        });

        Schema::create('booking_status_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $t->string('from_status')->nullable();
            $t->string('to_status');
            $t->string('note')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('booking_charges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained()->restrictOnDelete();
            $t->string('type'); // accommodation | extra_guest | early_check_in | late_check_out | parking | service | damage | discount | adjustment
            $t->string('description');
            $t->decimal('quantity', 8, 2)->default(1);
            $t->bigInteger('unit_cents');
            $t->bigInteger('amount_cents'); // negative for discounts
            $t->date('charge_date')->nullable();
            $t->unsignedInteger('pricing_version')->default(0);
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason')->nullable();
            $t->timestamps();
            $t->index(['booking_id', 'voided_at']);
            $t->index(['type', 'charge_date']);
        });

        Schema::create('pricing_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->json('snapshot');
            $t->bigInteger('total_cents');
            $t->string('reason');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['booking_id', 'version']);
        });

        Schema::create('quotes', function (Blueprint $t) {
            $t->id();
            $t->string('reference');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('room_id')->constrained()->restrictOnDelete();
            $t->foreignId('booking_source_id')->nullable()->constrained()->nullOnDelete();
            $t->string('guest_name')->nullable();
            $t->date('check_in');
            $t->date('check_out');
            $t->unsignedTinyInteger('guest_count');
            $t->time('early_time')->nullable();
            $t->bigInteger('total_cents');
            $t->json('warnings')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['reference', 'version']);
        });

        Schema::create('quote_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $t->string('type');
            $t->string('description');
            $t->decimal('quantity', 8, 2)->default(1);
            $t->bigInteger('unit_cents');
            $t->bigInteger('amount_cents');
        });

        Schema::create('message_templates', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('name');
            $t->text('body');
            $t->timestamps();
        });

        Schema::create('external_calendar_feeds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->constrained()->restrictOnDelete();
            $t->string('provider')->default('airbnb');
            $t->text('url'); // encrypted cast: private tokenised URL
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_synced_at')->nullable();
            $t->string('last_status')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
        });

        Schema::create('external_reservation_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $t->string('provider');
            $t->string('external_id');
            $t->timestamps();
            $t->unique(['provider', 'external_id']);
        });
    }

    public function down(): void
    {
        foreach (['external_reservation_links', 'external_calendar_feeds', 'message_templates', 'quote_items', 'quotes', 'pricing_snapshots', 'booking_charges', 'booking_status_events', 'booking_nights', 'booking_rooms', 'bookings', 'guests'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
