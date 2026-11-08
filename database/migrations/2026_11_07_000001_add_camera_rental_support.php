<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grab Cam camera rental runs on the same booking engine as the suites. A property's category
 * says which business it belongs to; every existing property stays a suite property.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $t) {
            $t->string('category')->default('suites')->after('slug'); // suites | cameras
        });

        Schema::table('room_types', function (Blueprint $t) {
            $t->string('brand')->nullable(); // cameras: Canon, Fujifilm, Ricoh, Apple, DJI
        });

        Schema::table('rooms', function (Blueprint $t) {
            $t->string('specs')->nullable()->after('description'); // cameras: lens, kit, storage
        });

        // Per camera model: every unit of a model shares one price, picked by rental length.
        Schema::create('rental_rate_tiers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('min_days');
            $t->unsignedInteger('max_days')->nullable(); // null: and above
            $t->unsignedBigInteger('daily_cents');
            $t->date('effective_from');
            $t->date('effective_to')->nullable(); // inclusive
            $t->timestamps();
            $t->index(['room_type_id', 'effective_from']);
        });

        // Camera rentals: return time, and the deposit and ID held until the camera comes back.
        // The deposit is held money, not a charge, so it never touches the booking total.
        Schema::table('bookings', function (Blueprint $t) {
            $t->time('return_time')->nullable()->after('requested_arrival_time');
            $t->unsignedBigInteger('deposit_cents')->default(0);
            $t->string('deposit_status')->default('none'); // none | held | returned | deducted
            $t->string('deposit_note')->nullable();
            $t->string('id_type')->nullable();
            $t->string('id_status')->default('none'); // none | held | returned
        });
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn(['return_time', 'deposit_cents', 'deposit_status', 'deposit_note', 'id_type', 'id_status']));
        Schema::dropIfExists('rental_rate_tiers');
        Schema::table('rooms', fn (Blueprint $t) => $t->dropColumn('specs'));
        Schema::table('room_types', fn (Blueprint $t) => $t->dropColumn('brand'));
        Schema::table('properties', fn (Blueprint $t) => $t->dropColumn('category'));
    }
};
