<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Airbnb sets its own price, so an Airbnb booking stores the total shown on Airbnb.
 * When it is set, the stay is priced from it instead of from the nightly rates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $t) {
            $t->bigInteger('channel_total_cents')->nullable()->after('total_cents');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $t) {
            $t->dropColumn('channel_total_cents');
        });
    }
};
