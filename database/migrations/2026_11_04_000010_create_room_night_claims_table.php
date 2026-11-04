<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per room per night held by a live hold or stay. The unique key means the database
 * itself refuses a double booking, even if a future code path were to skip the room locks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_night_claims', function (Blueprint $t) {
            $t->foreignId('room_id')->constrained()->restrictOnDelete();
            $t->date('stay_date');
            $t->foreignId('booking_id')->constrained()->restrictOnDelete();
            $t->primary(['room_id', 'stay_date']);
            $t->index('booking_id');
        });

        $live = DB::table('booking_rooms as br')->join('bookings as b', 'b.id', '=', 'br.booking_id')
            ->whereNull('br.released_at')
            ->where(fn ($q) => $q->whereIn('b.status', ['confirmed', 'checked_in'])
                ->orWhere(fn ($h) => $h->where('b.status', 'tentative')->where('b.hold_expires_at', '>', now())))
            ->get(['br.room_id', 'br.booking_id', 'br.check_in', 'br.check_out']);

        foreach ($live as $stay) {
            for ($night = $stay->check_in; $night < $stay->check_out; $night = date('Y-m-d', strtotime($night.' +1 day'))) {
                // An overlap already in old data stays visible through the dashboard's double-booking alert.
                DB::table('room_night_claims')->insertOrIgnore(['room_id' => $stay->room_id, 'stay_date' => $night, 'booking_id' => $stay->booking_id]);
            }
        }

        DB::table('owner_decisions')->where('key', 'public_no_prices')->update([
            'title' => 'The public website shows taken dates but no prices',
            'detail' => 'Owner decisions, 4 November 2026. Guests browse suites and see which dates are taken, by date only. Book Now opens Facebook Messenger with free dates they chose. Only staff see prices and send the quotation, and a booking exists only when staff enter it.',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('room_night_claims');
    }
};
