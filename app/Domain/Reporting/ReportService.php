<?php

namespace App\Domain\Reporting;

use App\Domain\Finance\FinanceService;
use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use App\Support\Local;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Metric definitions live here and are shown beside the numbers. Legacy spreadsheet data
 * is excluded by construction: it never enters bookings, receipts or expenses.
 */
class ReportService
{
    private const STAYED = [Booking::CONFIRMED, Booking::CHECKED_IN, Booking::CHECKED_OUT];

    public const DEFINITIONS = [
        'booked' => 'Booked revenue: charges on reservations created in the period, excluding cancelled and no-show bookings and any configured taxes.',
        'earned' => 'Earned accommodation: nightly rates for occupied nights in the period, by stay date, before discounts.',
        'collected' => 'Collected cash: verified receipts by receipt date plus actual channel payouts by payout date, less executed refunds.',
        'outstanding' => 'Outstanding: charges on active and completed stays less verified receipts and settled channel gross, as of today.',
        'gross_operating' => 'Gross operating revenue: earned accommodation plus other charges dated in the period, excluding any configured taxes.',
        'expenses' => 'Operating expenses: recorded expenses by expense date, whether paid or only incurred.',
        'contribution' => 'Operating contribution: gross operating revenue less operating expenses. Not audited net income.',
        'occupancy' => 'Occupancy: occupied room nights / sellable room nights, excluding blocked nights.',
        'adr' => 'ADR: earned accommodation / occupied room nights.',
        'revpar' => 'RevPAR: earned accommodation / sellable room nights.',
    ];

    public const BASES = [
        'created' => ['Booking-created date', 'Totals of reservations by the local date they were created, excluding inquiries, holds, cancellations and no-shows.'],
        'stay' => ['Stay date', 'Nightly rates by the night occupied, before discounts and extras.'],
        'receipt' => ['Receipt date', 'Verified receipts by receipt date, before refunds. Excludes channel payouts.'],
        'payout' => ['Platform payout date', 'Actual channel payouts received, by payout date.'],
        'expense' => ['Expense date', 'Recorded expenses by expense date, whether paid or only incurred.'],
    ];

    private const UTILITY_CATEGORIES = ['Water', 'Electricity', 'Wi-Fi'];

    public function __construct(private FinanceService $finance)
    {
    }

    public function kpis(string $from, string $to, ?int $propertyId = null): array
    {
        $endExclusive = CarbonImmutable::parse($to)->addDay()->toDateString();
        $roomIds = Room::where('operational_status', '!=', 'retired')
            ->when($propertyId, fn ($q) => $q->where('property_id', $propertyId))->pluck('id');

        $days = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
        $blocked = $this->blockedNights($roomIds->all(), $from, $endExclusive);
        $available = max(0, (int) ($days * $roomIds->count()) - $blocked);

        $nights = $this->nightsQuery($from, $to, $propertyId);
        $occupied = (clone $nights)->count();
        $earned = (int) (clone $nights)->sum('n.rate_cents');

        $ancillary = (int) DB::table('booking_charges as c')
            ->join('bookings as b', 'b.id', '=', 'c.booking_id')
            ->whereNull('c.voided_at')
            ->whereNotIn('c.type', ['accommodation', 'tax'])
            ->whereBetween('c.charge_date', [$from, $to])
            ->whereIn('b.status', self::STAYED)
            ->when($propertyId, fn ($q) => $q->where('b.property_id', $propertyId))
            ->sum('c.amount_cents');

        $booked = (int) Booking::whereNotIn('status', [Booking::CANCELLED, Booking::NO_SHOW, Booking::INQUIRY, Booking::EXPIRED, Booking::TENTATIVE])
            ->whereBetween(DB::raw($this->localDate('created_at')), [$from, $to])
            ->when($propertyId, fn ($q) => $q->where('property_id', $propertyId))
            ->sum('total_cents');
        $booked -= (int) DB::table('booking_charges as c')->join('bookings as b', 'b.id', '=', 'c.booking_id')
            ->where('c.type', 'tax')->whereNull('c.voided_at')
            ->whereNotIn('b.status', [Booking::CANCELLED, Booking::NO_SHOW, Booking::INQUIRY, Booking::EXPIRED, Booking::TENTATIVE])
            ->whereBetween(DB::raw($this->localDate('b.created_at')), [$from, $to])
            ->when($propertyId, fn ($q) => $q->where('b.property_id', $propertyId))
            ->sum('c.amount_cents');

        $receipts = (int) DB::table('receipts as r')->leftJoin('bookings as b', 'b.id', '=', 'r.booking_id')
            ->where('r.verification_status', 'verified')->whereBetween('r.receipt_date', [$from, $to])
            ->when($propertyId, fn ($q) => $q->where('b.property_id', $propertyId))->sum('r.amount_cents');
        $payouts = (int) DB::table('external_settlements as s')->leftJoin('bookings as b', 'b.id', '=', 's.booking_id')
            ->whereNotNull('s.actual_payout_cents')->whereBetween('s.payout_date', [$from, $to])
            ->when($propertyId, fn ($q) => $q->where('b.property_id', $propertyId))->sum('s.actual_payout_cents');
        $refunds = (int) DB::table('refunds as x')->join('bookings as b', 'b.id', '=', 'x.booking_id')
            ->where('x.status', 'executed')->whereBetween('x.executed_on', [$from, $to])
            ->when($propertyId, fn ($q) => $q->where('b.property_id', $propertyId))->sum('x.amount_cents');

        $expenses = DB::table('expenses as e')->leftJoin('rooms as r', 'r.id', '=', 'e.room_id')
            ->whereBetween('e.expense_date', [$from, $to])
            ->when($propertyId, fn ($q) => $q->where(fn ($w) => $w->where('e.property_id', $propertyId)->orWhere('r.property_id', $propertyId)));
        $expenseTotal = (int) (clone $expenses)->sum('e.amount_cents');
        $expenseUnpaid = (int) (clone $expenses)->where('e.status', 'incurred')->sum('e.amount_cents');

        $gross = $earned + $ancillary;

        return [
            'from' => $from,
            'to' => $to,
            'available_room_nights' => $available,
            'occupied_room_nights' => $occupied,
            'occupancy_pct' => $available ? round($occupied / $available * 100, 1) : null,
            'adr_cents' => $occupied ? (int) round($earned / $occupied) : null,
            'revpar_cents' => $available ? (int) round($earned / $available) : null,
            'earned_cents' => $earned,
            'ancillary_cents' => $ancillary,
            'gross_operating_cents' => $gross,
            'booked_cents' => $booked,
            'collected_cents' => $receipts + $payouts - $refunds,
            'receipts_cents' => $receipts,
            'payouts_cents' => $payouts,
            'refunds_cents' => $refunds,
            'expenses_cents' => $expenseTotal,
            'expenses_unpaid_cents' => $expenseUnpaid,
            'contribution_cents' => $gross - $expenseTotal,
        ];
    }

    public function outstanding(?int $propertyId = null): array
    {
        $total = 0;
        $rows = [];
        $bookings = Booking::whereIn('status', self::STAYED)->with(['guest:id,full_name', 'source:id,name'])
            ->when($propertyId, fn ($q) => $q->where('property_id', $propertyId))->get();

        foreach ($bookings as $b) {
            $balance = $this->finance->balance($b);
            if ($balance['balance_cents'] > 0) {
                $total += $balance['balance_cents'];
                $rows[] = [
                    'booking_id' => $b->id,
                    'reference' => $b->reference,
                    'guest' => $b->guest->full_name,
                    'source' => $b->source->name,
                    'check_in' => $b->check_in->toDateString(),
                    'balance_cents' => $balance['balance_cents'],
                    'status' => $balance['status'],
                    'days_past_check_in' => max(0, (int) $b->check_in->diffInDays(Local::today(), false)),
                ];
            }
        }

        return ['total_cents' => $total, 'rows' => $rows];
    }

    public function channelMix(string $from, string $to, ?int $propertyId = null): array
    {
        $rows = DB::table('booking_sources as s')
            ->leftJoin('bookings as b', fn ($j) => $j->on('b.booking_source_id', '=', 's.id')->whereBetween(DB::raw($this->localDate('b.created_at')), [$from, $to])
                ->when($propertyId, fn ($q) => $q->where('b.property_id', $propertyId)))
            ->groupBy('s.id', 's.name')
            ->selectRaw("s.id, s.name,
                SUM(b.status IN ('confirmed','checked_in','checked_out')) as confirmed,
                SUM(b.status = 'cancelled') as cancelled,
                SUM(b.status = 'inquiry') as inquiries,
                COUNT(b.id) as total")
            ->get();

        $earned = $this->nightsQuery($from, $to, $propertyId)
            ->groupBy('b.booking_source_id')->selectRaw('b.booking_source_id as id, SUM(n.rate_cents) as cents')->pluck('cents', 'id');

        return $rows->map(fn ($r) => [
            'name' => $r->name,
            'confirmed' => (int) $r->confirmed,
            'cancelled' => (int) $r->cancelled,
            'inquiries' => (int) $r->inquiries,
            'total' => (int) $r->total,
            'earned_cents' => (int) ($earned[$r->id] ?? 0),
        ])->filter(fn ($r) => $r['total'] > 0 || $r['earned_cents'] > 0)->values()->all();
    }

    public function topRooms(string $from, string $to, ?int $propertyId = null): array
    {
        return $this->nightsQuery($from, $to, null)->join('rooms as r', 'r.id', '=', 'n.room_id')
            ->when($propertyId, fn ($q) => $q->where('r.property_id', $propertyId))
            ->groupBy('r.id', 'r.name')->selectRaw('r.name, COUNT(*) as nights, SUM(n.rate_cents) as cents')
            ->orderByDesc('cents')->limit(9)->get()
            ->map(fn ($r) => ['name' => $r->name, 'nights' => (int) $r->nights, 'earned_cents' => (int) $r->cents])->all();
    }

    /** Rooms booked on each date in the range, including dates with none. */
    public function roomsBookedByDay(string $from, string $to): array
    {
        $nights = $this->nightsQuery($from, $to, null)->groupBy('n.stay_date')->selectRaw('n.stay_date as d, COUNT(DISTINCT n.room_id) as rooms')->pluck('rooms', 'd');
        $out = [];
        for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $to; $day = $day->addDay()) {
            $out[] = ['date' => $day->toDateString(), 'rooms' => (int) ($nights[$day->toDateString()] ?? 0)];
        }

        return $out;
    }

    public function monthlyTrend(int $months = 12, ?int $propertyId = null): array
    {
        $out = [];
        $cursor = Local::now()->startOfMonth()->subMonths($months - 1);
        for ($i = 0; $i < $months; $i++) {
            $kpi = $this->kpis($cursor->toDateString(), $cursor->endOfMonth()->toDateString(), $propertyId);
            $out[] = ['month' => $cursor->format('M Y'), 'earned' => $kpi['earned_cents'] / 100, 'collected' => $kpi['collected_cents'] / 100, 'expenses' => $kpi['expenses_cents'] / 100, 'occupancy' => $kpi['occupancy_pct'] ?? 0];
            $cursor = $cursor->addMonth();
        }

        return $out;
    }

    /** Tabular report for screen and export. Returns [headers, rows, definition]. Money columns are pesos. */
    public function table(string $type, string $from, string $to, ?int $propertyId = null, string $basis = 'stay', array $filters = []): array
    {
        $peso = fn ($c) => number_format(((int) $c) / 100, 2, '.', '');
        $roomInProperty = fn ($q) => $propertyId ? $q->where('r.property_id', $propertyId) : $q;

        return match ($type) {
            'room_ledger' => [
                ['Stay date', 'Room', 'Unit', 'Booking', 'Status', 'Source', 'Rate (PHP)'],
                DB::table('booking_nights as n')->join('bookings as b', 'b.id', '=', 'n.booking_id')->join('rooms as r', 'r.id', '=', 'n.room_id')->join('booking_sources as s', 's.id', '=', 'b.booking_source_id')
                    ->where('n.is_current', true)->whereIn('b.status', self::STAYED)->whereBetween('n.stay_date', [$from, $to])->tap($roomInProperty)
                    ->when($filters['source'] ?? null, fn ($q, $v) => $q->where('b.booking_source_id', $v))
                    ->when($filters['room'] ?? null, fn ($q, $v) => $q->where('n.room_id', $v))
                    ->orderBy('n.stay_date')->orderBy('r.name')
                    ->get(['n.stay_date', 'r.name', 'r.unit_code', 'b.reference', 'b.status', 's.name as source', 'n.rate_cents'])
                    ->map(fn ($x) => [$x->stay_date, $x->name, $x->unit_code, $x->reference, $x->status, $x->source, $peso($x->rate_cents)])->all(),
                'Nightly room ledger by stay date. Nightly rate only, before discounts and extras.',
            ],
            'channels' => [
                ['Source', 'Confirmed', 'Cancelled', 'Open inquiries', 'All bookings created', 'Earned accommodation (PHP)'],
                array_map(fn ($r) => [$r['name'], $r['confirmed'], $r['cancelled'], $r['inquiries'], $r['total'], $peso($r['earned_cents'])], $this->channelMix($from, $to, $propertyId)),
                'Booking counts by created date; earned accommodation by stay date. These bases do not reconcile with each other.',
            ],
            'aging' => [
                ['Booking', 'Guest', 'Source', 'Check-in', 'Days past check-in', 'Status', 'Balance (PHP)'],
                array_map(fn ($r) => [$r['reference'], $r['guest'], $r['source'], $r['check_in'], $r['days_past_check_in'], $r['status'], $peso($r['balance_cents'])], $this->outstanding($propertyId)['rows']),
                self::DEFINITIONS['outstanding'],
            ],
            'collections' => [
                ['Date', 'Type', 'Reference', 'Method / provider', 'Booking', 'Amount (PHP)'],
                $this->collectionRows($from, $to, $peso, $propertyId, $filters['method'] ?? null),
                self::DEFINITIONS['collected'],
            ],
            'expenses' => [
                ['Date', 'Category', 'Room', 'Description', 'Status', 'Amount (PHP)'],
                DB::table('expenses as e')->join('expense_categories as c', 'c.id', '=', 'e.expense_category_id')->leftJoin('rooms as r', 'r.id', '=', 'e.room_id')
                    ->whereBetween('e.expense_date', [$from, $to])->tap($this->expenseInProperty($propertyId))->orderBy('e.expense_date')
                    ->get(['e.expense_date', 'c.name as category', 'r.name as room', 'e.description', 'e.status', 'e.amount_cents'])
                    ->map(fn ($x) => [$x->expense_date, $x->category, $x->room ?? 'General', $x->description, $x->status, $peso($x->amount_cents)])->all(),
                self::DEFINITIONS['expenses'],
            ],
            'tasks' => [
                ['Created', 'Room', 'Type', 'Status', 'Assigned', 'Completed', 'Inspected'],
                DB::table('housekeeping_tasks as t')->join('rooms as r', 'r.id', '=', 't.room_id')->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
                    ->whereBetween(DB::raw($this->localDate('t.created_at')), [$from, $to])->tap($roomInProperty)->orderBy('t.created_at')
                    ->get(['t.created_at', 'r.name', 't.type', 't.status', 'u.name as assignee', 't.completed_at', 't.inspected_at'])
                    ->map(fn ($x) => [$x->created_at, $x->name, $x->type, $x->status, $x->assignee, $x->completed_at, $x->inspected_at])->all(),
                'Housekeeping tasks created in the period.',
            ],
            'incidents' => [
                ['Discovered', 'Room', 'Item', 'Status', 'Estimated (PHP)', 'Approved (PHP)'],
                DB::table('incidents as i')->join('rooms as r', 'r.id', '=', 'i.room_id')->whereBetween('i.discovered_on', [$from, $to])->tap($roomInProperty)->orderBy('i.discovered_on')
                    ->get(['i.discovered_on', 'r.name', 'i.item', 'i.status', 'i.estimated_cents', 'i.approved_cents'])
                    ->map(fn ($x) => [$x->discovered_on, $x->name, $x->item, $x->status, $x->estimated_cents === null ? '' : $peso($x->estimated_cents), $x->approved_cents === null ? '' : $peso($x->approved_cents)])->all(),
                'Incidents discovered in the period. Only approved amounts become charges.',
            ],
            'sales_recording' => $this->salesRecording($from, $to, $propertyId, $peso),
            'room_grid' => $this->roomGrid($from, $to, $propertyId, $peso),
            'by_basis' => $this->byBasis($from, $to, $propertyId, $basis, $peso),
            'utilities' => $this->utilities($from, $to, $propertyId, $peso),
            'parking' => $this->parking($from, $to, $propertyId, $peso),
            'cleaning_laundry' => $this->cleaningLaundry($from, $to, $propertyId, $peso),
            default => abort(404),
        };
    }

    private function collectionRows(string $from, string $to, callable $peso, ?int $propertyId = null, ?string $method = null): array
    {
        $inProperty = fn ($q) => $propertyId ? $q->where('b.property_id', $propertyId) : $q;

        $receipts = DB::table('receipts as r')->leftJoin('bookings as b', 'b.id', '=', 'r.booking_id')->where('r.verification_status', 'verified')->whereBetween('r.receipt_date', [$from, $to])->tap($inProperty)->when($method, fn ($q, $v) => $q->where('r.method', $v))
            ->get(['r.receipt_date as d', 'r.reference', 'r.method', 'b.reference as booking', 'r.amount_cents'])
            ->map(fn ($x) => [$x->d, 'Receipt', $x->reference, $x->method, $x->booking, $peso($x->amount_cents)]);
        $payouts = DB::table('external_settlements as s')->leftJoin('bookings as b', 'b.id', '=', 's.booking_id')->whereNotNull('s.actual_payout_cents')->whereBetween('s.payout_date', [$from, $to])->tap($inProperty)
            ->get(['s.payout_date as d', 's.payout_reference', 's.provider', 'b.reference as booking', 's.actual_payout_cents'])
            ->map(fn ($x) => [$x->d, 'Channel payout', $x->payout_reference, $x->provider, $x->booking, $peso($x->actual_payout_cents)]);
        $refunds = DB::table('refunds as x')->join('bookings as b', 'b.id', '=', 'x.booking_id')->where('x.status', 'executed')->whereBetween('x.executed_on', [$from, $to])->tap($inProperty)
            ->get(['x.executed_on as d', 'x.id', 'b.reference as booking', 'x.amount_cents'])
            ->map(fn ($x) => [$x->d, 'Refund', 'REF-'.$x->id, 'refund', $x->booking, $peso(-$x->amount_cents)]);

        // Payouts and refunds carry no payment method, so a method filter shows receipts only.
        return ($method ? $receipts : $receipts->concat($payouts)->concat($refunds))->sortBy(0)->values()->all();
    }

    /** The owner's familiar month sheet: a row per day, a column per room, channel tallies underneath. */
    private function roomGrid(string $from, string $to, ?int $propertyId, callable $peso): array
    {
        $rooms = Room::when($propertyId, fn ($q) => $q->where('property_id', $propertyId))->orderBy('id')->get(['id', 'name', 'unit_code', 'operational_status']);
        $nights = $this->nightsQuery($from, $to, null)->join('booking_sources as s', 's.id', '=', 'b.booking_source_id')
            ->whereIn('n.room_id', $rooms->pluck('id'))->get(['n.stay_date', 'n.room_id', 'n.rate_cents', 's.name as source']);
        $rooms = $rooms->filter(fn ($r) => $r->operational_status !== 'retired' || $nights->contains('room_id', $r->id))->values();

        $endExclusive = CarbonImmutable::parse($to)->addDay()->toDateString();
        $blocked = [];
        $blocks = DB::table('room_blocks')->whereIn('room_id', $rooms->pluck('id'))->whereNull('released_at')
            ->where('start_date', '<', $endExclusive)->where('end_date', '>', $from)->get(['room_id', 'start_date', 'end_date']);
        foreach ($blocks as $b) {
            $last = min($b->end_date, $endExclusive);
            for ($d = CarbonImmutable::parse(max($b->start_date, $from)); $d->toDateString() < $last; $d = $d->addDay()) {
                $blocked[$b->room_id][$d->toDateString()] = true;
            }
        }

        $rates = [];
        foreach ($nights as $n) {
            $rates[$n->room_id][$n->stay_date] = (int) $n->rate_cents;
        }

        $rows = [];
        $days = 0;
        for ($d = CarbonImmutable::parse($from); $d->toDateString() < $endExclusive; $d = $d->addDay()) {
            $date = $d->toDateString();
            $days++;
            $row = [$date];
            $dayTotal = 0;
            foreach ($rooms as $room) {
                $cents = $rates[$room->id][$date] ?? null;
                $dayTotal += $cents ?? 0;
                $row[] = $cents !== null ? $peso($cents) : (isset($blocked[$room->id][$date]) ? 'Blocked' : '');
            }
            $row[] = $dayTotal ? $peso($dayTotal) : '';
            $rows[] = $row;
        }

        $occupied = $rooms->map(fn ($r) => $nights->where('room_id', $r->id)->count());
        $blockedOnly = $rooms->map(fn ($r) => count(array_diff_key($blocked[$r->id] ?? [], $rates[$r->id] ?? [])));
        $sum = fn ($values) => array_merge($values->all(), [$values->sum()]);

        $rows[] = array_merge(['Total (PHP)'], $rooms->map(fn ($r) => $peso($nights->where('room_id', $r->id)->sum('rate_cents')))->all(), [$peso($nights->sum('rate_cents'))]);
        $rows[] = array_merge(['Occupied nights'], $sum($occupied));
        $rows[] = array_merge(['Blocked nights'], $sum($blockedOnly));
        $rows[] = array_merge(['Vacant nights'], $sum($rooms->keys()->map(fn ($i) => $days - $occupied[$i] - $blockedOnly[$i])));
        foreach ($nights->pluck('source')->unique()->sort() as $source) {
            $rows[] = array_merge(["{$source} nights"], $sum($rooms->map(fn ($r) => $nights->where('room_id', $r->id)->where('source', $source)->count())));
        }

        return [
            array_merge(['Date'], $rooms->map(fn ($r) => "{$r->name} ({$r->unit_code})")->all(), ['Total']),
            $rows,
            'Nightly rate (PHP) by stay date, before discounts and extras, with night counts by channel below. A blank cell is a vacant night, not a booking.',
        ];
    }

    private function byBasis(string $from, string $to, ?int $propertyId, string $basis, callable $peso): array
    {
        abort_unless(isset(self::BASES[$basis]), 404);
        $inBooking = fn ($q) => $propertyId ? $q->where('b.property_id', $propertyId) : $q;

        $query = match ($basis) {
            'created' => DB::table('bookings as b')->whereNotIn('b.status', [Booking::CANCELLED, Booking::NO_SHOW, Booking::INQUIRY, Booking::EXPIRED, Booking::TENTATIVE])
                ->whereBetween(DB::raw($this->localDate('b.created_at')), [$from, $to])->tap($inBooking)
                ->selectRaw($this->localDate('b.created_at').' as d, COUNT(*) as n, SUM(b.total_cents) as cents'),
            'stay' => $this->nightsQuery($from, $to, $propertyId)->selectRaw('n.stay_date as d, COUNT(*) as n, SUM(n.rate_cents) as cents'),
            'receipt' => DB::table('receipts as r')->leftJoin('bookings as b', 'b.id', '=', 'r.booking_id')->where('r.verification_status', 'verified')
                ->whereBetween('r.receipt_date', [$from, $to])->tap($inBooking)->selectRaw('r.receipt_date as d, COUNT(*) as n, SUM(r.amount_cents) as cents'),
            'payout' => DB::table('external_settlements as s')->leftJoin('bookings as b', 'b.id', '=', 's.booking_id')->whereNotNull('s.actual_payout_cents')
                ->whereBetween('s.payout_date', [$from, $to])->tap($inBooking)->selectRaw('s.payout_date as d, COUNT(*) as n, SUM(s.actual_payout_cents) as cents'),
            'expense' => DB::table('expenses as e')->leftJoin('rooms as r', 'r.id', '=', 'e.room_id')->whereBetween('e.expense_date', [$from, $to])
                ->tap($this->expenseInProperty($propertyId))->selectRaw('e.expense_date as d, COUNT(*) as n, SUM(e.amount_cents) as cents'),
        };
        $days = $query->groupBy('d')->orderBy('d')->get();
        $rows = $days->map(fn ($x) => [$x->d, (int) $x->n, $peso($x->cents)])->all();
        if ($rows) {
            $rows[] = ['Total', (int) $days->sum('n'), $peso($days->sum('cents'))];
        }

        return [
            [self::BASES[$basis][0], 'Entries', 'Amount (PHP)'],
            $rows,
            self::BASES[$basis][1].' Totals on different bases are not expected to reconcile with each other.',
        ];
    }

    /** One row per month and cost centre, so rooms can be compared. */
    private function utilities(string $from, string $to, ?int $propertyId, callable $peso): array
    {
        $rows = DB::table('expenses as e')->join('expense_categories as c', 'c.id', '=', 'e.expense_category_id')
            ->leftJoin('rooms as r', 'r.id', '=', 'e.room_id')->leftJoin('properties as p', 'p.id', '=', 'e.property_id')
            ->whereIn('c.name', self::UTILITY_CATEGORIES)->whereBetween('e.expense_date', [$from, $to])->tap($this->expenseInProperty($propertyId))
            ->orderBy('e.expense_date')->get(['e.expense_date', 'c.name as category', 'r.name as room', 'p.name as property', 'e.status', 'e.amount_cents']);

        $table = $rows->groupBy(fn ($x) => substr($x->expense_date, 0, 7).'|'.($x->room ?? $x->property ?? 'General'))->map(function ($group, $key) use ($peso) {
            [$month, $allocation] = explode('|', $key, 2);
            $cells = array_map(fn ($category) => $peso($group->where('category', $category)->sum('amount_cents')), self::UTILITY_CATEGORIES);

            return array_merge([$month, $allocation], $cells, [$peso($group->sum('amount_cents')), $peso($group->where('status', 'incurred')->sum('amount_cents'))]);
        })->sortBy(fn ($row) => $row[0].$row[1])->values()->all();

        return [
            array_merge(['Month', 'Allocated to'], array_map(fn ($c) => "{$c} (PHP)", self::UTILITY_CATEGORIES), ['Total (PHP)', 'Unpaid (PHP)']),
            $table,
            'Expenses recorded under '.implode(', ', self::UTILITY_CATEGORIES).' by expense date. A blank month means nothing was recorded, not that the cost was zero.',
        ];
    }

    private function parking(string $from, string $to, ?int $propertyId, callable $peso): array
    {
        $endExclusive = CarbonImmutable::parse($to)->addDay()->toDateString();
        $rows = DB::table('parking_assignments as a')->join('parking_spaces as s', 's.id', '=', 'a.parking_space_id')->join('properties as p', 'p.id', '=', 's.property_id')
            ->leftJoin('bookings as b', 'b.id', '=', 'a.booking_id')->leftJoin('booking_charges as c', 'c.id', '=', 'a.charge_id')
            ->where('a.start_date', '<', $endExclusive)->where('a.end_date', '>', $from)
            ->when($propertyId, fn ($q) => $q->where('s.property_id', $propertyId))
            ->orderBy('a.start_date')->orderBy('s.code')
            ->get(['s.code', 'p.name as property', 'a.holder_name', 'a.vehicle', 'a.start_date', 'a.end_date', 'a.status', 'b.reference', 'c.amount_cents', 'c.voided_at']);

        return [
            ['Space', 'Location', 'Holder', 'Vehicle', 'From', 'To (exclusive)', 'Nights in period', 'Status', 'Booking', 'Charge (PHP)'],
            $rows->map(function ($x) use ($from, $endExclusive, $peso) {
                $nights = $x->status === 'active' ? (int) CarbonImmutable::parse(max($x->start_date, $from))->diffInDays(CarbonImmutable::parse(min($x->end_date, $endExclusive))) : 0;

                return [$x->code, $x->property, $x->holder_name, $x->vehicle, $x->start_date, $x->end_date, $nights, $x->status, $x->reference, $x->amount_cents !== null && $x->voided_at === null ? $peso($x->amount_cents) : ''];
            })->all(),
            'Assignments overlapping the period. The charge is the whole assignment, not only the nights in this period. Cancelled assignments count zero nights.',
        ];
    }

    /**
     * The sales workbook's layout, room by room: its own rows for the past, live bookings from now on.
     * Each line says where it came from, and a live booking replaces a workbook row for the same room night.
     */
    private function salesRecording(string $from, string $to, ?int $propertyId, callable $peso): array
    {
        $live = $this->nightsQuery($from, $to, $propertyId)
            ->join('rooms as r', 'r.id', '=', 'n.room_id')->join('guests as g', 'g.id', '=', 'b.guest_id')->join('booking_sources as s', 's.id', '=', 'b.booking_source_id')
            ->get(['n.room_id', 'r.id as sort', 'r.name as room', 'n.stay_date as date', 'g.full_name as guest', 's.name as source', 'n.rate_cents', 'b.reference']);
        $liveNights = $live->map(fn ($x) => "{$x->room_id}|{$x->date}")->flip();

        $history = DB::table('legacy_rows as l')->leftJoin('rooms as r', 'r.id', '=', 'l.room_id')
            ->where('l.record_type', 'daily_sale')->where('l.review_status', '!=', 'rejected')->whereBetween('l.record_date', [$from, $to])
            ->when($propertyId, fn ($q) => $q->where('r.property_id', $propertyId))
            ->get(['l.room_id', 'r.id as sort', DB::raw('COALESCE(r.name, l.room_label) as room'), 'l.record_date as date', 'l.guest_name as guest', 'l.source_label as source', 'l.payment_label as payment', 'l.amount_cents', 'l.extra_cents', 'l.raw'])
            ->reject(fn ($x) => $x->room_id && isset($liveNights["{$x->room_id}|{$x->date}"]));

        $lines = $history->map(fn ($x) => [
            'sort' => $x->sort ?? PHP_INT_MAX, 'room' => $x->room, 'date' => $x->date, 'guest' => $x->guest, 'source' => $x->source, 'payment' => $x->payment,
            'rate' => (int) $x->amount_cents, 'extra' => (int) $x->extra_cents, 'remarks' => json_decode($x->raw, true)['REMARKS'] ?? '', 'record' => 'Workbook (unverified)',
        ])->concat($live->map(fn ($x) => [
            'sort' => $x->sort, 'room' => $x->room, 'date' => $x->date, 'guest' => $x->guest, 'source' => $x->source, 'payment' => '',
            'rate' => (int) $x->rate_cents, 'extra' => 0, 'remarks' => $x->reference, 'record' => 'System',
        ]))->sortBy([['sort', 'asc'], ['room', 'asc'], ['date', 'asc']])->values();

        $rows = [];
        foreach ($lines->groupBy('room') as $room => $nights) {
            foreach ($nights as $l) {
                $rows[] = [$l['date'], $room, $l['guest'], $l['source'], $l['payment'], $peso($l['rate']), $peso($l['extra']), $peso($l['rate'] + $l['extra']), $l['remarks'], $l['record']];
            }
            $rows[] = ['', "{$room} total", '', '', '', $peso($nights->sum('rate')), $peso($nights->sum('extra')), $peso($nights->sum('rate') + $nights->sum('extra')), '', ''];
        }
        if ($lines->isNotEmpty()) {
            $rows[] = ['', 'ALL ROOMS TOTAL', '', '', '', $peso($lines->sum('rate')), $peso($lines->sum('extra')), $peso($lines->sum('rate') + $lines->sum('extra')), '', ''];
        }

        return [
            ['Date', 'Room', 'Name', 'Booking via', 'Payment source', 'Night rate (PHP)', 'Extra guests (PHP)', 'Total (PHP)', 'Remarks', 'Record'],
            $rows,
            'Same layout as the sales workbook. Workbook lines are unverified: copied from the sheet, without proof of payment, and kept out of the verified figures in other reports. System lines are live bookings at their nightly rate.',
        ];
    }

    /** Job costs and expenses stay in separate columns: the same bill may be entered in both. */
    private function cleaningLaundry(string $from, string $to, ?int $propertyId, callable $peso): array
    {
        $rooms = Room::when($propertyId, fn ($q) => $q->where('property_id', $propertyId))->orderBy('id')->get(['id', 'name']);
        $tasks = DB::table('housekeeping_tasks')->where('type', 'turnover')->whereNotNull('completed_at')
            ->whereBetween(DB::raw($this->localDate('completed_at')), [$from, $to])->groupBy('room_id')->selectRaw('room_id, COUNT(*) as n')->pluck('n', 'room_id');
        $jobs = DB::table('laundry_jobs')->where('status', '!=', 'cancelled')->whereBetween(DB::raw($this->localDate('created_at')), [$from, $to])
            ->groupBy('room_id')->selectRaw('room_id, COUNT(*) as n, SUM(quantity) as qty, SUM(cost_cents) as cents')->get()->keyBy('room_id');
        $expenses = DB::table('expenses as e')->join('expense_categories as c', 'c.id', '=', 'e.expense_category_id')->leftJoin('rooms as r', 'r.id', '=', 'e.room_id')
            ->whereIn('c.name', ['Cleaning', 'Laundry'])->whereBetween('e.expense_date', [$from, $to])->tap($this->expenseInProperty($propertyId))
            ->get(['e.room_id', 'c.name as category', 'e.amount_cents']);

        $rows = $rooms->map(fn ($r) => [
            $r->name,
            (int) ($tasks[$r->id] ?? 0),
            (int) ($jobs[$r->id]->n ?? 0),
            (int) ($jobs[$r->id]->qty ?? 0),
            $peso($jobs[$r->id]->cents ?? 0),
            $peso($expenses->where('room_id', $r->id)->where('category', 'Cleaning')->sum('amount_cents')),
            $peso($expenses->where('room_id', $r->id)->where('category', 'Laundry')->sum('amount_cents')),
        ])->all();

        $general = $expenses->whereNull('room_id');
        if ($general->isNotEmpty()) {
            $rows[] = ['Not room-specific', 0, 0, 0, $peso(0), $peso($general->where('category', 'Cleaning')->sum('amount_cents')), $peso($general->where('category', 'Laundry')->sum('amount_cents'))];
        }

        return [
            ['Room', 'Turnovers completed', 'Laundry jobs', 'Laundry items', 'Laundry job cost (PHP)', 'Cleaning expenses (PHP)', 'Laundry expenses (PHP)'],
            $rows,
            'Work by completion or creation date; expenses by expense date. Laundry job cost and laundry expenses are recorded separately and may describe the same bill, so do not add them.',
        ];
    }

    /** Timestamps are stored in UTC, so the local day needs converting or a morning booking lands on the previous date. */
    private function localDate(string $column): string
    {
        return "DATE(CONVERT_TZ({$column}, '+00:00', '".Local::now()->format('P')."'))";
    }

    /** Expects the expense as `e` and its room as `r`, matching how kpis() attributes a cost to a property. */
    private function expenseInProperty(?int $propertyId): callable
    {
        return fn ($q) => $propertyId ? $q->where(fn ($w) => $w->where('e.property_id', $propertyId)->orWhere('r.property_id', $propertyId)) : $q;
    }

    private function nightsQuery(string $from, string $to, ?int $propertyId)
    {
        return DB::table('booking_nights as n')->join('bookings as b', 'b.id', '=', 'n.booking_id')
            ->where('n.is_current', true)->whereIn('b.status', self::STAYED)->whereBetween('n.stay_date', [$from, $to])
            ->when($propertyId, fn ($q) => $q->where('b.property_id', $propertyId));
    }

    private function blockedNights(array $roomIds, string $from, string $endExclusive): int
    {
        $total = 0;
        $blocks = DB::table('room_blocks')->whereIn('room_id', $roomIds)->whereNull('released_at')
            ->where('start_date', '<', $endExclusive)->where('end_date', '>', $from)->get(['start_date', 'end_date']);
        foreach ($blocks as $b) {
            $start = max($b->start_date, $from);
            $end = min($b->end_date, $endExclusive);
            $total += (int) CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end));
        }

        return $total;
    }

    public function generatedBy(?User $user): string
    {
        return ($user?->name ?? 'system').', '.Local::now()->format('Y-m-d H:i').' Asia/Manila';
    }
}
