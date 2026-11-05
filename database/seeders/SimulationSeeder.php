<?php

namespace Database\Seeders;

use App\Domain\Bookings\BookingService;
use App\Domain\Finance\FinanceService;
use App\Domain\Tasks\TurnoverService;
use App\Models\Booking;
use App\Models\BookingSource;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Guest;
use App\Models\HousekeepingTask;
use App\Models\Incident;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ParkingAssignment;
use App\Models\ParkingSpace;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Local;
use App\Support\Permissions;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A busy two months of entirely synthetic activity, for testing before go-live. It runs only on a
 * database whose name ends in _sim: see scripts/simulation.ps1. Fixed random seed, so a failure can
 * be reproduced.
 */
class SimulationSeeder extends Seeder
{
    public const PASSWORD = 'simulation-2026';

    public const ACCOUNTS = [
        'owner@sim.test' => ['Sim Owner', 'Owner', null],
        'admin@sim.test' => ['Sim Administrator', 'Administrator', null],
        'manager@sim.test' => ['Sim Manager', 'Operations Manager', null],
        'reception@sim.test' => ['Sim Reception', 'Booking / Reception', null],
        'cleaner1@sim.test' => ['Sim Cleaner One', 'Staff', ['housekeeping_own']],
        'cleaner2@sim.test' => ['Sim Cleaner Two', 'Staff', ['housekeeping_own']],
    ];

    private const SOURCES = ['airbnb', 'facebook_messenger', 'instagram', 'walk_in', 'phone'];

    private BookingService $bookings;

    private FinanceService $finance;

    private TurnoverService $turnover;

    private int $guestNumber = 0;

    public function run(): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! str_ends_with($database, '_sim')) {
            throw new RuntimeException("Refusing to seed simulation data into [{$database}]: its name must end in _sim.");
        }

        mt_srand(2026);
        $this->call(DatabaseSeeder::class);
        Room::query()->update(['is_published' => true, 'catalog_status' => 'approved', 'housekeeping_status' => 'ready']);
        Settings::set('pricing.standard_check_out', '11:00');

        foreach (self::ACCOUNTS as $email => [$name, $role, $pages]) {
            $user = User::updateOrCreate(['email' => $email], ['name' => $name, 'password' => self::PASSWORD]);
            Permissions::apply($user, $role, $pages);
        }
        auth()->login(User::where('email', 'owner@sim.test')->firstOrFail());

        $this->bookings = app(BookingService::class);
        $this->finance = app(FinanceService::class);
        $this->turnover = app(TurnoverService::class);

        foreach (Room::orderBy('id')->get() as $room) {
            $this->fillRoom($room);
        }
        $this->groupBooking();
        $this->todaysCleaning();
        $this->closuresAndBlocks();
        $this->operations();

        auth()->logout();
        $this->command?->info('Simulation data: '.Booking::count().' bookings, '.HousekeepingTask::count().' tasks.');
    }

    /** Stays back to back and with gaps, from 25 days ago to 45 days ahead. */
    private function fillRoom(Room $room): void
    {
        $day = -25;
        while ($day < 45) {
            $day += mt_rand(0, 3) === 0 ? mt_rand(1, 2) : 0;
            $nights = mt_rand(1, 4);
            $this->stay($room, $day, $day + $nights);
            $day += $nights;
        }
    }

    private function stay(Room $room, int $in, int $out): void
    {
        $booking = $this->inquiry([$room->id], $in, $out);
        $roll = mt_rand(1, 100);

        if ($out < 0) {
            $this->pastStay($booking, $room, $out);

            return;
        }
        if ($in <= 0) {
            $booking = $this->bookings->confirm($booking);
            // Arriving today into a room whose last guest leaves today: they wait for that guest to go.
            if ($in < 0 || ! $this->roomOccupied($room)) {
                $this->bookings->checkIn($booking, true, 'Simulation start');
            }
            $this->pay($booking->fresh());

            return;
        }

        match (true) {
            $roll <= 62 => $this->pay($this->bookings->confirm($booking)),
            $roll <= 74 => $this->bookings->hold($booking),
            $roll <= 90 => null, // stays an inquiry
            default => $this->bookings->cancel($booking, 'Guest changed plans'),
        };
    }

    private function roomOccupied(Room $room): bool
    {
        return Booking::where('status', Booking::CHECKED_IN)->whereHas('activeRooms', fn ($stay) => $stay->where('room_id', $room->id))->exists();
    }

    private function inquiry(array $roomIds, int $in, int $out): Booking
    {
        $this->guestNumber++;
        $name = sprintf('SIM Guest %03d', $this->guestNumber);

        return $this->bookings->createInquiry([
            'guest_id' => Guest::create(['full_name' => $name, 'phone' => '0900-000-0000', 'email' => Str::slug($name).'@example.test'])->id,
            'room_ids' => $roomIds,
            'booking_source_id' => BookingSource::where('code', self::SOURCES[array_rand(self::SOURCES)])->value('id'),
            'check_in' => $this->date($in),
            'check_out' => $this->date($out),
            'adults' => 2,
        ]);
    }

    /** A finished stay, with the cleaning passed and the dates moved back to when it really happened. */
    private function pastStay(Booking $booking, Room $room, int $out): void
    {
        $booking = $this->bookings->confirm($booking);
        $this->pay($booking);
        $this->bookings->checkIn($booking, true, 'Simulation history');
        $this->bookings->checkOut($booking->fresh());

        $task = HousekeepingTask::where('booking_id', $booking->id)->where('room_id', $room->id)->firstOrFail();
        $this->turnover->start($task);
        $this->turnover->complete($task->fresh(), $task->checklist, null);
        $this->turnover->inspect($task->fresh(), true, null);

        $when = CarbonImmutable::parse($this->date($out).' 13:00', Local::tz())->utc();
        DB::table('housekeeping_tasks')->where('id', $task->id)->update([
            'created_at' => $when, 'due_at' => $when->addHours(4), 'started_at' => $when->addHour(), 'completed_at' => $when->addHours(2), 'inspected_at' => $when->addHours(3),
        ]);
        DB::table('bookings')->where('id', $booking->id)->update(['actual_check_in_at' => $when->subDay(), 'actual_check_out_at' => $when]);
    }

    private function pay(Booking $booking): void
    {
        if (mt_rand(1, 100) > 65) {
            return;
        }
        $total = (int) $booking->fresh()->total_cents;
        $receipt = $this->finance->recordReceipt([
            'booking_id' => $booking->id,
            'received_at' => Local::now()->toDateTimeString(),
            'amount_cents' => max(100, (int) round($total / 2)),
            'method' => ['gcash', 'cash', 'bank_transfer'][mt_rand(0, 2)],
            'external_reference' => 'SIM-'.Str::upper(Str::random(8)),
        ]);
        if (mt_rand(1, 100) <= 70) {
            $this->finance->review($receipt, true);
        }
    }

    private function groupBooking(): void
    {
        $rooms = Room::whereIn('slug', ['paris', 'new-york'])->pluck('id')->all();
        $this->pay($this->bookings->confirm($this->inquiry($rooms, 50, 53)));
    }

    /** One of every state, so the Cleaning screen has something to show today. */
    private function todaysCleaning(): void
    {
        [$one, $two] = User::whereIn('email', ['cleaner1@sim.test', 'cleaner2@sim.test'])->orderBy('email')->get()->all();

        $rooms = Room::orderBy('id')->get();
        $due = fn (string $time) => CarbonImmutable::parse(Local::today().' '.$time, Local::tz())->utc();

        $this->turnover->createTask(['type' => 'deep_clean', 'title' => 'Deep clean the kitchen area', 'room_id' => $rooms[0]->id, 'due_at' => $due('15:00'), 'priority' => 'high', 'instructions' => 'Include inside the microwave.'], $one);
        $this->turnover->createTask(['type' => 'restock', 'title' => 'Restock toiletries', 'room_id' => $rooms[1]->id, 'due_at' => $due('11:00'), 'requires_inspection' => false], $two);
        $this->turnover->createTask(['type' => 'other', 'title' => 'Replace the bathroom curtain', 'room_id' => $rooms[2]->id, 'due_at' => $due('17:00')], null);

        $started = $this->turnover->createTask(['type' => 'cleaning', 'title' => 'Clean the balcony', 'room_id' => $rooms[3]->id, 'due_at' => $due('16:00')], $two);
        $this->turnover->start($started);
    }

    private function closuresAndBlocks(): void
    {
        $group = 'closure:'.Str::uuid();
        foreach (Room::all() as $room) {
            RoomBlock::create(['room_id' => $room->id, 'start_date' => $this->date(60), 'end_date' => $this->date(62), 'kind' => RoomBlock::CLOSURE, 'reason' => 'SIM Christmas party', 'external_uid' => $group, 'created_by' => auth()->id()]);
        }
        RoomBlock::create(['room_id' => Room::where('slug', 'rio')->value('id'), 'start_date' => $this->date(70), 'end_date' => $this->date(73), 'kind' => 'repair', 'reason' => 'SIM aircon repair', 'created_by' => auth()->id()]);
    }

    private function operations(): void
    {
        $room = Room::where('slug', 'bali')->firstOrFail();
        $user = auth()->id();

        WorkOrder::create(['room_id' => $room->id, 'title' => 'SIM dripping tap', 'severity' => 'normal', 'status' => 'open']);
        WorkOrder::create(['room_id' => Room::where('slug', 'milan')->value('id'), 'title' => 'SIM loose door handle', 'severity' => 'high', 'status' => 'in_progress']);
        Incident::create(['room_id' => $room->id, 'item' => 'SIM cracked glass', 'discovered_on' => $this->date(-3), 'estimated_cents' => 50000, 'status' => 'reported']);

        foreach (ExpenseCategory::orderBy('id')->limit(8)->get() as $i => $category) {
            Expense::create(['expense_category_id' => $category->id, 'property_id' => $room->property_id, 'room_id' => $i % 2 ? $room->id : null, 'description' => "SIM expense {$category->name}", 'amount_cents' => (100 + 75 * $i) * 100, 'expense_date' => $this->date(-2 * $i), 'status' => $i % 3 ? 'paid' : 'incurred', 'paid_on' => $i % 3 ? $this->date(-2 * $i) : null, 'created_by' => $user]);
        }

        foreach ([['SIM-TOWEL', 'Bath towel', 'pc', 10, 40], ['SIM-SOAP', 'Soap', 'pc', 50, 20], ['SIM-LINEN', 'Bed sheet set', 'set', 8, 30]] as [$sku, $name, $unit, $reorder, $qty]) {
            $item = InventoryItem::create(['sku' => $sku, 'name' => $name, 'unit' => $unit, 'reorder_level' => $reorder]);
            InventoryMovement::create(['inventory_item_id' => $item->id, 'property_id' => Property::orderBy('id')->value('id'), 'type' => 'receive', 'quantity' => $qty, 'note' => 'SIM opening stock', 'user_id' => $user]);
        }

        $space = ParkingSpace::orderBy('id')->first();
        if ($space) {
            ParkingAssignment::create(['parking_space_id' => $space->id, 'holder_name' => 'SIM Guest Parking', 'vehicle' => 'SIM-0000', 'start_date' => $this->date(0), 'end_date' => $this->date(2), 'status' => 'active']);
        }
    }

    private function date(int $offset): string
    {
        return Local::now()->addDays($offset)->toDateString();
    }
}
