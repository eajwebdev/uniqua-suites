<?php

namespace App\Http\Controllers\Admin;

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
        $rows = Expense::with(['category:id,name', 'room:id,name', 'property:id,name', 'vendor:id,name'])
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
                ['name' => 'room_id', 'label' => 'Room', 'type' => 'select', 'options' => Room::orderBy('id')->get()->map(fn ($r) => ['value' => $r->id, 'label' => $r->name])->all(), 'help' => 'Leave room and property blank for a general expense.'],
                ['name' => 'property_id', 'label' => 'Property', 'type' => 'select', 'options' => Property::orderBy('id')->get()->map(fn ($p) => ['value' => $p->id, 'label' => $p->name])->all()],
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

        $in = DB::table('receipts')->where('verification_status', 'verified')->whereBetween('receipt_date', [$from, $to])
            ->get(['receipt_date as d', 'reference as ref', 'method as label', 'amount_cents as cents']);
        $payouts = DB::table('external_settlements')->whereNotNull('actual_payout_cents')->whereBetween('payout_date', [$from, $to])
            ->get(['payout_date as d', 'payout_reference as ref', 'provider as label', 'actual_payout_cents as cents']);
        $out = DB::table('expenses')->where('status', 'paid')->whereBetween('paid_on', [$from, $to])
            ->get(['paid_on as d', 'id as ref', 'description as label', 'amount_cents as cents']);
        $refunds = DB::table('refunds')->where('status', 'executed')->whereBetween('executed_on', [$from, $to])
            ->get(['executed_on as d', 'id as ref', 'reason as label', 'amount_cents as cents']);

        $rows = collect()
            ->concat($in->map(fn ($r) => ['date' => $r->d, 'type' => 'Receipt', 'ref' => $r->ref, 'label' => $r->label, 'in_cents' => (int) $r->cents, 'out_cents' => null]))
            ->concat($payouts->map(fn ($r) => ['date' => $r->d, 'type' => 'Channel payout', 'ref' => $r->ref, 'label' => $r->label, 'in_cents' => (int) $r->cents, 'out_cents' => null]))
            ->concat($out->map(fn ($r) => ['date' => $r->d, 'type' => 'Expense paid', 'ref' => 'EXP-'.$r->ref, 'label' => $r->label, 'in_cents' => null, 'out_cents' => (int) $r->cents]))
            ->concat($refunds->map(fn ($r) => ['date' => $r->d, 'type' => 'Refund', 'ref' => 'REF-'.$r->ref, 'label' => $r->label, 'in_cents' => null, 'out_cents' => (int) $r->cents]))
            ->sortBy('date')->values();

        return Inertia::render('Admin/Resource', [
            'title' => 'Cashbook',
            'subtitle' => "{$from} to {$to}. Money actually moved: verified receipts, received payouts, paid expenses and executed refunds.",
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
