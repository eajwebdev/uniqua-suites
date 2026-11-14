<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminCategory;
use App\Domain\Availability\InventoryService;
use App\Domain\Finance\FinanceService;
use App\Domain\LegacyImport\WorkbookImporter;
use App\Domain\Reporting\DashboardDetails;
use App\Domain\Reporting\ReportService;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ExternalSettlement;
use App\Models\HousekeepingTask;
use App\Models\Incident;
use App\Models\InventoryItem;
use App\Models\LaundryJob;
use App\Models\ParkingAssignment;
use App\Models\Property;
use App\Models\Receipt;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\WorkOrder;
use App\Support\Local;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(private ReportService $reports, private FinanceService $finance, private InventoryService $inventory, private DashboardDetails $details)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        if (! $user->can('dashboard.view')) {
            return redirect(match (true) {
                $user->can('housekeeping.own') => '/admin/housekeeping',
                $user->can('maintenance.own') => '/admin/work-orders',
                $user->can('advances.manage') => '/admin/advances',
                default => '/admin/account',
            });
        }

        $today = Local::today();
        $period = $this->period($request->validate(['period' => 'nullable|in:month,last_month,90d'])['period'] ?? 'month');
        $money = null;
        $performance = null;
        $income = null;
        $expenses = null;

        if ($user->can('dashboard.finance')) {
            $current = $this->reports->kpis($period['from'], $period['to']);
            $prior = $this->reports->kpis($period['prior_from'], $period['prior_to']);
            $outstanding = $this->reports->outstanding();
            $money = [
                'collected_today_cents' => $this->reports->kpis($today, $today)['collected_cents'],
                'gross_cents' => $current['gross_operating_cents'],
                'collected_cents' => $current['collected_cents'],
                'collected_prior_cents' => $prior['collected_cents'],
                'outstanding_cents' => $outstanding['total_cents'],
                'expected_payout_cents' => (int) AdminCategory::whereHasProperty(ExternalSettlement::query(), 'booking')->whereNull('actual_payout_cents')->sum('expected_net_cents'),
                'earned_cents' => $current['earned_cents'],
                'earned_prior_cents' => $prior['earned_cents'],
                'expenses_cents' => $current['expenses_cents'],
                'expenses_prior_cents' => $prior['expenses_cents'],
                'contribution_cents' => $current['contribution_cents'],
                'contribution_prior_cents' => $prior['contribution_cents'],
                'definitions' => ReportService::DEFINITIONS,
            ];
            $performance = [
                'occupancy_pct' => $current['occupancy_pct'],
                'occupancy_prior_pct' => $prior['occupancy_pct'],
                'occupied_nights' => $current['occupied_room_nights'],
                'available_nights' => $current['available_room_nights'],
                'adr_cents' => $current['adr_cents'],
                'adr_prior_cents' => $prior['adr_cents'],
                'revpar_cents' => $current['revpar_cents'],
                'revpar_prior_cents' => $prior['revpar_cents'],
                'rooms' => $this->roomPerformance($period['from'], $period['to']),
                'channels' => $this->reports->channelMix($period['from'], $period['to']),
                'trend' => $this->reports->monthlyTrend(12),
            ];
            $income = $this->details->income($current, $outstanding, $user->can('payments.view'));
            $expenses = $this->details->expenses($current, $prior, $user->can('expenses.view'));
        }

        return Inertia::render('Admin/Dashboard', [
            'actions' => $this->actions($today, $user),
            'today' => $this->today($today),
            'board' => $this->roomBoard($today),
            'forecast' => $this->reports->roomsBookedByDay($today, Local::now()->addDays(13)->toDateString()),
            'period' => $period,
            'money' => $money,
            'performance' => $performance,
            'bookingsTab' => $user->can('bookings.view') ? $this->details->bookings($period['from'], $period['to'], $today, $user->can('dashboard.finance')) : null,
            'income' => $income,
            'expenses' => $expenses,
            'operations' => $this->details->operations($user),
            'parking' => ! AdminCategory::isCameras() && $user->can('parking.view') ? app(\App\Domain\Parking\ParkingService::class)->dashboard($today, $user->can('dashboard.finance')) : null,
            'team' => [
                'open_tasks' => AdminCategory::isCameras() ? 0 : HousekeepingTask::whereIn('status', ['pending', 'assigned', 'in_progress'])->count(),
                'late_tasks' => AdminCategory::isCameras() ? 0 : HousekeepingTask::whereIn('status', ['pending', 'assigned', 'in_progress'])->where('due_at', '<', now())->count(),
                'for_inspection' => AdminCategory::isCameras() ? 0 : HousekeepingTask::where('status', 'for_inspection')->count(),
                'open_laundry' => AdminCategory::isCameras() ? 0 : LaundryJob::whereIn('status', ['suggested', 'collected', 'processing'])->count(),
                'open_work_orders' => AdminCategory::workOrders(WorkOrder::query())->whereIn('status', ['open', 'in_progress'])->count(),
                'low_stock' => $this->lowStockCount(),
            ],
            'date' => $today,
        ]);
    }

    /** Each period is compared with one of the same length, so a part-month is never set against a whole month. */
    private function period(string $key): array
    {
        $now = Local::now();
        $lastMonth = $now->subMonthNoOverflow()->startOfMonth();

        return match ($key) {
            'last_month' => [
                'key' => $key,
                'from' => $lastMonth->toDateString(),
                'to' => $lastMonth->endOfMonth()->toDateString(),
                'prior_from' => $lastMonth->subMonthNoOverflow()->toDateString(),
                'prior_to' => $lastMonth->subMonthNoOverflow()->endOfMonth()->toDateString(),
                'compare' => 'vs the month before',
            ],
            '90d' => [
                'key' => $key,
                'from' => $now->subDays(89)->toDateString(),
                'to' => $now->toDateString(),
                'prior_from' => $now->subDays(179)->toDateString(),
                'prior_to' => $now->subDays(90)->toDateString(),
                'compare' => 'vs the 90 days before',
            ],
            default => [
                'key' => 'month',
                'from' => $now->startOfMonth()->toDateString(),
                'to' => $now->toDateString(),
                'prior_from' => $lastMonth->toDateString(),
                'prior_to' => min($lastMonth->addDays($now->day - 1)->toDateString(), $lastMonth->endOfMonth()->toDateString()),
                'compare' => 'vs the same days last month',
            ],
        };
    }

    /** Every active room, including any with no nights, so an idle room is visible. */
    private function roomPerformance(string $from, string $to): array
    {
        $days = (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
        $booked = collect($this->reports->topRooms($from, $to))->keyBy('name');

        return AdminCategory::rooms(Room::where('operational_status', '!=', 'retired'))->orderBy('id')->pluck('name')
            ->map(fn ($name) => [
                'name' => $name,
                'nights' => $booked[$name]['nights'] ?? 0,
                'earned_cents' => $booked[$name]['earned_cents'] ?? 0,
                'booked_pct' => (int) round(($booked[$name]['nights'] ?? 0) / $days * 100),
            ])->sortByDesc('earned_cents')->values()->all();
    }

    /** What each room is doing right now, and who is next. */
    private function roomBoard(string $today): array
    {
        $stays = DB::table('booking_rooms as br')->join('bookings as b', 'b.id', '=', 'br.booking_id')->join('guests as g', 'g.id', '=', 'b.guest_id')
            // A camera still out after its return day is still out, so it stays on the board.
            ->whereNull('br.released_at')->where(fn ($q) => $q->where('br.check_out', '>=', $today)
                ->orWhere(fn ($c) => $c->whereNotNull('br.starts_at')->where('b.status', Booking::CHECKED_IN)))
            ->whereIn('b.status', [Booking::CONFIRMED, Booking::CHECKED_IN])
            ->orderBy('br.check_in')
            ->get(['br.room_id', 'b.id', 'b.status', 'br.check_in', 'br.check_out', 'br.ends_at as return_at', 'g.full_name as guest'])
            ->groupBy('room_id');
        $blocks = RoomBlock::whereNull('released_at')->where('start_date', '<=', $today)->where('end_date', '>', $today)->get()->keyBy('room_id');

        return AdminCategory::rooms(Room::where('operational_status', '!=', 'retired'))->with('property:id,name')->orderBy('property_id')->orderBy('id')->get()
            ->map(function ($room) use ($stays, $blocks, $today) {
                $roomStays = $stays[$room->id] ?? collect();
                $inHouse = $roomStays->firstWhere('status', Booking::CHECKED_IN);
                $next = $roomStays->first(fn ($s) => $s->status === Booking::CONFIRMED && $s->check_in >= $today);

                return [
                    'id' => $room->id,
                    'name' => $room->name,
                    'property' => $room->property->name,
                    'housekeeping' => $room->housekeeping_status,
                    'state' => match (true) {
                        (bool) $inHouse => 'occupied',
                        $room->operational_status === 'maintenance' => 'maintenance',
                        isset($blocks[$room->id]) => 'blocked',
                        $next?->check_in === $today => 'arriving',
                        default => 'vacant',
                    },
                    'stay' => $inHouse ? ['booking_id' => $inHouse->id, 'guest' => $inHouse->guest, 'check_out' => ! empty($inHouse->return_at) ? substr((string) $inHouse->return_at, 0, 10) : $inHouse->check_out] : null,
                    'next' => $next ? ['booking_id' => $next->id, 'guest' => $next->guest, 'check_in' => $next->check_in] : null,
                ];
            })->all();
    }

    private function actions(string $today, $user): array
    {
        $items = [];
        $add = function (int $count, string $label, string $href, string $tone = 'warn') use (&$items) {
            if ($count > 0) {
                $items[] = compact('count', 'label', 'href', 'tone');
            }
        };

        $cameras = AdminCategory::isCameras();
        $bookings = fn () => AdminCategory::bookings(Booking::query());
        $arrivals = $bookings()->where('status', Booking::CONFIRMED)->where('check_in', $today)->with('activeRooms.room')->get();
        $unpaid = $arrivals->filter(fn ($b) => $this->finance->balance($b)['balance_cents'] > 0)->count();
        $dirty = $cameras ? 0 : $arrivals->filter(fn ($b) => $b->activeRooms->contains(fn ($r) => $r->room->housekeeping_status !== 'ready'))->count();

        $mine = AdminCategory::rooms(Room::query())->pluck('id')->all();
        $add($this->inventory->overlaps($today)->whereIn('room_id', $mine)->count(), $cameras ? 'schedule conflict(s): a camera has overlapping rentals or blocks. Fix these first' : 'schedule conflict(s): a room has overlapping stays or blocks. Fix these first', '/admin/calendar', 'danger');
        $add($cameras ? 0 : RoomBlock::where('kind', WorkbookImporter::HOLD_KIND)->whereNull('released_at')->where('end_date', '>', $today)->count(), 'stay(s) held from the sales workbook: enter each real booking, then release its hold', '/admin/calendar');
        $add($dirty, 'arrival(s) today in a room that is not ready', '/admin/housekeeping', 'danger');
        $add($unpaid, $cameras ? 'pickup(s) today with an unpaid balance' : 'arrival(s) today with an unpaid balance', '/admin/bookings?arrivals=today');
        $add($bookings()->where('status', Booking::TENTATIVE)->where('hold_expires_at', '<', now()->addHours(6))->count(), 'hold(s) expiring within 6 hours', '/admin/bookings?status=tentative');
        $add($bookings()->where('status', Booking::EXPIRED)->where('check_in', '>=', $today)->count(), 'expired hold(s) for upcoming dates', '/admin/bookings?status=expired_hold');
        $add(AdminCategory::workOrders(WorkOrder::query())->where('safety_block', true)->whereIn('status', ['open', 'in_progress'])->count(), 'unresolved safety work order(s)', '/admin/work-orders', 'danger');
        $add($cameras ? 0 : HousekeepingTask::where('status', 'for_inspection')->count(), 'room(s) waiting for inspection', '/admin/housekeeping');

        if ($user->can('settings.manage')) {
            $lastRun = Settings::get('system.scheduler_last_run');
            $add($lastRun && now()->diffInMinutes($lastRun, true) <= 10 ? 0 : 1, 'scheduler not running: holds will not expire and alerts will not go out until it is started', '/admin/settings', 'danger');
        }
        if ($user->can('incidents.approve')) {
            $add(AdminCategory::whereHasProperty(Incident::query(), 'room')->whereIn('status', ['reported', 'under_review', 'evidence_pending'])->count(), 'incident(s) needing a decision', '/admin/incidents');
        }
        if ($user->can('dashboard.finance')) {
            $left = $bookings()->where('status', Booking::CHECKED_OUT)->get()
                ->filter(fn ($b) => $this->finance->balance($b)['balance_cents'] > 0)->count();
            $add($left, $cameras ? 'rental(s) returned with an unpaid balance' : 'guest(s) checked out with an unpaid balance', '/admin?tab=income');
        }
        if ($user->can('payments.verify')) {
            $add(AdminCategory::whereHasProperty(Receipt::query(), 'booking')->where('verification_status', 'pending')->count(), 'receipt(s) awaiting verification', '/admin/payments?status=pending');
        }

        return $items;
    }

    private function today(string $today): array
    {
        // Units of the selected business: suites, or cameras (a camera out on rental is "occupied").
        $rooms = AdminCategory::rooms(Room::where('operational_status', '!=', 'retired'))->count();
        $occupied = DB::table('booking_rooms as br')->join('bookings as b', 'b.id', '=', 'br.booking_id')
            ->join('rooms as r', 'r.id', '=', 'br.room_id')->join('properties as p', 'p.id', '=', 'r.property_id')->where('p.category', AdminCategory::current())
            ->whereNull('br.released_at')->where('b.status', Booking::CHECKED_IN)->distinct()->count('br.room_id');
        $blocked = RoomBlock::whereHas('room', fn ($r) => AdminCategory::rooms($r))->whereNull('released_at')->where('start_date', '<=', $today)->where('end_date', '>', $today)->distinct()->count('room_id');

        return [
            'rooms' => $rooms,
            'occupied' => $occupied,
            'blocked' => $blocked,
            'vacant' => max(0, $rooms - $occupied - $blocked),
            'ready' => AdminCategory::isCameras() ? 0 : Room::suites()->where('housekeeping_status', 'ready')->where('operational_status', 'active')->count(),
            'not_ready' => AdminCategory::isCameras() ? 0 : Room::suites()->where('housekeeping_status', '!=', 'ready')->where('operational_status', 'active')->count(),
            'parking_in_use' => AdminCategory::isCameras() ? 0 : ParkingAssignment::blocking()->where('start_date', '<=', $today)->where('end_date', '>', $today)->count(),
            'arrivals' => $this->agenda(AdminCategory::bookings(Booking::where('status', Booking::CONFIRMED)->where('check_in', $today))),
            // A camera is due back on its exact return day (a same-day rental returns on its pickup day); one still out after that stays listed.
            'departures' => $this->agenda(AdminCategory::bookings(Booking::where('status', Booking::CHECKED_IN)->where(fn ($q) => $q
                ->where(fn ($s) => $s->whereNull('return_at')->where('check_out', $today))
                ->orWhere(fn ($c) => $c->whereNotNull('return_at')->where('return_at', '<', \Carbon\CarbonImmutable::parse($today)->addDay()->toDateString().' 00:00:00'))))),
        ];
    }

    private function agenda($query): array
    {
        return $query->with(['guest:id,full_name', 'source:id,name', 'activeRooms.room:id,name,housekeeping_status'])->orderBy('id')->limit(20)->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'reference' => $b->reference,
                'guest' => $b->guest->full_name,
                'source' => $b->source->name,
                'rooms' => $b->activeRooms->map(fn ($r) => ['name' => $r->room->name, 'housekeeping' => $r->room->housekeeping_status]),
                'early' => $b->approved_early_time,
                'special_requests' => $b->special_requests,
            ])->all();
    }

    private function lowStockCount(): int
    {
        return DB::table('inventory_items as i')->leftJoin('inventory_movements as m', 'm.inventory_item_id', '=', 'i.id')
            ->where('i.reorder_level', '>', 0)
            ->groupBy('i.id', 'i.reorder_level')
            ->havingRaw('COALESCE(SUM(m.quantity), 0) <= i.reorder_level')
            ->selectRaw('i.id')->get()->count();
    }
}
