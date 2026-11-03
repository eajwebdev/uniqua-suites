<?php

use App\Domain\Bookings\BookingService;
use App\Domain\Integrations\IcalSync;
use App\Models\Booking;
use App\Models\HousekeepingTask;
use App\Models\InventoryItem;
use App\Models\Property;
use App\Models\Room;
use App\Support\Local;
use App\Support\SystemNotice;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('uniqa:expire-holds', function (BookingService $bookings) {
    $count = $bookings->expireHolds();
    if ($count) {
        SystemNotice::toPermission('bookings.manage', "{$count} hold(s) expired and released their dates.", '/admin/bookings?status=expired_hold');
    }
    $this->info("Expired {$count} hold(s).");
})->purpose('Release lapsed booking holds');

Artisan::command('uniqa:daily-alerts', function () {
    $tomorrow = Local::now()->addDay()->toDateString();
    $arrivals = Booking::where('status', Booking::CONFIRMED)->where('check_in', $tomorrow)->count();
    if ($arrivals) {
        SystemNotice::toPermission('bookings.manage', "{$arrivals} arrival(s) tomorrow. Check balances and special requests.", '/admin');
    }

    $overdue = HousekeepingTask::whereIn('status', ['pending', 'assigned', 'in_progress'])->where('due_at', '<', now())->count();
    if ($overdue) {
        SystemNotice::toPermission('housekeeping.manage', "{$overdue} housekeeping task(s) are overdue.", '/admin/housekeeping');
    }

    // Raises a task per low item and property; never buys anything.
    $stock = DB::table('inventory_movements')->selectRaw('inventory_item_id, property_id, SUM(quantity) as qty')->groupBy('inventory_item_id', 'property_id')->get();
    foreach ($stock as $row) {
        $item = InventoryItem::find($row->inventory_item_id);
        if ($item && $item->reorder_level > 0 && $row->qty <= $item->reorder_level) {
            $room = Room::where('property_id', $row->property_id)->orderBy('id')->first();
            if ($room) {
                HousekeepingTask::firstOrCreate(
                    ['idempotency_key' => 'restock:'.$item->id.':'.$row->property_id.':'.Local::now()->format('Y-W')],
                    ['type' => 'restock', 'room_id' => $room->id, 'instructions' => "Restock {$item->name} at ".Property::find($row->property_id)?->name." (on hand: {$row->qty})."],
                );
            }
        }
    }
    $this->info('Daily alerts done.');
})->purpose('Prepare arrival, overdue-task and restock alerts');

Artisan::command('uniqa:sync-ical', function (IcalSync $sync) {
    $this->info($sync->syncAll().' feed(s) processed.');
})->purpose('Read blocked dates from configured external calendars');

if (app()->environment('testing')) {
    require __DIR__.'/test-console.php';
}

Schedule::command('uniqa:expire-holds')->everyMinute()->withoutOverlapping();
Schedule::command('uniqa:daily-alerts')->dailyAt('07:00')->timezone(config('uniqa.display_timezone'));
Schedule::command('uniqa:sync-ical')->hourly()->withoutOverlapping();
