<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Finance\FinanceService;
use App\Domain\Reporting\ReportService;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ExternalSettlement;
use App\Models\HousekeepingTask;
use App\Models\Incident;
use App\Models\IntegrationHealth;
use App\Models\InventoryItem;
use App\Models\LaundryJob;
use App\Models\ParkingAssignment;
use App\Models\Receipt;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\WorkOrder;
use App\Support\Local;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(private ReportService $reports, private FinanceService $finance)
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
        $money = null;
        $performance = null;

        if ($user->can('dashboard.finance')) {
            $now = Local::now();
            $month = $this->reports->kpis($now->startOfMonth()->toDateString(), $today);
            $prior = $this->reports->kpis($now->subMonthNoOverflow()->startOfMonth()->toDateString(), $now->subMonthNoOverflow()->endOfMonth()->toDateString());
            $day = $this->reports->kpis($today, $today);
            $money = [
                'collected_today_cents' => $day['collected_cents'],
                'collected_month_cents' => $month['collected_cents'],
                'collected_prior_cents' => $prior['collected_cents'],
                'outstanding_cents' => $this->reports->outstanding()['total_cents'],
                'expected_payout_cents' => (int) ExternalSettlement::whereNull('actual_payout_cents')->sum('expected_net_cents'),
                'received_payout_month_cents' => $month['payouts_cents'],
                'earned_month_cents' => $month['earned_cents'],
                'earned_prior_cents' => $prior['earned_cents'],
                'expenses_month_cents' => $month['expenses_cents'],
                'contribution_month_cents' => $month['contribution_cents'],
                'definitions' => ReportService::DEFINITIONS,
            ];
            $performance = [
                'occupancy_pct' => $month['occupancy_pct'],
                'adr_cents' => $month['adr_cents'],
                'revpar_cents' => $month['revpar_cents'],
                'top_rooms' => array_slice($this->reports->topRooms($now->startOfMonth()->toDateString(), $today), 0, 5),
                'channels' => $this->reports->channelMix($now->startOfMonth()->toDateString(), $today),
                'trend' => $this->reports->monthlyTrend(6),
            ];
        }

        return Inertia::render('Admin/Dashboard', [
            'actions' => $this->actions($today, $user),
            'today' => $this->today($today),
            'money' => $money,
            'performance' => $performance,
            'team' => [
                'open_tasks' => HousekeepingTask::whereIn('status', ['pending', 'assigned', 'in_progress'])->count(),
                'late_tasks' => HousekeepingTask::whereIn('status', ['pending', 'assigned', 'in_progress'])->where('due_at', '<', now())->count(),
                'for_inspection' => HousekeepingTask::where('status', 'for_inspection')->count(),
                'open_laundry' => LaundryJob::whereIn('status', ['suggested', 'collected', 'processing'])->count(),
                'open_work_orders' => WorkOrder::whereIn('status', ['open', 'in_progress'])->count(),
                'low_stock' => $this->lowStockCount(),
            ],
            'date' => $today,
        ]);
    }

    private function actions(string $today, $user): array
    {
        $items = [];
        $add = function (int $count, string $label, string $href, string $tone = 'warn') use (&$items) {
            if ($count > 0) {
                $items[] = compact('count', 'label', 'href', 'tone');
            }
        };

        $arrivals = Booking::where('status', Booking::CONFIRMED)->where('check_in', $today)->with('activeRooms.room')->get();
        $unpaid = $arrivals->filter(fn ($b) => $this->finance->balance($b)['balance_cents'] > 0)->count();
        $dirty = $arrivals->filter(fn ($b) => $b->activeRooms->contains(fn ($r) => $r->room->housekeeping_status !== 'ready'))->count();

        $add($dirty, 'arrival(s) today in a room that is not ready', '/admin/housekeeping', 'danger');
        $add($unpaid, 'arrival(s) today with an unpaid balance', '/admin/bookings?arrivals=today');
        $add(Booking::where('status', Booking::TENTATIVE)->where('hold_expires_at', '<', now()->addHours(6))->count(), 'hold(s) expiring within 6 hours', '/admin/bookings?status=tentative');
        $add(Booking::where('status', Booking::EXPIRED)->where('check_in', '>=', $today)->count(), 'expired hold(s) for upcoming dates', '/admin/bookings?status=expired_hold');
        $add(WorkOrder::where('safety_block', true)->whereIn('status', ['open', 'in_progress'])->count(), 'unresolved safety work order(s)', '/admin/work-orders', 'danger');
        $add(HousekeepingTask::where('status', 'for_inspection')->count(), 'room(s) waiting for inspection', '/admin/housekeeping');
        $add(IntegrationHealth::whereIn('status', ['failed', 'degraded'])->count(), 'calendar feed problem(s)', '/admin/settings', 'danger');

        if ($user->can('incidents.approve')) {
            $add(Incident::whereIn('status', ['reported', 'under_review', 'evidence_pending'])->count(), 'incident(s) needing a decision', '/admin/incidents');
        }
        if ($user->can('payments.verify')) {
            $add(Receipt::where('verification_status', 'pending')->count(), 'receipt(s) awaiting verification', '/admin/payments?status=pending');
        }

        return $items;
    }

    private function today(string $today): array
    {
        $rooms = Room::where('operational_status', '!=', 'retired')->count();
        $occupied = DB::table('booking_rooms as br')->join('bookings as b', 'b.id', '=', 'br.booking_id')
            ->whereNull('br.released_at')->where('b.status', Booking::CHECKED_IN)->distinct()->count('br.room_id');
        $blocked = RoomBlock::whereNull('released_at')->where('start_date', '<=', $today)->where('end_date', '>', $today)->distinct()->count('room_id');

        return [
            'rooms' => $rooms,
            'occupied' => $occupied,
            'blocked' => $blocked,
            'vacant' => max(0, $rooms - $occupied - $blocked),
            'ready' => Room::where('housekeeping_status', 'ready')->where('operational_status', 'active')->count(),
            'not_ready' => Room::where('housekeeping_status', '!=', 'ready')->where('operational_status', 'active')->count(),
            'parking_in_use' => ParkingAssignment::where('status', 'active')->where('start_date', '<=', $today)->where('end_date', '>', $today)->count(),
            'arrivals' => $this->agenda(Booking::where('status', Booking::CONFIRMED)->where('check_in', $today)),
            'departures' => $this->agenda(Booking::where('status', Booking::CHECKED_IN)->where('check_out', $today)),
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
        return DB::table('inventory_items as i')->join('inventory_movements as m', 'm.inventory_item_id', '=', 'i.id')
            ->where('i.reorder_level', '>', 0)
            ->groupBy('i.id', 'i.reorder_level', 'm.property_id')
            ->havingRaw('SUM(m.quantity) <= i.reorder_level')
            ->selectRaw('i.id')->get()->count();
    }
}
