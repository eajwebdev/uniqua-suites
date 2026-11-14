<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Camera rentals run on exact pickup and return times, not whole nights: a camera returned at 10 AM
 * can go out again at 10 AM. Suites leave these columns empty and keep their night-based rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $t) {
            $t->dateTime('pickup_at')->nullable()->after('check_out');
            $t->dateTime('return_at')->nullable()->after('pickup_at');
        });

        Schema::table('booking_rooms', function (Blueprint $t) {
            $t->dateTime('starts_at')->nullable()->after('check_out');
            $t->dateTime('ends_at')->nullable()->after('starts_at');
            $t->index(['room_id', 'starts_at', 'ends_at']);
        });

        // Existing camera rentals: the times staff typed, or midnight when none was typed (the old whole-day rule).
        $bookings = DB::table('bookings as b')->join('properties as p', 'p.id', '=', 'b.property_id')
            ->where('p.category', 'cameras')
            ->get(['b.id', 'b.check_in', 'b.check_out', 'b.requested_arrival_time', 'b.return_time']);
        foreach ($bookings as $b) {
            $start = substr((string) $b->check_in, 0, 10).' '.($b->requested_arrival_time ? substr($b->requested_arrival_time, 0, 5) : '00:00').':00';
            $end = substr((string) $b->check_out, 0, 10).' '.($b->return_time ? substr($b->return_time, 0, 5) : '00:00').':00';
            if ($end <= $start) {
                $end = substr((string) $b->check_out, 0, 10).' 00:00:00';
            }
            DB::table('bookings')->where('id', $b->id)->update(['pickup_at' => $start, 'return_at' => $end]);
            DB::table('booking_rooms')->where('booking_id', $b->id)->update(['starts_at' => $start, 'ends_at' => $end]);
            // Camera rentals are guarded by exact times, so their whole-night claims would only block same-day handovers.
            DB::table('room_night_claims')->where('booking_id', $b->id)->delete();
        }
    }

    public function down(): void
    {
        Schema::table('booking_rooms', function (Blueprint $t) {
            $t->dropIndex(['room_id', 'starts_at', 'ends_at']);
            $t->dropColumn(['starts_at', 'ends_at']);
        });
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn(['pickup_at', 'return_at']));
    }
};
