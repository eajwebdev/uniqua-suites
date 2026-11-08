<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The "N days and above, 1 day free" rule moves onto each rate set, so it has a start date like
 * the rates. Changing it never reprices a rental that starts before the change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_rate_tiers', function (Blueprint $t) {
            $t->unsignedSmallInteger('free_day_from')->nullable()->after('daily_cents'); // null or 0: no free day
        });

        $current = DB::table('application_settings')->where('key', 'cameras.free_day_threshold')->value('value');
        $threshold = $current === null ? 8 : (int) json_decode($current, true);
        DB::table('rental_rate_tiers')->update(['free_day_from' => $threshold ?: null]);
        DB::table('application_settings')->where('key', 'cameras.free_day_threshold')->delete();
    }

    public function down(): void
    {
        Schema::table('rental_rate_tiers', fn (Blueprint $t) => $t->dropColumn('free_day_from'));
    }
};
