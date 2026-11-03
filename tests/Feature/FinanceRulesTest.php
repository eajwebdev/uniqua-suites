<?php

namespace Tests\Feature;

use App\Domain\Availability\RoomConflict;
use App\Domain\Finance\FinanceService;
use App\Domain\Reporting\ReportService;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Receipt;
use Tests\DomainTestCase;

class FinanceRulesTest extends DomainTestCase
{
    private FinanceService $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finance = app(FinanceService::class);
    }

    private function receipt($booking, int $cents, string $method = 'gcash', ?string $ref = null, bool $verify = true, string $when = '2031-03-01 10:00:00'): Receipt
    {
        $r = $this->finance->recordReceipt(['booking_id' => $booking->id, 'received_at' => $when, 'amount_cents' => $cents, 'method' => $method, 'external_reference' => $ref]);
        if ($verify) {
            $this->finance->review($r, true);
        }

        return $r;
    }

    public function test_gcash_payment_is_separate_from_facebook_booking_source(): void
    {
        $booking = $this->confirmed();
        $receipt = $this->receipt($booking, 100000);

        $this->assertSame('gcash', $receipt->method);
        $this->assertSame('facebook_messenger', $booking->source->code);
    }

    public function test_balance_lifecycle_partial_review_paid_overpaid_refunded(): void
    {
        $booking = $this->confirmed(); // 3,400.00
        $this->assertSame('Unpaid', $this->finance->balance($booking)['status']);

        $pending = $this->receipt($booking, 100000, verify: false);
        $balance = $this->finance->balance($booking);
        $this->assertSame('Payment Under Review', $balance['status']);
        $this->assertSame(340000, $balance['balance_cents'], 'unverified money never reduces the balance');

        $this->finance->review($pending, true);
        $this->assertSame('Partially Paid', $this->finance->balance($booking)['status']);

        $this->receipt($booking, 240000);
        $this->assertSame('Paid', $this->finance->balance($booking)['status']);

        $this->receipt($booking, 50000);
        $over = $this->finance->balance($booking);
        $this->assertSame('Overpaid', $over['status']);
        $this->assertSame(-50000, $over['balance_cents']);

        $refund = $this->finance->requestRefund($booking, 50000, 'Overpayment');
        $this->assertSame(-50000, $this->finance->balance($booking)['balance_cents'], 'a requested refund changes nothing');
        $this->finance->decideRefund($refund, 'approve');
        $this->finance->decideRefund($refund, 'execute', '2031-03-02');
        $this->assertSame(0, $this->finance->balance($booking)['balance_cents']);
    }

    public function test_rejected_receipt_does_not_count(): void
    {
        $booking = $this->confirmed();
        $r = $this->receipt($booking, 100000, verify: false);
        $this->finance->review($r, false, 'Screenshot only');
        $this->assertSame(0, $this->finance->balance($booking)['verified_cents']);
        $this->assertSame('Unpaid', $this->finance->balance($booking)['status']);
    }

    public function test_airbnb_payout_is_separate_from_gross_and_not_double_counted(): void
    {
        $booking = $this->confirmed(source: 'airbnb'); // gross 3,400
        $s = $this->finance->recordSettlement(['booking_id' => $booking->id, 'provider' => 'Airbnb', 'gross_cents' => 340000, 'fees_cents' => 51000]);
        $this->assertSame(289000, (int) $s->expected_net_cents);
        $this->assertSame('Unpaid', $this->finance->balance($booking)['status'], 'no payout yet');

        $s->update(['actual_payout_cents' => 285000, 'payout_date' => '2031-04-02']);
        $this->assertSame('Paid', $this->finance->balance($booking)['status']);

        $march = app(ReportService::class)->kpis('2031-03-01', '2031-03-31');
        $april = app(ReportService::class)->kpis('2031-04-01', '2031-04-30');
        $this->assertSame(340000, $march['earned_cents']);
        $this->assertSame(0, $march['collected_cents'], 'stay in March, cash in April');
        $this->assertSame(285000, $april['collected_cents']);
        $this->assertSame(0, $april['earned_cents']);
    }

    public function test_duplicate_payout_and_receipt_references_are_detected(): void
    {
        $booking = $this->confirmed(source: 'airbnb');
        $this->finance->recordSettlement(['booking_id' => $booking->id, 'provider' => 'Airbnb', 'gross_cents' => 100, 'payout_reference' => 'P1']);
        try {
            $this->finance->recordSettlement(['booking_id' => $booking->id, 'provider' => 'Airbnb', 'gross_cents' => 100, 'payout_reference' => 'P1']);
            $this->fail('Duplicate payout accepted');
        } catch (RoomConflict) {
            $this->assertTrue(true);
        }

        $this->receipt($booking, 100, ref: 'GC1');
        $this->receipt($booking, 100, ref: 'GC1');
        $this->assertSame(['GC1' => 2], $this->finance->duplicateReferences());
    }

    public function test_expenses_flow_to_reports_and_unpaid_is_not_cash_paid(): void
    {
        Expense::create(['expense_category_id' => ExpenseCategory::first()->id, 'room_id' => $this->room('bali')->id, 'description' => 'Paid', 'amount_cents' => 10000, 'expense_date' => '2031-03-02', 'status' => 'paid', 'paid_on' => '2031-03-02']);
        Expense::create(['expense_category_id' => ExpenseCategory::first()->id, 'room_id' => $this->room('bali')->id, 'description' => 'Unpaid', 'amount_cents' => 5000, 'expense_date' => '2031-03-02', 'status' => 'incurred']);

        $kpi = app(ReportService::class)->kpis('2031-03-01', '2031-03-31', $this->room('bali')->property_id);
        $this->assertSame(15000, $kpi['expenses_cents']);
        $this->assertSame(5000, $kpi['expenses_unpaid_cents']);
        $this->assertSame(-15000, $kpi['contribution_cents']);
    }

    public function test_occupancy_adr_and_revpar_use_room_nights_and_exclude_blocks(): void
    {
        $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $this->room('bali')->blocks()->create(['start_date' => '2031-03-01', 'end_date' => '2031-03-11', 'kind' => 'repair', 'reason' => 'Test']);

        $kpi = app(ReportService::class)->kpis('2031-03-01', '2031-03-10');
        $this->assertSame(80, $kpi['available_room_nights']); // 9 rooms x 10 days - 10 blocked
        $this->assertSame(2, $kpi['occupied_room_nights']);
        $this->assertSame(2.5, $kpi['occupancy_pct']);
        $this->assertSame(170000, $kpi['adr_cents']);
        $this->assertSame(4250, $kpi['revpar_cents']);
    }

    public function test_locked_period_rejects_new_entries(): void
    {
        $booking = $this->confirmed();
        $this->finance->lockPeriod('2031-03');

        $this->expectException(RoomConflict::class);
        $this->receipt($booking, 100, when: '2031-03-15 10:00:00');
    }
}
