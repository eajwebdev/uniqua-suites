<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The check-out (or camera return) moment a reminder was last sent for, as local wall-clock time.
 * A stay extended to a new time gets a fresh reminder; the same time never gets two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dateTime('checkout_reminded_for')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('checkout_reminded_for');
        });
    }
};
