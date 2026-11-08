<?php

namespace App\Domain\Reporting;

use App\Support\AdminCategory;
use App\Models\Booking;
use App\Models\Expense;
use App\Models\HousekeepingTask;
use App\Models\Incident;
use App\Models\Receipt;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** The detail behind the dashboard's tabs. Totals come from ReportService, so they always match Reports. */
class DashboardDetails
{
    private const OPEN_TASKS = ['pending', 'assigned', 'in_progress'];

    public function __construct(private ReportService $reports)
    {
    }

    public function bookings(string $from, string $to, string $today, bool $withMoney): array
    {
        $channels = collect($this->reports->channelMix($from, $to));
        $created = $channels->sum('total');
        $cancelled = $channels->sum('cancelled');

        $upcoming = AdminCategory::bookings(Booking::query())->where('status', Booking::CONFIRMED)->where('check_in', '>=', $today)
            ->where('check_in', '<=', CarbonImmutable::parse($today)->addDays(13)->toDateString());
        $waiting = AdminCategory::bookings(Booking::query())->whereIn('status', [Booking::INQUIRY, Booking::TENTATIVE])->where('check_in', '>=', $today);

        return [
            'created' => $created,
            'confirmed' => $channels->sum('confirmed'),
            'cancelled' => $cancelled,
            'cancelled_pct' => $created ? (int) round($cancelled / $created * 100) : null,
            'inquiries' => (clone $waiting)->where('status', Booking::INQUIRY)->count(),
            'holds' => (clone $waiting)->where('status', Booking::TENTATIVE)->count(),
            'upcoming_count' => (clone $upcoming)->count(),
            'upcoming' => $this->bookingRows((clone $upcoming)->orderBy('check_in')->orderBy('id')->limit(10)),
            'waiting' => $this->bookingRows((clone $waiting)->orderBy('check_in')->orderBy('id')->limit(10)),
            'channels' => $channels->map(fn ($c) => $withMoney ? $c : array_diff_key($c, ['earned_cents' => 1]))
                ->sortByDesc('total')->values()->all(),
        ];
    }

    public function income(array $kpi, array $outstanding, bool $withGuests): array
    {
        $methods = DB::table('receipts as r')->leftJoin('bookings as b', 'b.id', '=', 'r.booking_id')->tap(fn ($q) => AdminCategory::whereProperty($q, 'b.property_id'))
            ->where('r.verification_status', 'verified')->whereBetween('r.receipt_date', [$kpi['from'], $kpi['to']])
            ->groupBy('r.method')->selectRaw('r.method as method, SUM(r.amount_cents) as cents')->orderByDesc('cents')->get();
        $pending = AdminCategory::whereHasProperty(Receipt::query(), 'booking')->where('verification_status', 'pending');

        return [
            'other_charges_cents' => $kpi['ancillary_cents'],
            'payouts_cents' => $kpi['payouts_cents'],
            'refunds_cents' => $kpi['refunds_cents'],
            'by_method' => $methods->map(fn ($m) => ['method' => $m->method, 'cents' => (int) $m->cents])->all(),
            'pending_count' => (clone $pending)->count(),
            'pending_cents' => (int) (clone $pending)->sum('amount_cents'),
            'unpaid_count' => count($outstanding['rows']),
            // Guest names belong to people who can open payments, not to everyone who sees totals.
            'unpaid' => $withGuests ? collect($outstanding['rows'])->sortByDesc('balance_cents')->take(8)->values()->all() : null,
        ];
    }

    public function expenses(array $kpi, array $prior, bool $withItems): array
    {
        $byCategory = fn (string $from, string $to) => DB::table('expenses as e')->join('expense_categories as c', 'c.id', '=', 'e.expense_category_id')
            ->leftJoin('rooms as r', 'r.id', '=', 'e.room_id')->tap(fn ($q) => AdminCategory::whereProperty($q, DB::raw('COALESCE(r.property_id, e.property_id)')))
            ->whereBetween('e.expense_date', [$from, $to])->groupBy('c.id', 'c.name')
            ->selectRaw('c.id, c.name, SUM(e.amount_cents) as cents, SUM(e.reverses_expense_id IS NULL) as entries')->get();
        $earlier = $byCategory($prior['from'], $prior['to'])->pluck('cents', 'id');

        $real = fn () => AdminCategory::expenses(Expense::query())->whereNull('reverses_expense_id')
            ->whereNotIn('id', Expense::whereNotNull('reverses_expense_id')->select('reverses_expense_id'));
        $unpaid = $real()->where('status', 'incurred');
        $item = fn ($e) => [
            'id' => $e->id,
            'date' => CarbonImmutable::parse($e->expense_date)->toDateString(),
            'category' => $e->category->name,
            'description' => $e->description,
            'vendor' => $e->vendor?->name,
            'where' => $e->room?->name ?? 'General',
            'status' => $e->status,
            'amount_cents' => (int) $e->amount_cents,
        ];
        $load = ['category:id,name', 'vendor:id,name', 'room:id,name'];

        return [
            'paid_cents' => $kpi['expenses_cents'] - $kpi['expenses_unpaid_cents'],
            'unpaid_cents' => $kpi['expenses_unpaid_cents'],
            'share_of_revenue_pct' => $kpi['gross_operating_cents'] > 0 ? (int) round($kpi['expenses_cents'] / $kpi['gross_operating_cents'] * 100) : null,
            'by_category' => $byCategory($kpi['from'], $kpi['to'])
                ->map(fn ($c) => ['name' => $c->name, 'cents' => (int) $c->cents, 'prior_cents' => (int) ($earlier[$c->id] ?? 0), 'entries' => (int) $c->entries])
                ->filter(fn ($c) => $c['cents'] !== 0)->sortByDesc('cents')->values()->all(),
            'by_room' => DB::table('expenses as e')->leftJoin('rooms as r', 'r.id', '=', 'e.room_id')->tap(fn ($q) => AdminCategory::whereProperty($q, DB::raw('COALESCE(r.property_id, e.property_id)')))
                ->whereBetween('e.expense_date', [$kpi['from'], $kpi['to']])->groupBy('r.id', 'r.name')
                ->selectRaw('r.name, SUM(e.amount_cents) as cents')->get()
                ->map(fn ($r) => ['name' => $r->name ?? 'General', 'cents' => (int) $r->cents])
                ->filter(fn ($r) => $r['cents'] !== 0)->sortByDesc('cents')->values()->all(),
            'bills_count' => (clone $unpaid)->count(),
            'bills_cents' => (int) (clone $unpaid)->sum('amount_cents'),
            // Descriptions and vendors are for people who can open the expenses themselves.
            'bills' => $withItems ? (clone $unpaid)->with($load)->orderBy('expense_date')->orderBy('id')->limit(8)->get()->map($item)->all() : null,
            'largest' => $withItems ? $real()->whereBetween('expense_date', [$kpi['from'], $kpi['to']])->with($load)->orderByDesc('amount_cents')->limit(6)->get()->map($item)->all() : null,
        ];
    }

    public function operations(User $user): array
    {
        $tasks = fn () => DB::table('housekeeping_tasks as t')->join('rooms as r', 'r.id', '=', 't.room_id')->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
            ->whereIn('t.status', self::OPEN_TASKS);

        return [
            'tasks_by_status' => AdminCategory::isCameras() ? [] : HousekeepingTask::whereIn('status', [...self::OPEN_TASKS, 'for_inspection'])->groupBy('status')->selectRaw('status, COUNT(*) as total')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all(),
            'open_incidents' => AdminCategory::whereHasProperty(Incident::query(), 'room')->whereIn('status', ['reported', 'under_review', 'evidence_pending'])->count(),
            'tasks' => $user->can('housekeeping.manage') && ! AdminCategory::isCameras()
                ? $tasks()->orderByRaw('t.due_at IS NULL')->orderBy('t.due_at')->limit(8)->get(['t.id', 't.title', 't.type', 't.status', 't.priority', 't.due_at', 'r.name as room', 'u.name as assignee'])
                    ->map(fn ($t) => [
                        'id' => $t->id,
                        'title' => $t->title ?: ucfirst(str_replace('_', ' ', $t->type)),
                        'status' => $t->status,
                        'room' => $t->room,
                        'assignee' => $t->assignee,
                        'due_at' => $t->due_at ? CarbonImmutable::parse($t->due_at, 'UTC')->toIso8601String() : null,
                        'late' => $t->due_at && CarbonImmutable::parse($t->due_at, 'UTC')->isPast(),
                    ])->all()
                : null,
            'repairs' => $user->can('maintenance.manage')
                ? DB::table('work_orders as w')->leftJoin('rooms as r', 'r.id', '=', 'w.room_id')->tap(fn ($q) => AdminCategory::whereProperty($q, DB::raw('COALESCE(r.property_id, w.property_id)')))->whereIn('w.status', ['open', 'in_progress'])
                    ->orderByRaw("FIELD(w.severity, 'critical', 'high', 'normal', 'low')")->orderBy('w.id')->limit(6)
                    ->get(['w.id', 'w.title', 'w.severity', 'w.status', 'r.name as room'])->map(fn ($w) => (array) $w)->all()
                : null,
            'low_stock' => $user->can('inventory.view')
                ? DB::table('inventory_items as i')->join('inventory_movements as m', 'm.inventory_item_id', '=', 'i.id')->join('properties as p', 'p.id', '=', 'm.property_id')
                    ->where('i.reorder_level', '>', 0)->groupBy('i.id', 'i.name', 'i.unit', 'i.reorder_level', 'p.name')
                    ->havingRaw('SUM(m.quantity) <= i.reorder_level')->orderByRaw('SUM(m.quantity) - i.reorder_level')->limit(6)
                    ->selectRaw('i.id, i.name, i.unit, i.reorder_level, p.name as property, SUM(m.quantity) as on_hand')->get()
                    ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'unit' => $s->unit, 'property' => $s->property, 'reorder_level' => (float) $s->reorder_level, 'on_hand' => (float) $s->on_hand])->all()
                : null,
        ];
    }

    private function bookingRows($query): array
    {
        return $query->with(['guest:id,full_name', 'source:id,name', 'activeRooms.room:id,name'])->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'guest' => $b->guest->full_name,
                'source' => $b->source->name,
                'status' => $b->status,
                'rooms' => $b->activeRooms->map(fn ($r) => $r->room->name)->all(),
                'check_in' => $b->check_in->toDateString(),
                'check_out' => $b->check_out->toDateString(),
                'nights' => (int) $b->check_in->diffInDays($b->check_out),
                'hold_expires_at' => $b->hold_expires_at ? CarbonImmutable::parse($b->hold_expires_at)->toIso8601String() : null,
            ])->all();
    }
}
