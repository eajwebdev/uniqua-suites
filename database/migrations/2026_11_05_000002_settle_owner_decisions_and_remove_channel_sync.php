<?php

use App\Models\OwnerDecision;
use App\Support\OwnerDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Airbnb bookings are entered by hand, so the calendar feed, listing IDs and integration status go.
 * A table is only dropped when nothing has been saved in it. Fresh installs have no decisions yet:
 * the seeder settles those.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (OwnerDecision::exists()) {
            OwnerDefaults::apply();
        }

        Schema::dropIfExists('integration_health');
        foreach (['external_calendar_feeds', 'room_listings'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->doesntExist()) {
                Schema::drop($table);
            }
        }
    }

    public function down(): void
    {
        // Nothing to restore: these tables held no records when they were dropped.
    }
};
