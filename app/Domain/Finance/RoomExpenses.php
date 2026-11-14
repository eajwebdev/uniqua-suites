<?php

namespace App\Domain\Finance;

use App\Domain\Availability\RoomConflict;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Incident;
use App\Models\Room;
use App\Models\WorkOrder;
use App\Support\Audit;
use App\Support\Local;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What each room costs. Expenses are the one record of money spent: a repair or a damage
 * replacement becomes a room expense once, linked to its work order or incident, so a room's
 * monthly total never counts the same peso twice.
 */
class RoomExpenses
{
    public function __construct(private FinanceService $finance)
    {
    }

    /** Records the cost of a finished repair or a damage replacement as an expense on that room. */
    public function record(WorkOrder|Incident $source, int $cents, string $status = 'paid', ?string $date = null, ?string $vendor = null): Expense
    {
        if ($cents <= 0) {
            throw new RoomConflict('Enter the amount spent.');
        }
        $date ??= Local::today();
        $column = $source instanceof WorkOrder ? 'work_order_id' : 'incident_id';
        if ($this->alreadyCounted($source)) {
            throw new RoomConflict('This cost is already recorded as an expense. Reverse that expense first to record a different amount.');
        }
        $this->finance->assertOpen($date);
        // A cost at or over the approval limit is recorded as incurred, to be approved and paid from Expenses.
        $threshold = \App\Support\Settings::get('finance.expense_approval_threshold_cents');
        if ($status === 'paid' && $threshold !== null && $cents >= (int) $threshold) {
            $status = 'incurred';
        }

        $category = ExpenseCategory::firstOrCreate(['name' => $source instanceof WorkOrder ? 'Repairs & Maintenance' : 'Damage & Replacement']);
        $room = $source->room_id ? Room::find($source->room_id) : null;
        $expense = Expense::create([
            'expense_category_id' => $category->id,
            'room_id' => $source->room_id,
            'property_id' => $room?->property_id ?? ($source instanceof WorkOrder ? $source->property_id : null),
            'booking_id' => $source->booking_id,
            $column => $source->id,
            'vendor_id' => $vendor ? \App\Models\Vendor::firstOrCreate(['name' => $vendor])->id : null,
            'description' => $source instanceof WorkOrder ? "Repair: {$source->title}" : "Damage replacement: {$source->item}",
            'amount_cents' => $cents,
            'expense_date' => $date,
            'status' => $status,
            'paid_on' => $status === 'paid' ? $date : null,
            'created_by' => auth()->id(),
        ]);
        Audit::log('expense.recorded', $expense, ['amount_cents' => $cents, $column => $source->id]);

        return $expense;
    }

    /**
     * Is this repair already in the room's costs? A damage incident and the work order made for it are
     * one repair, so an expense on either counts for both.
     */
    public function alreadyCounted(WorkOrder|Incident $source): bool
    {
        if ($source instanceof WorkOrder) {
            $incidents = Incident::where('work_order_id', $source->id)->pluck('id');

            return $this->recorded('work_order_id', $source->id) || $incidents->contains(fn ($id) => $this->recorded('incident_id', $id));
        }

        return $this->recorded('incident_id', $source->id) || ($source->work_order_id && $this->recorded('work_order_id', $source->work_order_id));
    }

    /**
     * Ids among $ids that already have a live (unreversed) expense in $column, in one query, for list pages.
     *
     * @return array<int, true>
     */
    public function recordedIds(string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Expense::whereIn($column, $ids)->whereNull('reverses_expense_id')
            ->whereNotExists(fn ($q) => $q->from('expenses as r')->whereColumn('r.reverses_expense_id', 'expenses.id'))
            ->pluck($column)->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /** A linked expense that has not been reversed. */
    public function recorded(string $column, int $id): bool
    {
        return Expense::where($column, $id)->whereNull('reverses_expense_id')
            ->whereNotExists(fn ($q) => $q->from('expenses as r')->whereColumn('r.reverses_expense_id', 'expenses.id'))
            ->exists();
    }

    /**
     * Spend per room per month, by expense date (when the cost was incurred). Reversals net out.
     *
     * @param  list<string>  $months  'Y-m'
     * @return Collection<int, array<string, int>> room_id => [month => cents]; key 0 is spend on no room
     */
    public function monthly(Collection $roomIds, array $months, ?int $categoryId = null): Collection
    {
        $from = $months[0].'-01';
        $to = CarbonImmutable::parse(end($months).'-01')->endOfMonth()->toDateString();

        return DB::table('expenses')
            ->whereIn('room_id', $roomIds)
            ->whereBetween('expense_date', [$from, $to])
            ->when($categoryId, fn ($q, $v) => $q->where('expense_category_id', $v))
            ->get(['room_id', 'expense_date', 'amount_cents'])
            ->groupBy('room_id')
            ->map(fn ($rows) => $rows->groupBy(fn ($r) => substr((string) $r->expense_date, 0, 7))->map(fn ($m) => (int) $m->sum('amount_cents'))->all());
    }

    /** Damage charged to guests per room (approved incident charges not voided), by charge date. */
    public function recovered(Collection $roomIds, string $from, string $to): Collection
    {
        return DB::table('incidents as i')->join('booking_charges as c', 'c.id', '=', 'i.charge_id')
            ->whereIn('i.room_id', $roomIds)->whereNull('c.voided_at')->whereBetween('c.charge_date', [$from, $to])
            ->groupBy('i.room_id')->selectRaw('i.room_id, SUM(c.amount_cents) as cents')->pluck('cents', 'room_id')
            ->map(fn ($c) => (int) $c);
    }
}
