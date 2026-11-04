<?php

namespace App\Domain\Availability;

use App\Models\Booking;
use App\Models\Room;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * The single inventory gate. Every path that writes room inventory (hold, confirm,
 * edit, move, block, external import) runs through withRoomLocks() and assertFree().
 */
class InventoryService
{
    private const MAX_ATTEMPTS = 3;

    /**
     * Lock the physical room rows in ascending id order, then run $work in one transaction.
     * Locks the rooms rather than bookings so it still serializes when no booking exists yet.
     * Deadlocks and lock timeouts are retried; any other failure rolls everything back.
     */
    public function withRoomLocks(array $roomIds, callable $work): mixed
    {
        $roomIds = collect($roomIds)->unique()->sort()->values()->all();
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                return DB::transaction(function () use ($roomIds, $work) {
                    $rooms = Room::whereIn('id', $roomIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                    return $work($rooms);
                });
            } catch (QueryException $e) {
                // 1213 deadlock, 1205 lock wait timeout
                if ($attempt < self::MAX_ATTEMPTS && in_array($e->errorInfo[1] ?? null, [1213, 1205], true)) {
                    usleep(100_000 * $attempt);
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * Conflicts for one room over the half-open range [start, end).
     * Must be called inside withRoomLocks() when the result will drive a write.
     */
    public function conflicts(int $roomId, string $start, string $end, ?int $ignoreBookingId = null): Collection
    {
        $bookings = DB::table('booking_rooms as br')
            ->join('bookings as b', 'b.id', '=', 'br.booking_id')
            ->where('br.room_id', $roomId)
            ->whereNull('br.released_at')
            ->where('br.check_in', '<', $end)
            ->where('br.check_out', '>', $start)
            ->when($ignoreBookingId, fn ($q) => $q->where('b.id', '!=', $ignoreBookingId))
            ->where(function ($q) {
                $q->whereIn('b.status', [Booking::CONFIRMED, Booking::CHECKED_IN])
                    ->orWhere(fn ($h) => $h->where('b.status', Booking::TENTATIVE)
                        ->whereNotNull('b.hold_expires_at')
                        ->where('b.hold_expires_at', '>', now()));
            })
            ->get(['b.reference', 'b.status', 'br.check_in', 'br.check_out'])
            ->map(fn ($r) => [
                'type' => $r->status === Booking::TENTATIVE ? 'hold' : 'booking',
                'label' => "{$r->reference} ({$r->status})",
                'start' => $r->check_in,
                'end' => $r->check_out,
            ]);

        $blocks = DB::table('room_blocks')
            ->where('room_id', $roomId)
            ->whereNull('released_at')
            ->where('start_date', '<', $end)
            ->where('end_date', '>', $start)
            ->get(['kind', 'start_date', 'end_date'])
            ->map(fn ($r) => [
                'type' => 'block',
                'label' => 'Room block: '.$r->kind,
                'start' => $r->start_date,
                'end' => $r->end_date,
            ]);

        return $bookings->concat($blocks)->values();
    }

    /** Throws RoomConflict when the room cannot take this range. */
    public function assertFree(Room $room, string $start, string $end, ?int $ignoreBookingId = null): void
    {
        if ($start >= $end) {
            throw new RoomConflict('Check-out must be after check-in.');
        }

        if ($room->operational_status === 'retired') {
            throw new RoomConflict("{$room->name} is retired and cannot be assigned.");
        }

        if ($room->hasOpenSafetyBlock()) {
            throw new RoomConflict("{$room->name} has an unresolved safety-critical work order and cannot be assigned.");
        }

        $conflicts = $this->conflicts($room->id, $start, $end, $ignoreBookingId);

        if ($conflicts->isNotEmpty()) {
            $first = $conflicts->first();
            throw new RoomConflict("{$room->name} is not available for {$start} to {$end}: {$first['label']} occupies {$first['start']} to {$first['end']}.");
        }
    }

    /**
     * Writes the booking's nights to room_night_claims, whose unique key is the last line of
     * defence: if anything ever got past assertFree(), the database refuses it here and the
     * whole transaction rolls back. Call inside withRoomLocks() after the booking has changed.
     */
    public function syncClaims(Booking $booking): void
    {
        DB::table('room_night_claims')->where('booking_id', $booking->id)->delete();

        $holds = in_array($booking->status, [Booking::CONFIRMED, Booking::CHECKED_IN], true)
            || ($booking->status === Booking::TENTATIVE && $booking->hold_expires_at?->isFuture());
        if (! $holds) {
            return;
        }

        $rows = [];
        foreach ($booking->activeRooms()->get() as $stay) {
            $this->releaseLapsedClaims($stay->room_id, $stay->check_in->toDateString(), $stay->check_out->toDateString());
            for ($night = $stay->check_in->toImmutable(); $night->lt($stay->check_out); $night = $night->addDay()) {
                $rows[] = ['room_id' => $stay->room_id, 'stay_date' => $night->toDateString(), 'booking_id' => $booking->id];
            }
        }

        try {
            DB::table('room_night_claims')->insert($rows);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new RoomConflict('That room is already taken for one of those nights. Nothing was changed.');
            }
            throw $e;
        }
    }

    /** A hold that ran out still has its claims until the scheduler sweeps it; it must not block a new booking. */
    private function releaseLapsedClaims(int $roomId, string $start, string $end): void
    {
        DB::table('room_night_claims as c')->join('bookings as b', 'b.id', '=', 'c.booking_id')
            ->where('c.room_id', $roomId)->where('c.stay_date', '>=', $start)->where('c.stay_date', '<', $end)
            ->where(fn ($q) => $q->whereNotIn('b.status', [Booking::TENTATIVE, Booking::CONFIRMED, Booking::CHECKED_IN])
                ->orWhere(fn ($h) => $h->where('b.status', Booking::TENTATIVE)
                    ->where(fn ($e) => $e->whereNull('b.hold_expires_at')->orWhere('b.hold_expires_at', '<=', now()))))
            ->delete();
    }

    /**
     * Stays and blocks that overlap, from today on. Should always be empty for bookings; an
     * external calendar can still clash, because Airbnb does not ask this system first.
     */
    public function overlaps(string $today): Collection
    {
        $live = fn ($q, string $b) => $q->where(fn ($w) => $w->whereIn("{$b}.status", [Booking::CONFIRMED, Booking::CHECKED_IN])
            ->orWhere(fn ($h) => $h->where("{$b}.status", Booking::TENTATIVE)->where("{$b}.hold_expires_at", '>', now())));

        $bookings = DB::table('booking_rooms as x')
            ->join('booking_rooms as y', fn ($j) => $j->on('y.room_id', '=', 'x.room_id')->on('y.booking_id', '>', 'x.booking_id'))
            ->join('bookings as bx', 'bx.id', '=', 'x.booking_id')->join('bookings as by', 'by.id', '=', 'y.booking_id')
            ->whereNull('x.released_at')->whereNull('y.released_at')
            ->whereColumn('x.check_in', '<', 'y.check_out')->whereColumn('x.check_out', '>', 'y.check_in')
            ->where('x.check_out', '>', $today)->where('y.check_out', '>', $today)
            ->tap(fn ($q) => $live($q, 'bx'))->tap(fn ($q) => $live($q, 'by'))
            ->get(['x.room_id', 'bx.reference as first', 'by.reference as second']);

        $blocks = DB::table('booking_rooms as x')
            ->join('room_blocks as k', 'k.room_id', '=', 'x.room_id')->join('bookings as bx', 'bx.id', '=', 'x.booking_id')
            ->whereNull('x.released_at')->whereNull('k.released_at')
            ->whereColumn('x.check_in', '<', 'k.end_date')->whereColumn('x.check_out', '>', 'k.start_date')
            ->where('x.check_out', '>', $today)->where('k.end_date', '>', $today)
            ->tap(fn ($q) => $live($q, 'bx'))
            ->get(['x.room_id', 'bx.reference as first', 'k.reason as second']);

        return $bookings->concat($blocks)->values();
    }

    /**
     * The nights a room is taken, merged into ranges: dates only, safe for the public site.
     * Uses the same rule as assertFree(), so what guests see is what staff are held to.
     * 'to' is the morning the room frees up, which a new guest can arrive on.
     */
    public function takenRanges(int $roomId, string $from, string $to): array
    {
        $ranges = [];
        foreach ($this->conflicts($roomId, $from, $to)->sortBy('start') as $c) {
            $start = max($c['start'], $from);
            $last = count($ranges) - 1;
            if ($last >= 0 && $start <= $ranges[$last]['to']) {
                $ranges[$last]['to'] = max($ranges[$last]['to'], $c['end']);
            } else {
                $ranges[] = ['from' => $start, 'to' => $c['end']];
            }
        }

        return $ranges;
    }

    /** Read-only check for the public site and search screens. */
    public function isFree(int $roomId, string $start, string $end): bool
    {
        return $this->conflicts($roomId, $start, $end)->isEmpty();
    }
}
