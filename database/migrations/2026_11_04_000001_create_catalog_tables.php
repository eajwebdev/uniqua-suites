<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('location_label');
            $t->text('public_notes')->nullable();
            $t->timestamps();
        });

        Schema::create('room_types', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('slug')->unique();
        });

        Schema::create('rooms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('property_id')->constrained()->restrictOnDelete();
            $t->foreignId('room_type_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('unit_code'); // string: preserves leading zeros such as 0918
            $t->string('floor')->nullable();
            $t->unsignedTinyInteger('bedrooms');
            $t->unsignedTinyInteger('ac_count');
            $t->unsignedTinyInteger('bathrooms');
            $t->boolean('has_balcony')->default(false);
            $t->unsignedTinyInteger('included_guests');
            $t->unsignedTinyInteger('max_guests');
            $t->text('description')->nullable();
            $t->text('inclusions')->nullable();
            $t->text('policies')->nullable();
            $t->text('operational_notes')->nullable();
            $t->string('catalog_status')->default('proposed'); // proposed | approved
            $t->boolean('is_published')->default(false);
            $t->string('operational_status')->default('active'); // active | maintenance | retired
            $t->string('housekeeping_status')->default('ready');
            $t->boolean('parking_eligible')->default(true);
            $t->date('active_from')->nullable();
            $t->date('active_to')->nullable();
            $t->foreignId('catalog_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('catalog_approved_at')->nullable();
            $t->timestamps();
            $t->unique(['property_id', 'unit_code']);
            $t->index(['is_published', 'operational_status']);
        });

        Schema::create('room_images', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->constrained()->cascadeOnDelete();
            $t->string('path');
            $t->string('alt_text');
            $t->unsignedSmallInteger('position')->default(0);
            $t->timestamps();
        });

        Schema::create('amenities', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
        });

        Schema::create('amenity_room', function (Blueprint $t) {
            $t->foreignId('room_id')->constrained()->cascadeOnDelete();
            $t->foreignId('amenity_id')->constrained()->cascadeOnDelete();
            $t->primary(['room_id', 'amenity_id']);
        });

        Schema::create('room_blocks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->constrained()->restrictOnDelete();
            $t->date('start_date');
            $t->date('end_date'); // exclusive
            $t->string('kind'); // repair | owner_use | technical | third_party | external_calendar
            $t->string('reason');
            $t->string('external_uid')->nullable();
            $t->foreignId('external_calendar_feed_id')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('released_at')->nullable();
            $t->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['room_id', 'start_date', 'end_date']);
            $t->unique(['external_calendar_feed_id', 'external_uid']);
        });

        Schema::create('room_status_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->constrained()->cascadeOnDelete();
            $t->string('dimension'); // housekeeping | operational
            $t->string('from_status')->nullable();
            $t->string('to_status');
            $t->string('note')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('rate_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->constrained()->restrictOnDelete();
            $t->date('effective_from');
            $t->date('effective_to')->nullable(); // inclusive
            $t->unsignedBigInteger('weekday_cents');
            $t->unsignedBigInteger('weekend_cents');
            $t->string('source_note')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['room_id', 'effective_from']);
        });

        Schema::create('booking_sources', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->boolean('is_legacy_only')->default(false);
        });

        Schema::create('rate_overrides', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_id')->constrained()->restrictOnDelete();
            $t->foreignId('booking_source_id')->nullable()->constrained()->restrictOnDelete();
            $t->date('date_from');
            $t->date('date_to'); // inclusive
            $t->unsignedBigInteger('nightly_cents');
            $t->string('label');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['room_id', 'date_from', 'date_to']);
        });

        Schema::create('application_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });

        // Visible register of source facts, owner-approval defaults and future functionality.
        Schema::create('owner_decisions', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('title');
            $t->text('detail');
            $t->string('kind'); // source_fact | default_needs_approval | future_optional | data_conflict
            $t->string('status')->default('pending'); // pending | approved | rejected
            $t->text('resolution')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action');
            $t->nullableMorphs('subject');
            $t->json('meta')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['action', 'created_at']);
        });

        Schema::create('integration_health', function (Blueprint $t) {
            $t->id();
            $t->string('provider')->unique();
            $t->string('status')->default('not_connected'); // not_connected | connected | degraded | failed
            $t->text('detail')->nullable();
            $t->timestamp('last_success_at')->nullable();
            $t->timestamp('last_failure_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['integration_health', 'activity_logs', 'owner_decisions', 'application_settings', 'rate_overrides', 'booking_sources', 'rate_rules', 'room_status_events', 'room_blocks', 'amenity_room', 'amenities', 'room_images', 'rooms', 'room_types', 'properties'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
