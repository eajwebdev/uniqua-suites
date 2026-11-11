<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminCategory;
use App\Domain\Finance\FinanceService;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Property;
use App\Models\Room;
use App\Models\Vendor;
use App\Support\Audit;
use App\Support\Local;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ExpenseController extends Controller
{
    private const TABS = [['label' => 'Expenses', 'href' => '/admin/expenses'], ['label' => 'Cashbook', 'href' => '/admin/cashbook']];

    public function __construct(private FinanceService $finance)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $f = $request->validate(['category' => 'nullable|integer', 'status' => 'nullable|in:incurred,paid']);
        $rows = AdminCategory::expenses(Expense::query())->with(['category:id,name', 'room:id,name', 'property:id,name', 'vendor:id,name'])
            ->when($f['category'] ?? null, fn ($q, $v) => $q->where('expense_category_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('expense_date')->orderByDesc('id')->paginate(15)->withQueryString();
        $reversed = Expense::whereNotNull('reverses_expense_id')->pluck('reverses_expense_id');
        $categories = ExpenseCategory::orderBy('name')->get()->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])->all();

        return Inertia::render('Admin/Resource', [
            'title' => 'Expenses',
            'subtitle' => 'Incurred and paid are tracked separately: an unpaid bill counts as an expense but not as cash out.',
            'tabs' => self::TABS,
            'filters' => [
                ['name' => 'category', 'label' => 'Category', 'value' => $f['category'] ?? null, 'options' => $categories],
                ['name' => 'status', 'label' => 'Status', 'value' => $f['status'] ?? null, 'options' => [['value' => 'incurred', 'label' => 'Incurred'], ['value' => 'paid', 'label' => 'Paid']]],
            ],
            'columns' => [
                ['key' => 'expense_date', 'label' => 'Date', 'type' => 'date'],
                ['key' => 'category', 'label' => 'Category'],
                ['key' => 'allocation', 'label' => 'Allocated to'],
                ['key' => 'description', 'label' => 'Description'],
                ['key' => 'vendor', 'label' => 'Vendor'],
                ['key' => 'amount_cents', 'label' => 'Amount', 'type' => 'money'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
                ['key' => 'proof', 'label' => 'Proof', 'type' => 'link'],
            ],
            'rows' => $rows->through(function ($e) use ($user, $reversed) {
                $actions = [];
                $isReversal = $e->reverses_expense_id !== null;
                if ($user->can('expenses.manage') && $e->status === 'incurred' && ! $isReversal) {
                    $actions[] = ['label' => 'Mark paid', 'url' => "/admin/expenses/{$e->id}/paid", 'fields' => [
                        ['name' => 'paid_on', 'label' => 'Paid on', 'type' => 'date', 'required' => true, 'default' => Local::today()],
                        ['name' => 'payment_method', 'label' => 'Method', 'type' => 'text'],
                    ]];
                }
                if ($user->can('expenses.approve') && ! $e->approved_at && ! $isReversal) {
                    $actions[] = ['label' => 'Approve', 'url' => "/admin/expenses/{$e->id}/approve"];
                }
                if ($user->can('expenses.manage') && ! $isReversal && ! $reversed->contains($e->id)) {
                    $actions[] = ['label' => 'Reverse', 'url' => "/admin/expenses/{$e->id}/reverse", 'variant' => 'danger', 'confirm' => 'This records a new negative entry dated today. The original stays on file. Continue?', 'fields' => [['name' => 'reason', 'label' => 'Why is this being reversed?', 'type' => 'text', 'required' => true]]];
                }

                return [
                    'id' => $e->id,
                    'expense_date' => $e->expense_date->toDateString(),
                    'category' => $e->category->name,
                    'allocation' => $e->room?->name ?? $e->property?->name ?? 'General',
                    'description' => $e->description,
                    'vendor' => $e->vendor?->name,
                    'amount_cents' => (int) $e->amount_cents,
                    'status' => $e->status,
                    'proof' => $e->proof_path ? ['label' => 'View', 'href' => "/admin/files/expense/{$e->id}", 'external' => true] : ['label' => 'None'],
                    '_actions' => $actions,
                ];
            }),
            'create' => $user->can('expenses.manage') ? ['label' => 'Add expense', 'url' => '/admin/expenses', 'fields' => [
                ['name' => 'expense_date', 'label' => 'Date', 'type' => 'date', 'required' => true, 'default' => Local::today()],
                ['name' => 'expense_category_id', 'label' => 'Category', 'type' => 'select', 'required' => true, 'options' => $categories],
                ['name' => 'description', 'label' => 'Description', 'type' => 'text', 'required' => true],
                ['name' => 'amount', 'label' => 'Amount (PHP)', 'type' => 'money', 'required' => true],
                ['name' => 'room_id', 'label' => 'Room', 'type' => 'select', 'options' => AdminCategory::rooms(Room::query())->orderBy('id')->get()->map(fn ($r) => ['value' => $r->id, 'label' => $r->name])->all(), 'help' => AdminCategory::isCameras() ? 'Choose the camera, or Grab Cam Rental as the property for a general camera cost.' : 'Leave room and property blank for a general expense.'],
                ['name' => 'property_id', 'label' => 'Property', 'type' => 'select', 'options' => Property::where('category', AdminCategory::current())->orderBy('id')->get()->map(fn ($p) => ['value' => $p->id, 'label' => $p->name])->all()],
                ['name' => 'vendor_name', 'label' => 'Vendor', 'type' => 'text'],
                ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'incurred', 'options' => [['value' => 'incurred', 'label' => 'Incurred, not yet paid'], ['value' => 'paid', 'label' => 'Paid']]],
                ['name' => 'payment_method', 'label' => 'Payment method', 'type' => 'text'],
                ['name' => 'proof', 'label' => 'Proof (image or PDF)', 'type' => 'file'],
            ]] : null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'expense_date' => 'required|date_format:Y-m-d',
            'expense_category_id' => 'required|exists:expense_categories,id',
            'description' => 'required|string|max:255',
            'amount' => ['required', Money::RULE],
            'room_id' => 'nullable|exists:rooms,id',
            'property_id' => 'nullable|exists:properties,id',
            'vendor_name' => 'nullable|string|max:120',
            'status' => 'required|in:incurred,paid',
            'payment_method' => 'nullable|string|max:60',
            'proof' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
        ]);
        $this->finance->assertOpen($data['expense_date']);
        if ($data['status'] === 'paid') {
            $this->finance->assertExpensePayable(Money::cents($data['amount']), false);
        }

        $expense = Expense::create([
            'expense_date' => $data['expense_date'],
            'expense_category_id' => $data['expense_category_id'],
            'description' => $data['description'],
            'amount_cents' => Money::cents($data['amount']),
            'room_id' => $data['room_id'] ?? null,
            'property_id' => $data['property_id'] ?? null,
            'vendor_id' => ! empty($data['vendor_name']) ? Vendor::firstOrCreate(['name' => $data['vendor_name']])->id : null,
            'status' => $data['status'],
            'paid_on' => $data['status'] === 'paid' ? $data['expense_date'] : null,
            'payment_method' => $data['payment_method'] ?? null,
            'proof_path' => $request->hasFile('proof') ? $request->file('proof')->store('evidence/expenses', 'local') : null,
            'created_by' => $request->user()->id,
        ]);
        Audit::log('expense.recorded', $expense, ['amount_cents' => $expense->amount_cents]);

        return back()->with('success', 'Expense recorded.');
    }

    public function markPaid(Request $request, Expense $expense)
    {
        $data = $request->validate(['paid_on' => 'required|date_format:Y-m-d', 'payment_method' => 'nullable|string|max:60']);
        $this->finance->markExpensePaid($expense, $data);

        return back()->with('success', 'Marked as paid.');
    }

    public function approve(Expense $expense)
    {
        $this->finance->approveExpense($expense);

        return back()->with('success', 'Expense approved.');
    }

    public function reverse(Request $request, Expense $expense)
    {
        $this->finance->reverseExpense($expense, $request->validate(['reason' => 'required|string|max:200'])['reason']);

        return back()->with('success', 'Reversal recorded, dated today. The original expense is unchanged.');
    }

    /** Actual money movements by date. Unpaid expenses and unverified receipts are excluded. */
    public function cashbook(Request $request)
    {
        $f = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $from = $f['from'] ?? Local::now()->startOfMonth()->toDateString();
        $to = $f['to'] ?? Local::today();

        // Only the selected business's money. Unlinked payments and general expenses belong to Suites.
        $booked = fn (string $table, string $alias) => DB::table("{$table} as {$alias}")->leftJoin('bookings as b', 'b.id', '=', "{$alias}.booking_id")
            ->tap(fn ($q) => AdminCategory::whereProperty($q, 'b.property_id'));
        $in = $booked('receipts', 'x')->where('x.verification_status', 'verified')->whereBetween('x.receipt_date', [$from, $to])
            ->get(['x.receipt_date as d', 'x.reference as ref', 'x.method as label', 'x.amount_cents as cents']);
        $payouts = $booked('external_settlements', 'x')->whereNotNull('x.actual_payout_cents')->whereBetween('x.payout_date', [$from, $to])
            ->get(['x.payout_date as d', 'x.payout_reference as ref', 'x.provider as label', 'x.actual_payout_cents as cents']);
        $out = DB::table('expenses as e')->leftJoin('rooms as r', 'r.id', '=', 'e.room_id')
            ->tap(fn ($q) => AdminCategory::whereProperty($q, DB::raw('COALESCE(r.property_id, e.property_id)')))
            ->where('e.status', 'paid')->whereBetween('e.paid_on', [$from, $to])
            ->get(['e.paid_on as d', 'e.id as ref', 'e.description as label', 'e.amount_cents as cents']);
        $refunds = $booked('refunds', 'x')->where('x.status', 'executed')->whereBetween('x.executed_on', [$from, $to])
            ->get(['x.executed_on as d', 'x.id as ref', 'x.reason as label', 'x.amount_cents as cents']);

        $remitted = DB::table('parking_assignments as x')->join('parking_spaces as s', 's.id', '=', 'x.parking_space_id')
            ->tap(fn ($q) => AdminCategory::whereProperty($q, 's.property_id'))
            ->whereNotNull('x.remitted_on')->whereBetween('x.remitted_on', [$from, $to])
            ->get(['x.remitted_on as d', 'x.remit_reference as ref', 'x.id', 's.code', 'x.remit_to', 'x.remitted_cents as cents']);

        $rows = collect()
            ->concat($in->map(fn ($r) => ['date' => $r->d, 'type' => 'Receipt', 'ref' => $r->ref, 'label' => $r->label, 'in_cents' => (int) $r->cents, 'out_cents' => null]))
            ->concat($payouts->map(fn ($r) => ['date' => $r->d, 'type' => 'Channel payout', 'ref' => $r->ref, 'label' => $r->label, 'in_cents' => (int) $r->cents, 'out_cents' => null]))
            ->concat($out->map(fn ($r) => ['date' => $r->d, 'type' => 'Expense paid', 'ref' => 'EXP-'.$r->ref, 'label' => $r->label, 'in_cents' => null, 'out_cents' => (int) $r->cents]))
            ->concat($refunds->map(fn ($r) => ['date' => $r->d, 'type' => 'Refund', 'ref' => 'REF-'.$r->ref, 'label' => $r->label, 'in_cents' => null, 'out_cents' => (int) $r->cents]))
            ->concat($remitted->map(fn ($r) => ['date' => $r->d, 'type' => 'Parking remitted', 'ref' => $r->ref ?: 'PRK-'.$r->id, 'label' => "{$r->code} to ".($r->remit_to ?: 'stall owner'), 'in_cents' => null, 'out_cents' => (int) $r->cents]))
            ->sortBy('date')->values();

        return Inertia::render('Admin/Resource', [
            'title' => 'Cashbook',
            'subtitle' => "{$from} to {$to}. Money actually moved: verified receipts, received payouts, paid expenses, executed refunds and parking remitted to other condo owners.",
            'tabs' => self::TABS,
            'stats' => [
                ['label' => 'Money in', 'value' => $rows->sum('in_cents'), 'type' => 'money'],
                ['label' => 'Money out', 'value' => $rows->sum('out_cents'), 'type' => 'money'],
                ['label' => 'Net movement', 'value' => $rows->sum('in_cents') - $rows->sum('out_cents'), 'type' => 'money'],
            ],
            'dateRange' => ['from' => $from, 'to' => $to],
            'columns' => [
                ['key' => 'date', 'label' => 'Date', 'type' => 'date'], ['key' => 'type', 'label' => 'Type'], ['key' => 'ref', 'label' => 'Reference'], ['key' => 'label', 'label' => 'Detail'],
                ['key' => 'in_cents', 'label' => 'In', 'type' => 'money'], ['key' => 'out_cents', 'label' => 'Out', 'type' => 'money'],
            ],
            'rows' => $rows->map(fn ($r, $i) => $r + ['id' => $i]),
        ]);
    }
}
