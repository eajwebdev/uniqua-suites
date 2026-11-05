<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Availability\InventoryService;
use App\Domain\Availability\RoomConflict;
use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Support\Audit;
use App\Support\Local;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The owner closing some or all rooms for an event, such as an anniversary or a Christmas party.
 * Closures are ordinary room blocks, so staff bookings, holds, date changes, quotes and the public
 * calendar all refuse those nights through the same check as any other block.
 */
class ClosureController extends Controller
{
    private const MAX_NIGHTS = 366;

    public function __construct(private InventoryService $inventory)
    {
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:120',
            'first_night' => 'required|date_format:Y-m-d|after_or_equal:'.Local::today(),
            'last_night' => 'required|date_format:Y-m-d|after_or_equal:first_night',
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'integer|exists:rooms,id',
        ], [
            'first_night.after_or_equal' => 'Choose today or a later date.',
            'last_night.after_or_equal' => 'The last night cannot be before the first night.',
            'room_ids.required' => 'Choose at least one room.',
        ]);

        $start = $data['first_night'];
        // Blocks end on the morning after, the same way a stay does.
        $end = CarbonImmutable::parse($data['last_night'])->addDay()->toDateString();
        if (CarbonImmutable::parse($start)->diffInDays($end) > self::MAX_NIGHTS) {
            throw new RoomConflict('A closure can be up to a year long. Please check the dates.');
        }

        $count = $this->inventory->withRoomLocks($data['room_ids'], function ($rooms) use ($data, $start, $end, $request) {
            $this->assertNoGuests($rooms, $start, $end);

            $group = 'closure:'.Str::uuid();
            foreach ($rooms as $room) {
                RoomBlock::create([
                    'room_id' => $room->id,
                    'start_date' => $start,
                    'end_date' => $end,
                    'kind' => RoomBlock::CLOSURE,
                    'reason' => $data['reason'],
                    'external_uid' => $group,
                    'created_by' => $request->user()->id,
                ]);
            }
            Audit::log('closure.created', null, ['group' => $group, 'reason' => $data['reason'], 'from' => $start, 'to' => $end, 'rooms' => $rooms->pluck('name')->values()->all()]);

            return $rooms->count();
        });

        return back()->with('success', "Closed {$count} room(s) for {$data['reason']}. Nobody can book those nights here or on the website. Block the same dates on Airbnb too.");
    }

    public function reopen(Request $request, string $group)
    {
        $released = RoomBlock::where('kind', RoomBlock::CLOSURE)->where('external_uid', "closure:{$group}")->whereNull('released_at')
            ->update(['released_at' => now(), 'released_by' => $request->user()->id]);
        abort_if($released === 0, 404);
        Audit::log('closure.reopened', null, ['group' => "closure:{$group}", 'rooms' => $released]);

        return back()->with('success', 'Those dates are open for booking again.');
    }

    /** Guests are never cancelled by a closure. The owner decides what happens to each stay first. */
    private function assertNoGuests($rooms, string $start, string $end): void
    {
        $stays = $rooms->flatMap(fn (Room $room) => $this->inventory->conflicts($room->id, $start, $end)
            ->where('type', '!=', 'block')
            ->map(fn ($c) => "{$room->name} ({$c['label']})"))->values();
        if ($stays->isEmpty()) {
            return;
        }

        $listed = $stays->take(5)->join(', ').($stays->count() > 5 ? ', and '.($stays->count() - 5).' more' : '');
        throw new RoomConflict("Nothing was closed, because guests are booked on those nights: {$listed}. Move or cancel those stays first, or untick those rooms.");
    }
}
