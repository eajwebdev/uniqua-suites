<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExternalSettlement;
use App\Models\PeriodLock;
use App\Models\Receipt;
use App\Models\Refund;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Tests\DomainTestCase;

class OperationalControlsTest extends DomainTestCase
{
    private function secondOwner(): User
    {
        $user = User::factory()->create();
        \App\Support\Permissions::apply($user, 'Owner');

        return $user;
    }

    private function postExpense(array $overrides = [])
    {
        return $this->post('/admin/expenses', $overrides + [
            'expense_date' => '2031-03-10',
            'expense_category_id' => ExpenseCategory::where('name', 'Repairs & Maintenance')->value('id'),
            'description' => 'Synthetic repair',
            'amount' => '5000.00',
            'status' => 'incurred',
        ]);
    }

    public function test_expense_threshold_is_off_until_the_owner_sets_it(): void
    {
        $this->postExpense(['status' => 'paid'])->assertSessionHasNoErrors()->assertSessionMissing('error');

        $this->assertSame('paid', Expense::first()->status);
    }

    public function test_an_expense_over_the_threshold_cannot_be_paid_until_approved(): void
    {
        Settings::set('finance.expense_approval_threshold_cents', 500000);

        $this->postExpense(['status' => 'paid'])->assertSessionHas('error');
        $this->assertSame(0, Expense::count());

        $this->postExpense()->assertSessionMissing('error');
        $expense = Expense::first();

        $this->post("/admin/expenses/{$expense->id}/paid", ['paid_on' => '2031-03-11'])->assertSessionHas('error');
        $this->assertSame('incurred', $expense->fresh()->status);

        $this->post("/admin/expenses/{$expense->id}/approve")->assertSessionMissing('error');
        $this->post("/admin/expenses/{$expense->id}/paid", ['paid_on' => '2031-03-11'])->assertSessionMissing('error');
        $this->assertSame('paid', $expense->fresh()->status);
    }

    public function test_an_expense_under_the_threshold_needs_no_approval(): void
    {
        Settings::set('finance.expense_approval_threshold_cents', 500000);

        $this->postExpense(['amount' => '4999.99', 'status' => 'paid'])->assertSessionMissing('error');

        $this->assertSame('paid', Expense::first()->status);
    }

    public function test_a_paid_expense_cannot_be_moved_out_of_a_locked_month(): void
    {
        $this->postExpense(['status' => 'paid']);
        $expense = Expense::first();
        PeriodLock::create(['period' => '2031-03', 'locked_by' => $this->owner->id, 'locked_at' => now()]);

        $this->post("/admin/expenses/{$expense->id}/paid", ['paid_on' => '2031-04-02'])->assertSessionHas('error');

        $this->assertSame('2031-03-10', $expense->fresh()->paid_on->toDateString());
    }

    public function test_second_reviewer_blocks_self_review_only_when_turned_on(): void
    {
        $booking = $this->confirmed();
        $receiptData = ['booking_reference' => $booking->reference, 'received_at' => \App\Support\Local::now()->format('Y-m-d\TH:i'), 'amount' => '100.00', 'method' => 'cash', 'confirm_unusual' => true];

        $this->post('/admin/receipts', $receiptData);
        $this->post('/admin/receipts/'.Receipt::latest('id')->first()->id.'/review', ['decision' => 'verified'])->assertSessionMissing('error');
        $this->assertSame('verified', Receipt::latest('id')->first()->verification_status);

        Settings::set('finance.second_reviewer', true);

        $this->post('/admin/receipts', $receiptData);
        $receipt = Receipt::latest('id')->first();
        $this->post("/admin/receipts/{$receipt->id}/review", ['decision' => 'verified'])->assertSessionHas('error');
        $this->assertSame('pending', $receipt->fresh()->verification_status);

        $this->actingAs($this->secondOwner());
        $this->flushSession();
        $this->post("/admin/receipts/{$receipt->id}/review", ['decision' => 'verified'])->assertSessionMissing('error');
        $this->assertSame('verified', $receipt->fresh()->verification_status);
    }

    public function test_second_reviewer_applies_to_refunds_and_expenses(): void
    {
        Settings::set('finance.second_reviewer', true);
        $booking = $this->confirmed();

        $this->post('/admin/refunds', ['booking_id' => $booking->id, 'amount' => '100.00', 'reason' => 'Synthetic']);
        $refund = Refund::first();
        $this->post("/admin/refunds/{$refund->id}/decide", ['action' => 'approve'])->assertSessionHas('error');
        $this->assertSame('requested', $refund->fresh()->status);

        $this->postExpense();
        $expense = Expense::first();
        $this->post("/admin/expenses/{$expense->id}/approve")->assertSessionHas('error');
        $this->assertNull($expense->fresh()->approved_at);

        $this->actingAs($this->secondOwner());
        $this->flushSession();
        $this->post("/admin/refunds/{$refund->id}/decide", ['action' => 'approve'])->assertSessionMissing('error');
        $this->post("/admin/expenses/{$expense->id}/approve")->assertSessionMissing('error');
        $this->assertSame('approved', $refund->fresh()->status);
        $this->assertNotNull($expense->fresh()->approved_at);
    }

    public function test_settings_save_the_new_controls(): void
    {
        $this->post('/admin/settings', [
            'extra_guest' => '200.00', 'early_fee' => '200.00', 'standard_check_in' => '14:00', 'hold_hours' => 24,
            'laundry_auto' => true, 'require_verification' => false, 'turnover_due_hours' => 4,
            'expense_threshold' => '2500.50', 'second_reviewer' => true, 'require_two_factor' => false,
        ])->assertSessionHasNoErrors();

        $this->assertSame(250050, Settings::get('finance.expense_approval_threshold_cents'));
        $this->assertTrue(Settings::get('finance.second_reviewer'));
        $this->assertFalse(Settings::get('security.require_two_factor'));
    }

    public function test_payouts_are_audited_and_cannot_be_recorded_twice(): void
    {
        $settlement = ExternalSettlement::create(['provider' => 'Airbnb', 'gross_cents' => 100000, 'fees_cents' => 0, 'adjustments_cents' => 0, 'expected_net_cents' => 100000]);

        $this->post("/admin/settlements/{$settlement->id}/payout", ['actual_payout' => '1000.00', 'payout_date' => '2031-03-05'])->assertSessionMissing('error');
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'settlement.payout_recorded')->count());

        $this->post("/admin/settlements/{$settlement->id}/payout", ['actual_payout' => '1.00', 'payout_date' => '2031-03-06'])->assertSessionHas('error');
        $this->assertSame(100000, (int) $settlement->fresh()->actual_payout_cents);
    }

    public function test_month_end_notifies_once_and_never_locks(): void
    {
        ExternalSettlement::create(['provider' => 'Airbnb', 'gross_cents' => 100000, 'fees_cents' => 0, 'adjustments_cents' => 0, 'expected_net_cents' => 100000]);

        $this->artisan('uniqa:month-end --period=2031-02')->assertSuccessful();
        $this->artisan('uniqa:month-end --period=2031-02')->assertSuccessful();

        $notices = $this->owner->notifications()->get();
        $this->assertCount(2, $notices);
        $this->assertStringContainsString('2 open reconciliation exception(s)', $notices->firstWhere(fn ($n) => str_contains($n->data['title'], 'exception'))->data['title']);
        $this->assertStringContainsString('from=2031-02-01&to=2031-02-28&type=room_grid', $notices->firstWhere(fn ($n) => str_contains($n->data['title'], 'room grid'))->data['url']);
        $this->assertSame(0, PeriodLock::count());
    }

    public function test_month_end_skips_a_locked_month_and_rejects_bad_input(): void
    {
        PeriodLock::create(['period' => '2031-02', 'locked_by' => $this->owner->id, 'locked_at' => now()]);

        $this->artisan('uniqa:month-end --period=2031-02')->assertSuccessful();
        $this->artisan('uniqa:month-end --period=February')->assertFailed();

        $this->assertSame(0, $this->owner->notifications()->count());
    }

    public function test_booking_screen_can_expand_to_several_rooms_and_conflicts_still_apply(): void
    {
        $booking = $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $this->confirmed('bali', '2031-03-03', '2031-03-05');
        $venice = $this->room('venice')->id;

        $this->post("/admin/bookings/{$booking->id}/amend", ['reason' => 'Group', 'room_ids' => [$venice, $this->room('roma')->id]])->assertSessionMissing('error');
        $this->assertEqualsCanonicalizing([$venice, $this->room('roma')->id], $booking->fresh()->activeRooms()->pluck('room_id')->all());
        $this->assertGreaterThan(340000, (int) $booking->fresh()->total_cents);

        $this->post("/admin/bookings/{$booking->id}/amend", ['reason' => 'Group', 'room_ids' => [$venice, $this->room('bali')->id]])->assertSessionHas('error');
        $this->assertEqualsCanonicalizing([$venice, $this->room('roma')->id], Booking::find($booking->id)->activeRooms()->pluck('room_id')->all());
    }

}
