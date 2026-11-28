<?php

namespace App\Domain\Rooms;

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomBlock;
use Illuminate\Support\Facades\DB;

/**
 * What each room (or camera) is doing right now, and who is next: the Dashboard's board and the
 * cards on Rooms & Properties read the same answer.
 */
class RoomBoard
{
    /**
     * @param  iterable<Room>  $rooms  with property loaded
     * @return array<int, array{state: string, stay: ?array, next: ?array}> keyed by room id
     */
    public function states(iterable $rooms, string $today): array
    {
        $ids = collect($rooms)->pluck('id')->all();
        $stays = DB::table('booking_rooms as br')->join('bookings as b', 'b.id', '=', 'br.booking_id')->join('guests as g', 'g.id', '=', 'b.guest_id')
            ->whereIn('br.room_id', $ids)
            // A camera still out after its return day is still out, so it stays on the board.
            ->whereNull('br.released_at')->where(fn ($q) => $q->where('br.check_out', '>=', $today)
                ->orWhere(fn ($c) => $c->whereNotNull('br.starts_at')->where('b.status', Booking::CHECKED_IN)))
            ->whereIn('b.status', [Booking::CONFIRMED, Booking::CHECKED_IN])
            ->orderBy('br.check_in')
            ->get(['br.room_id', 'b.id', 'b.reference', 'b.status', 'br.check_in', 'br.check_out', 'br.ends_at as return_at', 'g.full_name as guest'])
            ->groupBy('room_id');
        $blocks = RoomBlock::whereIn('room_id', $ids)->whereNull('released_at')->where('start_date', '<=', $today)->where('end_date', '>', $today)->get()->keyBy('room_id');

        $out = [];
        foreach ($rooms as $room) {
            $roomStays = $stays[$room->id] ?? collect();
            $inHouse = $roomStays->firstWhere('status', Booking::CHECKED_IN);
            $next = $roomStays->first(fn ($s) => $s->status === Booking::CONFIRMED && $s->check_in >= $today);

            $out[$room->id] = [
                'state' => match (true) {
                    (bool) $inHouse => 'occupied',
                    $room->operational_status === 'maintenance' => 'maintenance',
                    isset($blocks[$room->id]) => 'blocked',
                    $next?->check_in === $today => 'arriving',
                    default => 'vacant',
                },
                'stay' => $inHouse ? [
                    'booking_id' => $inHouse->id,
                    'reference' => $inHouse->reference,
                    'guest' => $inHouse->guest,
                    'check_out' => ! empty($inHouse->return_at) ? substr((string) $inHouse->return_at, 0, 10) : $inHouse->check_out,
                    'return_at' => ! empty($inHouse->return_at) ? substr((string) $inHouse->return_at, 0, 16) : null,
                ] : null,
                'next' => $next ? ['booking_id' => $next->id, 'reference' => $next->reference, 'guest' => $next->guest, 'check_in' => $next->check_in] : null,
            ];
        }

        return $out;
    }
}
