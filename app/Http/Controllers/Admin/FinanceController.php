<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Finance\FinanceService;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ExternalSettlement;
use App\Models\PaymentAccount;
use App\Models\PeriodLock;
use App\Models\Receipt;
use App\Models\Refund;
use App\Support\Audit;
use App\Support\Local;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class FinanceController extends Controller
{
    private const TABS = [
        ['label' => 'Receipts', 'href' => '/admin/payments'],
        ['label' => 'Channel settlements', 'href' => '/admin/settlements'],
        ['label' => 'Reconciliation', 'href' => '/admin/reconciliation'],
    ];

    public function __construct(private FinanceService $finance)
    {
    }

    public function receipts(Request $request)
    {
        $status = $request->validate(['status' => 'nullable|in:pending,verified,rejected'])['status'] ?? null;
        $user = $request->user();
        $rows = Receipt::with(['booking:id,reference', 'account:id,name', 'enteredBy:id,name'])
            ->when($status, fn ($q, $v) => $q->where('verification_status', $v))
            ->orderByDesc('id')->paginate(15)->withQueryString();
        $reversed = Receipt::whereNotNull('reverses_receipt_id')->pluck('reverses_receipt_id');

        return Inertia::render('Admin/Resource', [
            'title' => 'Payments',
            'subtitle' => 'A recorded payment counts toward the balance only after it is verified. A screenshot is not proof of cleared funds.',
            'tabs' => self::TABS,
            'filters' => [['name' => 'status', 'label' => 'Status', 'value' => $status, 'options' => $this->opts(['pending', 'verified', 'rejected'])]],
            'columns' => [
                ['key' => 'reference', 'label' => 'Reference'],
                ['key' => 'booking', 'label' => 'Booking', 'type' => 'link'],
                ['key' => 'receipt_date', 'label' => 'Date', 'type' => 'date'],
                ['key' => 'method', 'label' => 'Method'],
                ['key' => 'account', 'label' => 'Account'],
                ['key' => 'external_reference', 'label' => 'Ext. ref'],
                ['key' => 'amount_cents', 'label' => 'Amount', 'type' => 'money'],
                ['key' => 'evidence', 'label' => 'Evidence', 'type' => 'link'],
                ['key' => 'verification_status', 'label' => 'Status', 'type' => 'status'],
            ],
            'rows' => $rows->through(fn ($r) => [
                'id' => $r->id,
                'reference' => $r->reference,
                'booking' => $r->booking ? ['label' => $r->booking->reference, 'href' => "/admin/bookings/{$r->booking_id}"] : ['label' => 'Unlinked'],
                'receipt_date' => $r->receipt_date->toDateString(),
                'method' => $r->method,
                'account' => $r->account?->name,
                'external_reference' => $r->external_reference,
                'amount_cents' => (int) $r->amount_cents,
                'evidence' => $r->evidence_path ? ['label' => 'View', 'href' => "/admin/files/receipt/{$r->id}", 'external' => true] : ['label' => 'None'],
                'verification_status' => $r->verification_status,
                '_actions' => match (true) {
                    ! $user->can('payments.verify') => [],
                    $r->verification_status === 'pending' => [
                        ['label' => 'Verify', 'url' => "/admin/receipts/{$r->id}/review", 'data' => ['decision' => 'verified'], 'confirm' => 'Confirm these funds were actually received?', 'variant' => 'primary'],
                        ['label' => 'Reject', 'url' => "/admin/receipts/{$r->id}/review", 'data' => ['decision' => 'rejected'], 'fields' => [['name' => 'note', 'label' => 'Reason', 'type' => 'text', 'required' => true]], 'variant' => 'danger'],
                    ],
                    $r->verification_status === 'verified' && ! $r->reverses_receipt_id && ! $reversed->contains($r->id) => [
                        ['label' => 'Reverse', 'url' => "/admin/receipts/{$r->id}/reverse", 'variant' => 'danger', 'confirm' => 'This records a new negative receipt dated today. The original stays on file. Continue?', 'fields' => [['name' => 'reason', 'label' => 'Why is this being reversed?', 'type' => 'text', 'required' => true]]],
                    ],
                    default => [],
                },
            ]),
            'create' => $user->can('payments.record') ? ['label' => 'Record payment', 'url' => '/admin/receipts', 'fields' => $this->receiptFields()] : null,
        ]);
    }

    public function storeReceipt(Request $request)
    {
        $data = $request->validate([
            'booking_reference' => 'nullable|exists:bookings,reference',
            'received_at' => 'required|date',
            'amount' => ['required', Money::RULE],
            'method' => 'required|in:cash,gcash,bank_transfer,other',
            'payment_account_id' => 'nullable|exists:payment_accounts,id',
            'external_reference' => 'nullable|string|max:120',
            'evidence' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
            'confirm_unusual' => 'nullable|boolean',
        ]);
        $cents = Money::cents($data['amount']);
        $receivedAt = CarbonImmutable::parse($data['received_at'], Local::tz());
        $reference = isset($data['external_reference']) ? trim($data['external_reference']) : null;

        $mistakes = $this->finance->receiptMistakes($cents, $receivedAt, $data['method'], $reference, $request->hasFile('evidence'));
        if ($mistakes) {
            throw ValidationException::withMessages($mistakes);
        }

        // The booking row is locked, so two quick clicks are checked one after the other and the second sees the first.
        DB::transaction(function () use ($request, $data, $cents, $receivedAt, $reference) {
            $booking = ! empty($data['booking_reference']) ? Booking::where('reference', $data['booking_reference'])->lockForUpdate()->first() : null;

            $warnings = $this->finance->receiptWarnings($booking, $cents, $receivedAt, $reference);
            if ($warnings && ! $request->boolean('confirm_unusual')) {
                throw ValidationException::withMessages($warnings + ['confirm_unusual' => 'Please check the warning. If the payment is correct, tick this box and save again.']);
            }

            $receipt = $this->finance->recordReceipt([
                'booking_id' => $booking?->id,
                'received_at' => $data['received_at'],
                'amount_cents' => $cents,
                'method' => $data['method'],
                'payment_account_id' => $data['payment_account_id'] ?? null,
                'external_reference' => $reference ?: null,
                'evidence_path' => $request->hasFile('evidence') ? $request->file('evidence')->store('evidence/receipts', 'local') : null,
            ]);
            if ($warnings) {
                Audit::log('receipt.warning_confirmed', $receipt, ['warnings' => array_values($warnings)]);
            }
        });

        return back()->with('success', 'Payment recorded. It counts toward the balance once it is verified.');
    }

    public function reviewReceipt(Request $request, Receipt $receipt)
    {
        $data = $request->validate(['decision' => 'required|in:verified,rejected', 'note' => 'required_if:decision,rejected|nullable|string|max:255']);
        $this->finance->review($receipt, $data['decision'] === 'verified', $data['note'] ?? null);

        return back()->with('success', "Receipt {$data['decision']}.");
    }

    public function reverseReceipt(Request $request, Receipt $receipt)
    {
        $this->finance->reverseReceipt($receipt, $request->validate(['reason' => 'required|string|max:200'])['reason']);

        return back()->with('success', 'Reversal recorded, dated today. The original receipt is unchanged.');
    }

    public function storeRefund(Request $request)
    {
        $data = $request->validate(['booking_id' => 'required|exists:bookings,id', 'amount' => ['required', Money::RULE], 'reason' => 'required|string|max:255']);
        $this->finance->requestRefund(Booking::findOrFail($data['booking_id']), Money::cents($data['amount']), $data['reason']);

        return back()->with('success', 'Refund requested. It needs owner approval; nothing has been paid.');
    }

    public function decideRefund(Request $request, Refund $refund)
    {
        $data = $request->validate(['action' => 'required|in:approve,reject,execute', 'executed_on' => 'nullable|date_format:Y-m-d']);
        $this->finance->decideRefund($refund, $data['action'], $data['executed_on'] ?? null);

        return back()->with('success', 'Refund updated.');
    }

    public function settlements(Request $request)
    {
        $user = $request->user();
        $rows = ExternalSettlement::with(['booking:id,reference', 'account:id,name'])->orderByDesc('id')->paginate(15);

        return Inertia::render('Admin/Resource', [
            'title' => 'Channel settlements',
            'subtitle' => 'Platform gross, fees and payouts stay separate. A paid Airbnb guest is not cash received until the payout lands.',
            'tabs' => self::TABS,
            'columns' => [
                ['key' => 'provider', 'label' => 'Provider'],
                ['key' => 'booking', 'label' => 'Booking', 'type' => 'link'],
                ['key' => 'payout_reference', 'label' => 'Payout ref'],
                ['key' => 'gross_cents', 'label' => 'Gross', 'type' => 'money'],
                ['key' => 'fees_cents', 'label' => 'Fees', 'type' => 'money'],
                ['key' => 'expected_net_cents', 'label' => 'Expected', 'type' => 'money'],
                ['key' => 'actual_payout_cents', 'label' => 'Actual', 'type' => 'money'],
                ['key' => 'difference_cents', 'label' => 'Difference', 'type' => 'money'],
                ['key' => 'payout_date', 'label' => 'Payout date', 'type' => 'date'],
            ],
            'rows' => $rows->through(fn ($s) => [
                'id' => $s->id,
                'provider' => $s->provider,
                'booking' => $s->booking ? ['label' => $s->booking->reference, 'href' => "/admin/bookings/{$s->booking_id}"] : ['label' => 'Unlinked'],
                'payout_reference' => $s->payout_reference,
                'gross_cents' => (int) $s->gross_cents,
                'fees_cents' => (int) $s->fees_cents,
                'expected_net_cents' => (int) $s->expected_net_cents,
                'actual_payout_cents' => $s->actual_payout_cents === null ? null : (int) $s->actual_payout_cents,
                'difference_cents' => $s->actual_payout_cents === null ? null : (int) $s->actual_payout_cents - (int) $s->expected_net_cents,
                'payout_date' => $s->payout_date?->toDateString(),
                '_actions' => $user->can('settlements.manage') && $s->actual_payout_cents === null ? [[
                    'label' => 'Record payout',
                    'url' => "/admin/settlements/{$s->id}/payout",
                    'fields' => [
                        ['name' => 'actual_payout', 'label' => 'Amount received (PHP)', 'type' => 'money', 'required' => true],
                        ['name' => 'payout_date', 'label' => 'Payout date', 'type' => 'date', 'required' => true],
                        ['name' => 'payment_account_id', 'label' => 'Receiving account', 'type' => 'select', 'options' => $this->accounts()],
                    ],
                ]] : [],
            ]),
            'create' => $user->can('settlements.manage') ? ['label' => 'Add settlement', 'url' => '/admin/settlements', 'fields' => [
                ['name' => 'provider', 'label' => 'Provider', 'type' => 'text', 'required' => true, 'default' => 'Airbnb'],
                ['name' => 'booking_reference', 'label' => 'Booking reference', 'type' => 'text'],
                ['name' => 'payout_reference', 'label' => 'Payout reference', 'type' => 'text'],
                ['name' => 'gross', 'label' => 'Platform gross (PHP)', 'type' => 'money', 'required' => true],
                ['name' => 'fees', 'label' => 'Fees and commission (PHP)', 'type' => 'money'],
                ['name' => 'adjustments', 'label' => 'Adjustments (PHP)', 'type' => 'money'],
                ['name' => 'notes', 'label' => 'Notes', 'type' => 'text'],
            ]] : null,
        ]);
    }

    public function storeSettlement(Request $request)
    {
        $data = $request->validate([
            'provider' => 'required|string|max:60',
            'booking_reference' => 'nullable|exists:bookings,reference',
            'payout_reference' => 'nullable|string|max:120',
            'gross' => ['required', Money::RULE],
            'fees' => ['nullable', Money::RULE],
            'adjustments' => ['nullable', Money::RULE],
            'notes' => 'nullable|string|max:255',
        ]);
        $this->finance->recordSettlement([
            'provider' => $data['provider'],
            'booking_id' => ! empty($data['booking_reference']) ? Booking::where('reference', $data['booking_reference'])->value('id') : null,
            'payout_reference' => $data['payout_reference'] ?? null,
            'gross_cents' => Money::cents($data['gross']),
            'fees_cents' => Money::cents($data['fees'] ?? null) ?? 0,
            'adjustments_cents' => Money::cents($data['adjustments'] ?? null) ?? 0,
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Settlement added.');
    }

    public function recordPayout(Request $request, ExternalSettlement $settlement)
    {
        $data = $request->validate(['actual_payout' => ['required', Money::RULE], 'payout_date' => 'required|date_format:Y-m-d', 'payment_account_id' => 'nullable|exists:payment_accounts,id']);
        $this->finance->recordPayout($settlement, [
            'actual_payout_cents' => Money::cents($data['actual_payout']),
            'payout_date' => $data['payout_date'],
            'payment_account_id' => $data['payment_account_id'] ?? null,
        ]);

        return back()->with('success', 'Payout recorded.');
    }

    public function reconciliation(Request $request)
    {
        $user = $request->user();
        $exceptions = $this->finance->reconciliationExceptions();

        $locks =PeriodLock::with('locker:id,name')->orderByDesc('period')->limit(24)->get();

        return Inertia::render('Admin/Resource', [
            'title' => 'Reconciliation',
            'subtitle' => $exceptions->count().' open exception(s). Locked months below accept no silent edits.',
            'tabs' => self::TABS,
            'columns' => [['key' => 'type', 'label' => 'Exception', 'type' => 'status'], ['key' => 'detail', 'label' => 'Detail'], ['key' => 'link', 'label' => 'Open', 'type' => 'link']],
            'rows' => $exceptions->values()->map(fn ($e, $i) => ['id' => $i, 'type' => $e['type'], 'detail' => $e['detail'], 'link' => ['label' => 'Review', 'href' => $e['href']]]),
            'note' => $locks->isEmpty() ? 'No periods are locked.' : 'Locked: '.$locks->map(fn ($l) => "{$l->period} by {$l->locker->name}")->join(', '),
            'create' => $user->can('periods.lock') ? ['label' => 'Lock a month', 'url' => '/admin/periods/lock', 'fields' => [
                ['name' => 'period', 'label' => 'Month (YYYY-MM)', 'type' => 'text', 'required' => true, 'default' => Local::now()->subMonthNoOverflow()->format('Y-m')],
                ['name' => 'notes', 'label' => 'Notes', 'type' => 'text'],
            ], 'confirm' => 'Lock this month? Later corrections must be new adjustments dated in an open period.'] : null,
        ]);
    }

    public function lockPeriod(Request $request)
    {
        $data = $request->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'notes' => 'nullable|string|max:500']);
        $this->finance->lockPeriod($data['period'], $data['notes'] ?? null);

        return back()->with('success', "{$data['period']} locked.");
    }

    private function receiptFields(): array
    {
        return [
            ['name' => 'booking_reference', 'label' => 'Booking reference', 'type' => 'text', 'help' => 'Leave blank if unmatched; it will appear on the reconciliation list.'],
            ['name' => 'received_at', 'label' => 'Received at', 'type' => 'datetime', 'required' => true, 'default' => Local::now()->format('Y-m-d\TH:i')],
            ['name' => 'amount', 'label' => 'Amount (PHP)', 'type' => 'money', 'required' => true],
            ['name' => 'method', 'label' => 'Method', 'type' => 'select', 'required' => true, 'options' => $this->opts(['cash', 'gcash', 'bank_transfer', 'other'])],
            ['name' => 'payment_account_id', 'label' => 'Receiving account', 'type' => 'select', 'options' => $this->accounts()],
            ['name' => 'external_reference', 'label' => 'GCash or bank reference', 'type' => 'text'],
            ['name' => 'evidence', 'label' => 'Proof (image or PDF)', 'type' => 'file'],
            ['name' => 'confirm_unusual', 'label' => 'If a warning appears: I checked it, and this payment is correct', 'type' => 'checkbox'],
        ];
    }

    private function accounts(): array
    {
        return PaymentAccount::where('is_active', true)->get()->map(fn ($a) => ['value' => $a->id, 'label' => $a->name])->all();
    }

    private function opts(array $values): array
    {
        return array_map(fn ($v) => ['value' => $v, 'label' => ucfirst(str_replace('_', ' ', $v))], $values);
    }
}
