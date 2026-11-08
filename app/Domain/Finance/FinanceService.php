<?php

namespace App\Domain\Finance;

use App\Domain\Availability\RoomConflict;
use App\Models\Booking;
use App\Models\Expense;
use App\Models\ExternalSettlement;
use App\Models\PeriodLock;
use App\Models\Receipt;
use App\Models\Refund;
use App\Support\Audit;
use App\Support\Local;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FinanceService
{
    private const OLD_RECEIPT_DAYS = 30;

    /** A receipt counts toward the balance only after verification; a screenshot is not cleared funds. */
    public function recordReceipt(array $data): Receipt
    {
        $receivedAt = CarbonImmutable::parse($data['received_at'], Local::tz());
        $this->assertOpen($receivedAt->toDateString());

        $receipt = Receipt::create([
            'reference' => 'RC-'.Local::now()->format('ym').'-'.Str::upper(Str::random(6)),
            'booking_id' => $data['booking_id'] ?? null,
            'received_at' => $receivedAt->utc(),
            'receipt_date' => $receivedAt->toDateString(),
            'amount_cents' => $data['amount_cents'],
            'method' => $data['method'],
            'payment_account_id' => $data['payment_account_id'] ?? null,
            'external_reference' => $data['external_reference'] ?? null,
            'evidence_path' => $data['evidence_path'] ?? null,
            'verification_status' => 'pending',
            'entered_by' => auth()->id(),
        ]);
        Audit::log('receipt.recorded', $receipt, ['amount_cents' => $receipt->amount_cents, 'method' => $receipt->method]);

        return $receipt;
    }

    /**
     * Things that are almost certainly a typing mistake, so nobody can save them. Keyed by form field.
     *
     * @return array<string, string>
     */
    public function receiptMistakes(int $cents, CarbonImmutable $receivedAt, string $method, ?string $reference, bool $hasProof): array
    {
        $mistakes = [];
        if ($cents <= 0) {
            $mistakes['amount'] = 'Enter an amount above zero.';
        }
        if ($receivedAt->isAfter(Local::now()->addMinutes(5))) {
            $mistakes['received_at'] = 'This date is in the future. Enter when the money actually came in.';
        }
        if (in_array($method, ['gcash', 'bank_transfer'], true) && blank($reference) && ! $hasProof) {
            $mistakes['external_reference'] = 'For GCash and bank payments, add the reference number or attach proof.';
        }

        return $mistakes;
    }

    /**
     * Things that can be genuine but are usually a mistake, so someone has to look twice. Keyed by form field.
     *
     * @return array<string, string>
     */
    public function receiptWarnings(?Booking $booking, int $cents, CarbonImmutable $receivedAt, ?string $reference): array
    {
        $warnings = [];
        $live = fn () => Receipt::where('verification_status', '!=', 'rejected')->whereNull('reverses_receipt_id')
            ->whereNotIn('id', Receipt::whereNotNull('reverses_receipt_id')->select('reverses_receipt_id'));

        if ($receivedAt->lt(Local::now()->subDays(self::OLD_RECEIPT_DAYS))) {
            $warnings['received_at'] = 'This date is more than '.self::OLD_RECEIPT_DAYS.' days ago. Please check the month and year.';
        }
        if (filled($reference) && ($same = $live()->where('external_reference', trim($reference))->first())) {
            $warnings['external_reference'] = "This reference is already on payment {$same->reference}.";
        }
        if ($booking) {
            $twin = $live()->where('booking_id', $booking->id)->where('amount_cents', $cents)->where('receipt_date', $receivedAt->toDateString())->first();
            $balance = $this->balance($booking);
            $left = max(0, $balance['balance_cents'] - $balance['pending_cents']);

            if ($twin) {
                $warnings['amount'] = 'The same amount was already recorded for this booking that day ('.$twin->reference.'). It may have been entered twice.';
            } elseif ($balance['charges_cents'] > 0 && $cents > $left) {
                $warnings['amount'] = 'This is '.Money::format($cents - $left).' more than the '.Money::format($left).' left to pay.';
            }
            if (in_array($booking->status, [Booking::CANCELLED, Booking::NO_SHOW, Booking::EXPIRED], true)) {
                $warnings['booking_reference'] = 'This booking is not active. Check that this is the right booking.';
            }
        }

        return $warnings;
    }

    public function review(Receipt $receipt, bool $verified, ?string $note = null): void
    {
        $this->assertOpen($receipt->receipt_date->toDateString());
        if ($receipt->verification_status !== 'pending') {
            throw new RoomConflict("Receipt is already {$receipt->verification_status}.");
        }
        $this->assertSecondPerson($receipt->entered_by, 'review a receipt you recorded');
        $receipt->update([
            'verification_status' => $verified ? 'verified' : 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);
        Audit::log('receipt.'.($verified ? 'verified' : 'rejected'), $receipt, ['note' => $note]);
    }

    /** Other receipts or payouts carrying the same reference, for the reconciliation queue. */
    public function duplicateReferences(): array
    {
        return Receipt::whereNotNull('external_reference')
            ->where('verification_status', '!=', 'rejected')
            ->selectRaw('external_reference, COUNT(*) as n')
            ->groupBy('external_reference')
            ->having('n', '>', 1)
            ->pluck('n', 'external_reference')
            ->all();
    }

    public function balance(Booking $booking): array
    {
        $charges = (int) $booking->activeCharges()->sum('amount_cents');
        $verified = (int) $booking->receipts()->where('verification_status', 'verified')->sum('amount_cents');
        $pending = (int) $booking->receipts()->where('verification_status', 'pending')->sum('amount_cents');
        $refunded = (int) $booking->refunds()->where('status', 'executed')->sum('amount_cents');
        // A settled channel booking was paid by the guest on the platform. Gross clears the guest
        // balance; fees and payout differences stay on the settlement, so cash is not double counted.
        $settled = (int) $booking->settlements()->whereNotNull('actual_payout_cents')->sum('gross_cents');

        $applied = $verified + $settled - $refunded;
        $due = $charges - $applied;

        return [
            'charges_cents' => $charges,
            'verified_cents' => $verified,
            'pending_cents' => $pending,
            'refunded_cents' => $refunded,
            'settled_gross_cents' => $settled,
            'balance_cents' => $due,
            'status' => $this->balanceStatus($charges, $applied, $pending, $refunded),
        ];
    }

    private function balanceStatus(int $charges, int $applied, int $pending, int $refunded): string
    {
        if ($refunded > 0 && $applied <= 0) {
            return 'Refunded';
        }
        if ($pending > 0 && $applied < $charges) {
            return 'Payment Under Review';
        }
        if ($applied > $charges) {
            return 'Overpaid';
        }
        if ($charges > 0 && $applied === $charges) {
            return 'Paid';
        }

        return $applied > 0 ? 'Partially Paid' : 'Unpaid';
    }

    public function requestRefund(Booking $booking, int $cents, string $reason, ?int $receiptId = null): Refund
    {
        return Refund::create([
            'booking_id' => $booking->id,
            'receipt_id' => $receiptId,
            'amount_cents' => $cents,
            'reason' => $reason,
            'status' => 'requested',
            'requested_by' => auth()->id(),
        ]);
    }

    /** Execution is a manual record of money already sent; nothing is paid out by the system. */
    public function decideRefund(Refund $refund, string $action, ?string $executedOn = null): void
    {
        $next = match ($action) {
            'approve' => $refund->status === 'requested' ? 'approved' : null,
            'reject' => $refund->status === 'requested' ? 'rejected' : null,
            'execute' => $refund->status === 'approved' ? 'executed' : null,
            default => null,
        };
        if (! $next) {
            throw new RoomConflict("Cannot {$action} a refund in status {$refund->status}.");
        }
        $changes = ['status' => $next];
        if ($action === 'approve') {
            $this->assertSecondPerson($refund->requested_by, 'approve a refund you requested');
            $changes['approved_by'] = auth()->id();
        }
        if ($action === 'execute') {
            $date = $executedOn ?? Local::today();
            $this->assertOpen($date);
            $changes['executed_on'] = $date;
        }
        $refund->update($changes);
        Audit::log('refund.'.$next, $refund, ['amount_cents' => $refund->amount_cents]);
    }

    public function recordSettlement(array $data): ExternalSettlement
    {
        if (! empty($data['payout_date'])) {
            $this->assertOpen($data['payout_date']);
        }
        if (! empty($data['payout_reference'])) {
            $dupe = ExternalSettlement::where('provider', $data['provider'])
                ->where('payout_reference', $data['payout_reference'])
                ->where('booking_id', $data['booking_id'] ?? null)
                ->exists();
            if ($dupe) {
                throw new RoomConflict('This payout reference is already recorded for this booking.');
            }
        }
        $gross = (int) $data['gross_cents'];
        $fees = (int) ($data['fees_cents'] ?? 0);
        $adj = (int) ($data['adjustments_cents'] ?? 0);

        $row = ExternalSettlement::create([
            'booking_id' => $data['booking_id'] ?? null,
            'provider' => $data['provider'],
            'payout_reference' => $data['payout_reference'] ?? null,
            'gross_cents' => $gross,
            'fees_cents' => $fees,
            'adjustments_cents' => $adj,
            'expected_net_cents' => $gross - $fees + $adj,
            'actual_payout_cents' => $data['actual_payout_cents'] ?? null,
            'payout_date' => $data['payout_date'] ?? null,
            'payment_account_id' => $data['payment_account_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => auth()->id(),
        ]);
        Audit::log('settlement.recorded', $row, ['expected_net_cents' => $row->expected_net_cents]);

        return $row;
    }

    public function recordPayout(ExternalSettlement $settlement, array $data): void
    {
        $this->assertOpen($data['payout_date']);
        if ($settlement->actual_payout_cents !== null) {
            throw new RoomConflict('This payout is already recorded.');
        }
        $settlement->update($data);
        Audit::log('settlement.payout_recorded', $settlement, ['actual_payout_cents' => $data['actual_payout_cents'], 'payout_date' => $data['payout_date']]);
    }

    /** A bill at or above the owner's threshold cannot count as cash out until someone approves it. */
    public function assertExpensePayable(int $cents, bool $approved): void
    {
        $threshold = Settings::get('finance.expense_approval_threshold_cents');
        if ($threshold !== null && $cents >= $threshold && ! $approved) {
            throw new RoomConflict('Expenses of '.Money::format((int) $threshold).' or more need approval before they are paid. Record it as incurred, then have it approved.');
        }
    }

    public function markExpensePaid(Expense $expense, array $data): void
    {
        if ($expense->status === 'paid') {
            throw new RoomConflict('This expense is already marked paid.');
        }
        $this->assertOpen($data['paid_on']);
        $this->assertExpensePayable((int) $expense->amount_cents, $expense->approved_at !== null);
        $expense->update(['status' => 'paid'] + $data);
        Audit::log('expense.paid', $expense);
    }

    public function approveExpense(Expense $expense): void
    {
        if ($expense->approved_at) {
            throw new RoomConflict('This expense is already approved.');
        }
        $this->assertSecondPerson($expense->created_by, 'approve an expense you recorded');
        $expense->update(['approved_by' => auth()->id(), 'approved_at' => now()]);
        Audit::log('expense.approved', $expense);
    }

    private function assertSecondPerson(?int $actorId, string $action): void
    {
        if ($actorId !== null && $actorId === auth()->id() && Settings::get('finance.second_reviewer')) {
            throw new RoomConflict("A second person is required: you cannot {$action}.");
        }
    }

    /** A closed month stays as closed: the mistake is corrected by a new entry dated today. */
    public function reverseExpense(Expense $expense, string $reason): Expense
    {
        if ($expense->reverses_expense_id || Expense::where('reverses_expense_id', $expense->id)->exists()) {
            throw new RoomConflict('This expense has already been reversed, or is itself a reversal.');
        }
        $today = Local::today();
        $this->assertOpen($today);

        $reversal = $expense->replicate(['proof_path', 'approved_by', 'approved_at']);
        $reversal->fill([
            'amount_cents' => -(int) $expense->amount_cents,
            'description' => 'Reversal: '.$expense->description,
            'expense_date' => $today,
            'paid_on' => $expense->status === 'paid' ? $today : null,
            'reverses_expense_id' => $expense->id,
            'reversal_reason' => $reason,
            'created_by' => auth()->id(),
        ])->save();
        Audit::log('expense.reversed', $reversal, ['reverses' => $expense->id, 'reason' => $reason]);

        return $reversal;
    }

    public function reverseReceipt(Receipt $receipt, string $reason): Receipt
    {
        if ($receipt->verification_status !== 'verified') {
            throw new RoomConflict('Only a verified receipt can be reversed. Reject it instead.');
        }
        if ($receipt->reverses_receipt_id || Receipt::where('reverses_receipt_id', $receipt->id)->exists()) {
            throw new RoomConflict('This receipt has already been reversed, or is itself a reversal.');
        }
        $today = Local::today();
        $this->assertOpen($today);

        $reversal = Receipt::create([
            'reference' => 'RC-'.Local::now()->format('ym').'-'.Str::upper(Str::random(6)),
            'booking_id' => $receipt->booking_id,
            'received_at' => now(),
            'receipt_date' => $today,
            'amount_cents' => -(int) $receipt->amount_cents,
            'method' => $receipt->method,
            'payment_account_id' => $receipt->payment_account_id,
            'verification_status' => 'verified',
            'entered_by' => auth()->id(),
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_note' => "Reversal of {$receipt->reference}: {$reason}",
            'reverses_receipt_id' => $receipt->id,
        ]);
        Audit::log('receipt.reversed', $reversal, ['reverses' => $receipt->reference, 'reason' => $reason]);

        return $reversal;
    }

    /** Everything open for the reconciliation screen and the month-end notice. */
    /** @param  string|null  $category  suites or cameras for the admin's selected business; null for both (month-end). */
    public function reconciliationExceptions(?string $category = null): Collection
    {
        $exceptions = collect();
        // A payment or payout with no booking belongs to Suites, as everywhere else.
        $in = function ($query) use ($category) {
            if ($category === null) {
                return $query;
            }
            $ids = \App\Models\Property::where('category', $category)->pluck('id');

            return $query->where(fn ($w) => $w->whereHas('booking', fn ($b) => $b->whereIn('property_id', $ids))
                ->when($category === \App\Models\Property::SUITES, fn ($o) => $o->orWhereNull('booking_id')));
        };

        $in(Receipt::where('verification_status', 'pending'))->get()
            ->each(fn ($r) => $exceptions->push(['type' => 'Pending verification', 'detail' => "{$r->reference}, ".Money::format((int) $r->amount_cents), 'href' => '/admin/payments?status=pending']));
        $in(Receipt::whereNull('booking_id'))->whereNull('reverses_receipt_id')->where('verification_status', '!=', 'rejected')->get()
            ->each(fn ($r) => $exceptions->push(['type' => 'Unmatched receipt', 'detail' => "{$r->reference}, ".Money::format((int) $r->amount_cents), 'href' => '/admin/payments']));
        $in(Receipt::whereNull('evidence_path'))->whereNull('reverses_receipt_id')->where('method', '!=', 'cash')->where('verification_status', '!=', 'rejected')->get()
            ->each(fn ($r) => $exceptions->push(['type' => 'Missing proof', 'detail' => "{$r->reference}, {$r->method}", 'href' => '/admin/payments']));
        foreach ($this->duplicateReferences() as $ref => $n) {
            $exceptions->push(['type' => 'Duplicate reference', 'detail' => "{$ref} appears {$n} times", 'href' => '/admin/payments']);
        }
        $in(ExternalSettlement::whereNull('actual_payout_cents'))->get()
            ->each(fn ($s) => $exceptions->push(['type' => 'Payout not received', 'detail' => "{$s->provider}, expected ".Money::format((int) $s->expected_net_cents), 'href' => '/admin/settlements']));
        $in(ExternalSettlement::whereNotNull('actual_payout_cents'))->whereColumn('actual_payout_cents', '!=', 'expected_net_cents')->get()
            ->each(fn ($s) => $exceptions->push(['type' => 'Payout mismatch', 'detail' => $s->provider.', '.Money::format((int) $s->actual_payout_cents - (int) $s->expected_net_cents).' difference', 'href' => '/admin/settlements']));
        $in(ExternalSettlement::whereNull('booking_id'))->get()
            ->each(fn ($s) => $exceptions->push(['type' => 'Unlinked payout', 'detail' => "{$s->provider} {$s->payout_reference}", 'href' => '/admin/settlements']));

        return $exceptions;
    }

    /** Closed months accept no silent edits; corrections are dated in an open period. */
    public function assertOpen(string $date): void
    {
        $period = substr($date, 0, 7);
        if (PeriodLock::where('period', $period)->exists()) {
            throw new RoomConflict("{$period} is locked. Record an adjustment dated in an open period.");
        }
    }

    /** Checks every month holding a night of this stay. The check-out date is not a night. */
    public function assertStayOpen(string $checkIn, string $checkOut): void
    {
        $months = [];
        $lastNight = CarbonImmutable::parse($checkOut)->subDay();
        for ($month = CarbonImmutable::parse($checkIn)->startOfMonth(); $month->lte($lastNight); $month = $month->addMonth()) {
            $months[] = $month->format('Y-m');
        }
        $locked = PeriodLock::whereIn('period', $months)->orderBy('period')->value('period');
        if ($locked) {
            throw new RoomConflict("This stay has nights in {$locked}, which is locked. Leave the booking as it is and add an adjustment dated in an open period.");
        }
    }

    public function lockPeriod(string $period, ?string $notes = null): PeriodLock
    {
        if (PeriodLock::where('period', $period)->exists()) {
            throw new RoomConflict("{$period} is already locked.");
        }
        $lock = PeriodLock::create([
            'period' => $period,
            'locked_by' => auth()->id(),
            'locked_at' => now(),
            'notes' => $notes,
        ]);
        Audit::log('period.locked', $lock, ['period' => $period]);

        return $lock;
    }
}
