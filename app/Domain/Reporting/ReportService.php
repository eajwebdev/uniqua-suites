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
        'booked' => 'Booked revenue: charges on reservations created in the period, excluding cancelled and no-show bookings.',
        'earned' => 'Earned accommodation: nightly rates for occupied nights in the period, by stay date, before discounts.',
        'collected' => 'Collected cash: verified receipts by receipt date plus actual channel payouts by payout date, less executed refunds.',
        'outstanding' => 'Outstanding: charges on active and completed stays less verified receipts and settled channel gross, as of today.',
        'gross_operating' => 'Gross operating revenue: earned accommodation plus other charges dated in the period.',
        'expenses' => 'Operating expenses: recorded expenses by expense date, whether paid or only incurred.',
        'contribution' => 'Operating contribution: gross operating revenue less operating expenses. Not audited net income.',
        'occupancy' => 'Occupancy: occupied room nights / sellable room nights, excluding blocked nights.',
        'adr' => 'ADR: earned accommodation / occupied room nights.',
        'revpar' => 'RevPAR: earned accommodation / sellable room nights.',
    ];

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
            ->where('c.type', '!=', 'accommodation')
            ->whereBetween('c.charge_date', [$from, $to])
            ->whereIn('b.status', self::STAYED)
            ->when($propertyId, fn ($q) => $q->where('b.property_id', $propertyId))
            ->sum('c.amount_cents');

        $booked = (int) Booking::whereNotIn('status', [Booking::CANCELLED, Booking::NO_SHOW, Booking::INQUIRY, Booking::EXPIRED, Booking::TENTATIVE])
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->when($propertyId, fn ($q) => $q->where('property_id', $propertyId))
            ->sum('total_cents');

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

    public function channelMix(string $from, string $to): array
    {
        $rows = DB::table('booking_sources as s')
            ->leftJoin('bookings as b', fn ($j) => $j->on('b.booking_source_id', '=', 's.id')->whereBetween(DB::raw('DATE(b.created_at)'), [$from, $to]))
            ->groupBy('s.id', 's.name')
            ->selectRaw("s.id, s.name,
                SUM(b.status IN ('confirmed','checked_in','checked_out')) as confirmed,
                SUM(b.status = 'cancelled') as cancelled,
                SUM(b.status = 'inquiry') as inquiries,
                COUNT(b.id) as total")
            ->get();

        $earned = DB::table('booking_nights as n')->join('bookings as b', 'b.id', '=', 'n.booking_id')
            ->where('n.is_current', true)->whereIn('b.status', self::STAYED)->whereBetween('n.stay_date', [$from, $to])
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

    public function topRooms(string $from, string $to): array
    {
        return DB::table('booking_nights as n')->join('bookings as b', 'b.id', '=', 'n.booking_id')->join('rooms as r', 'r.id', '=', 'n.room_id')
            ->where('n.is_current', true)->whereIn('b.status', self::STAYED)->whereBetween('n.stay_date', [$from, $to])
            ->groupBy('r.id', 'r.name')->selectRaw('r.name, COUNT(*) as nights, SUM(n.rate_cents) as cents')
            ->orderByDesc('cents')->limit(9)->get()
            ->map(fn ($r) => ['name' => $r->name, 'nights' => (int) $r->nights, 'earned_cents' => (int) $r->cents])->all();
    }

    public function monthlyTrend(int $months = 12): array
    {
        $out = [];
        $cursor = Local::now()->startOfMonth()->subMonths($months - 1);
        for ($i = 0; $i < $months; $i++) {
            $kpi = $this->kpis($cursor->toDateString(), $cursor->endOfMonth()->toDateString());
            $out[] = ['month' => $cursor->format('M Y'), 'earned' => $kpi['earned_cents'] / 100, 'collected' => $kpi['collected_cents'] / 100, 'expenses' => $kpi['expenses_cents'] / 100, 'occupancy' => $kpi['occupancy_pct'] ?? 0];
            $cursor = $cursor->addMonth();
        }

        return $out;
    }

    /** Tabular report for screen and export. Returns [headers, rows, definition]. Money columns are pesos. */
    public function table(string $type, string $from, string $to): array
    {
        $peso = fn ($c) => number_format(((int) $c) / 100, 2, '.', '');

        return match ($type) {
            'room_ledger' => [
                ['Stay date', 'Room', 'Unit', 'Booking', 'Status', 'Source', 'Rate (PHP)'],
                DB::table('booking_nights as n')->join('bookings as b', 'b.id', '=', 'n.booking_id')->join('rooms as r', 'r.id', '=', 'n.room_id')->join('booking_sources as s', 's.id', '=', 'b.booking_source_id')
                    ->where('n.is_current', true)->whereIn('b.status', self::STAYED)->whereBetween('n.stay_date', [$from, $to])->orderBy('n.stay_date')->orderBy('r.name')
                    ->get(['n.stay_date', 'r.name', 'r.unit_code', 'b.reference', 'b.status', 's.name as source', 'n.rate_cents'])
                    ->map(fn ($x) => [$x->stay_date, $x->name, $x->unit_code, $x->reference, $x->status, $x->source, $peso($x->rate_cents)])->all(),
                'Nightly room ledger by stay date. Nightly rate only, before discounts and extras.',
            ],
            'channels' => [
                ['Source', 'Confirmed', 'Cancelled', 'Open inquiries', 'All bookings created', 'Earned accommodation (PHP)'],
                array_map(fn ($r) => [$r['name'], $r['confirmed'], $r['cancelled'], $r['inquiries'], $r['total'], $peso($r['earned_cents'])], $this->channelMix($from, $to)),
                'Booking counts by created date; earned accommodation by stay date. These bases do not reconcile with each other.',
            ],
            'aging' => [
                ['Booking', 'Guest', 'Source', 'Check-in', 'Days past check-in', 'Status', 'Balance (PHP)'],
                array_map(fn ($r) => [$r['reference'], $r['guest'], $r['source'], $r['check_in'], $r['days_past_check_in'], $r['status'], $peso($r['balance_cents'])], $this->outstanding()['rows']),
                self::DEFINITIONS['outstanding'],
            ],
            'collections' => [
                ['Date', 'Type', 'Reference', 'Method / provider', 'Booking', 'Amount (PHP)'],
                $this->collectionRows($from, $to, $peso),
                self::DEFINITIONS['collected'],
            ],
            'expenses' => [
                ['Date', 'Category', 'Room', 'Description', 'Status', 'Amount (PHP)'],
                DB::table('expenses as e')->join('expense_categories as c', 'c.id', '=', 'e.expense_category_id')->leftJoin('rooms as r', 'r.id', '=', 'e.room_id')
                    ->whereBetween('e.expense_date', [$from, $to])->orderBy('e.expense_date')
                    ->get(['e.expense_date', 'c.name as category', 'r.name as room', 'e.description', 'e.status', 'e.amount_cents'])
                    ->map(fn ($x) => [$x->expense_date, $x->category, $x->room ?? 'General', $x->description, $x->status, $peso($x->amount_cents)])->all(),
                self::DEFINITIONS['expenses'],
            ],
            'tasks' => [
                ['Created', 'Room', 'Type', 'Status', 'Assigned', 'Completed', 'Inspected'],
                DB::table('housekeeping_tasks as t')->join('rooms as r', 'r.id', '=', 't.room_id')->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
                    ->whereBetween(DB::raw('DATE(t.created_at)'), [$from, $to])->orderBy('t.created_at')
                    ->get(['t.created_at', 'r.name', 't.type', 't.status', 'u.name as assignee', 't.completed_at', 't.inspected_at'])
                    ->map(fn ($x) => [$x->created_at, $x->name, $x->type, $x->status, $x->assignee, $x->completed_at, $x->inspected_at])->all(),
                'Housekeeping tasks created in the period.',
            ],
            'incidents' => [
                ['Discovered', 'Room', 'Item', 'Status', 'Estimated (PHP)', 'Approved (PHP)'],
                DB::table('incidents as i')->join('rooms as r', 'r.id', '=', 'i.room_id')->whereBetween('i.discovered_on', [$from, $to])->orderBy('i.discovered_on')
                    ->get(['i.discovered_on', 'r.name', 'i.item', 'i.status', 'i.estimated_cents', 'i.approved_cents'])
                    ->map(fn ($x) => [$x->discovered_on, $x->name, $x->item, $x->status, $x->estimated_cents === null ? '' : $peso($x->estimated_cents), $x->approved_cents === null ? '' : $peso($x->approved_cents)])->all(),
                'Incidents discovered in the period. Only approved amounts become charges.',
            ],
            default => abort(404),
        };
    }

    private function collectionRows(string $from, string $to, callable $peso): array
    {
        $receipts = DB::table('receipts as r')->leftJoin('bookings as b', 'b.id', '=', 'r.booking_id')->where('r.verification_status', 'verified')->whereBetween('r.receipt_date', [$from, $to])
            ->get(['r.receipt_date as d', 'r.reference', 'r.method', 'b.reference as booking', 'r.amount_cents'])
            ->map(fn ($x) => [$x->d, 'Receipt', $x->reference, $x->method, $x->booking, $peso($x->amount_cents)]);
        $payouts = DB::table('external_settlements as s')->leftJoin('bookings as b', 'b.id', '=', 's.booking_id')->whereNotNull('s.actual_payout_cents')->whereBetween('s.payout_date', [$from, $to])
            ->get(['s.payout_date as d', 's.payout_reference', 's.provider', 'b.reference as booking', 's.actual_payout_cents'])
            ->map(fn ($x) => [$x->d, 'Channel payout', $x->payout_reference, $x->provider, $x->booking, $peso($x->actual_payout_cents)]);
        $refunds = DB::table('refunds as x')->join('bookings as b', 'b.id', '=', 'x.booking_id')->where('x.status', 'executed')->whereBetween('x.executed_on', [$from, $to])
            ->get(['x.executed_on as d', 'x.id', 'b.reference as booking', 'x.amount_cents'])
            ->map(fn ($x) => [$x->d, 'Refund', 'REF-'.$x->id, 'refund', $x->booking, $peso(-$x->amount_cents)]);

        return $receipts->concat($payouts)->concat($refunds)->sortBy(0)->values()->all();
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
