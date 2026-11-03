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

    /** Read-only check for the public site and search screens. */
    public function isFree(int $roomId, string $start, string $end): bool
    {
        return $this->conflicts($roomId, $start, $end)->isEmpty();
    }
}
