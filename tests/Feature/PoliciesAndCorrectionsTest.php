<?php

namespace Tests\Feature;

use App\Domain\Finance\FinanceService;
use App\Domain\Pricing\PricingService;
use App\Domain\Reporting\ReportService;
use App\Models\Booking;
use App\Models\BookingCharge;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\HousekeepingTask;
use App\Models\Incident;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ParkingAssignment;
use App\Models\ParkingSpace;
use App\Models\PeriodLock;
use App\Models\RateRule;
use App\Models\Receipt;
use App\Models\TaxRule;
use App\Models\User;
use App\Support\Local;
use App\Support\Settings;
use App\Support\SystemNotice;
use Tests\DomainTestCase;

class PoliciesAndCorrectionsTest extends DomainTestCase
{
    private function lock(string $period): void
    {
        PeriodLock::create(['period' => $period, 'locked_by' => $this->owner->id, 'locked_at' => now()]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function tax(float $percent = 12, string $from = '2030-01-01', ?string $to = null): TaxRule
    {
        return TaxRule::create(['name' => 'Synthetic Tax', 'rate_basis_points' => (int) round($percent * 100), 'effective_from' => $from, 'effective_to' => $to]);
    }

    // Locked months

    public function test_a_stay_in_a_locked_month_cannot_be_amended_cancelled_or_marked_no_show(): void
    {
        $booking = $this->confirmed('venice', '2031-03-03', '2031-03-05');
        $before = (int) $booking->fresh()->total_cents;
        $this->lock('2031-03');

        $this->post("/admin/bookings/{$booking->id}/amend", ['reason' => 'x', 'check_out' => '2031-03-06'])->assertSessionHas('error');
        $this->flushSession();
        $this->post("/admin/bookings/{$booking->id}/cancel", ['reason' => 'x'])->assertSessionHas('error');
        $this->flushSession();
        $this->post("/admin/bookings/{$booking->id}/no-show")->assertSessionHas('error');

        $fresh = $booking->fresh();
        $this->assertSame(Booking::CONFIRMED, $fresh->status);
        $this->assertSame('2031-03-05', $fresh->check_out->toDateString());
        $this->assertSame($before, (int) $fresh->total_cents);
    }

    public function test_a_booking_cannot_be_moved_into_or_confirmed_inside_a_locked_month(): void
    {
        $this->lock('2031-03');

        $open = $this->confirmed('venice', '2031-04-03', '2031-04-05');
        $this->post("/admin/bookings/{$open->id}/amend", ['reason' => 'x', 'check_in' => '2031-03-20', 'check_out' => '2031-03-22'])->assertSessionHas('error');
        $this->assertSame('2031-04-03', $open->fresh()->check_in->toDateString());

        $this->flushSession();
        $inquiry = $this->inquiry('bali', '2031-03-10', '2031-03-12');
        $this->post("/admin/bookings/{$inquiry->id}/confirm")->assertSessionHas('error');
        $this->assertSame(Booking::INQUIRY, $inquiry->fresh()->status);
    }

    public function test_only_months_holding_a_night_count_and_unpriced_bookings_stay_free(): void
    {
        $this->lock('2031-03');

        // Checks out on 1 March: every night is in February, so March's lock does not apply.
        $february = $this->confirmed('venice', '2031-02-27', '2031-03-01');
        $this->bookings->cancel($february, 'Synthetic');
        $this->assertSame(Booking::CANCELLED, $february->fresh()->status);

        // An inquiry has no revenue, so cancelling it changes nothing in the closed month.
        $inquiry = $this->inquiry('bali', '2031-03-10', '2031-03-12');
        $this->bookings->cancel($inquiry, 'Synthetic');
        $this->assertSame(Booking::CANCELLED, $inquiry->fresh()->status);
    }

    public function test_an_incident_charge_cannot_be_added_while_this_month_is_locked(): void
    {
        $booking = $this->confirmed();
        $incident = Incident::create(['room_id' => $this->room('venice')->id, 'booking_id' => $booking->id, 'item' => 'Lamp', 'discovered_on' => Local::today(), 'status' => 'under_review']);
        $this->lock(substr(Local::today(), 0, 7));

        $this->post("/admin/incidents/{$incident->id}/decide", ['decision' => 'approve', 'approved' => '500.00', 'owner_resolution' => 'x'])->assertSessionHas('error');

        $this->assertNull($incident->fresh()->charge_id);
    }

    // Corrections

    public function test_an_expense_in_a_locked_month_is_corrected_by_a_reversal_dated_today(): void
    {
        $expense = Expense::create(['expense_category_id' => ExpenseCategory::first()->id, 'description' => 'Wrong bill', 'amount_cents' => 50000, 'expense_date' => '2026-01-10', 'status' => 'paid', 'paid_on' => '2026-01-10', 'proof_path' => 'evidence/x.pdf', 'created_by' => $this->owner->id]);
        $this->lock('2026-01');
        $today = Local::today();
        $service = app(ReportService::class);

        $this->post("/admin/expenses/{$expense->id}/reverse", ['reason' => 'Duplicate entry'])->assertSessionMissing('error');

        $reversal = Expense::where('reverses_expense_id', $expense->id)->firstOrFail();
        $this->assertSame(-50000, (int) $reversal->amount_cents);
        $this->assertSame($today, $reversal->expense_date->toDateString());
        $this->assertSame($today, $reversal->paid_on->toDateString());
        $this->assertNull($reversal->proof_path);
        $this->assertSame(50000, (int) $expense->fresh()->amount_cents, 'The original stays as it was.');
        $this->assertSame(50000, $service->kpis('2026-01-01', '2026-01-31')['expenses_cents'], 'The closed month does not change.');
        $this->assertSame(-50000, $service->kpis($today, $today)['expenses_cents']);

        $this->post("/admin/expenses/{$expense->id}/reverse", ['reason' => 'Again'])->assertSessionHas('error');
        $this->flushSession();
        $this->post("/admin/expenses/{$reversal->id}/reverse", ['reason' => 'Undo'])->assertSessionHas('error');
        $this->assertSame(2, Expense::count());
    }

    public function test_a_verified_receipt_reversal_restores_the_balance(): void
    {
        $booking = $this->confirmed();
        $finance = app(FinanceService::class);
        $receipt = $finance->recordReceipt(['booking_id' => $booking->id, 'received_at' => now()->toDateTimeString(), 'amount_cents' => 100000, 'method' => 'gcash', 'external_reference' => 'SYN']);
        $finance->review($receipt, true);
        $due = $finance->balance($booking)['balance_cents'];

        $this->post("/admin/receipts/{$receipt->id}/reverse", ['reason' => 'Bounced'])->assertSessionMissing('error');

        $this->assertSame($due + 100000, $finance->balance($booking)['balance_cents']);
        $this->assertSame('verified', $receipt->fresh()->verification_status);
        $this->assertFalse($finance->reconciliationExceptions()->contains(fn ($e) => $e['type'] === 'Missing proof' && str_contains($e['detail'], Receipt::where('reverses_receipt_id', $receipt->id)->value('reference'))), 'A reversal needs no proof of its own.');

        $this->post("/admin/receipts/{$receipt->id}/reverse", ['reason' => 'Again'])->assertSessionHas('error');
        $this->assertSame(2, Receipt::count());
    }

    public function test_a_pending_receipt_cannot_be_reversed_and_reversal_needs_verify_permission(): void
    {
        $receipt = app(FinanceService::class)->recordReceipt(['received_at' => now()->toDateTimeString(), 'amount_cents' => 100, 'method' => 'cash']);

        $this->post("/admin/receipts/{$receipt->id}/reverse", ['reason' => 'x'])->assertSessionHas('error');

        $this->actingAs($this->staff('Booking / Reception'))->post("/admin/receipts/{$receipt->id}/reverse", ['reason' => 'x'])->assertForbidden();
    }

    // Stay rules

    public function test_stay_rules_are_off_until_set_then_room_values_beat_the_setting(): void
    {
        $this->confirmed('venice', '2031-03-03', '2031-03-04');

        Settings::set('stay.min_nights', 2);
        $short = $this->inquiry('bali', '2031-03-03', '2031-03-04');
        $this->post("/admin/bookings/{$short->id}/hold")->assertSessionHas('error');
        $this->flushSession();
        $this->post("/admin/bookings/{$short->id}/confirm")->assertSessionHas('error');
        $this->assertSame(Booking::INQUIRY, $short->fresh()->status);

        $this->room('bali')->update(['min_nights' => 1]);
        $this->flushSession();
        $this->post("/admin/bookings/{$short->id}/confirm")->assertSessionMissing('error');
        $this->assertSame(Booking::CONFIRMED, $short->fresh()->status);
    }

    public function test_booking_window_limit_and_party_change_do_not_retrigger_rules(): void
    {
        $booking = $this->confirmed('venice', '2031-03-03', '2031-03-04');
        Settings::set('stay.min_nights', 2);

        // Already confirmed before the rule: changing only the party is not a new stay.
        $this->post("/admin/bookings/{$booking->id}/amend", ['reason' => 'x', 'adults' => 3])->assertSessionMissing('error');
        // Moving the dates is, so the rule applies.
        $this->post("/admin/bookings/{$booking->id}/amend", ['reason' => 'x', 'check_in' => '2031-03-10', 'check_out' => '2031-03-11'])->assertSessionHas('error');

        Settings::set('stay.min_nights', null);
        Settings::set('stay.max_advance_days', 30);
        $this->flushSession();
        $far = $this->inquiry('roma', Local::now()->addDays(31)->toDateString(), Local::now()->addDays(33)->toDateString());
        $near = $this->inquiry('roma', Local::now()->addDays(30)->toDateString(), Local::now()->addDays(31)->toDateString());
        $this->post("/admin/bookings/{$far->id}/hold")->assertSessionHas('error');
        $this->flushSession();
        $this->post("/admin/bookings/{$near->id}/hold")->assertSessionMissing('error');
    }

    public function test_policies_page_shows_only_what_the_owner_wrote(): void
    {
        auth()->logout();
        $this->get('/policies')->assertInertia(fn ($p) => $p->where('policy.cancellation', null)->where('policy.house_rules', null));

        Settings::set('policy.cancellation', 'Synthetic cancellation text');
        $this->assertStringContainsString('Synthetic cancellation text', $this->get('/policies')->getContent());
    }

    // Taxes

    public function test_no_tax_is_charged_until_configured(): void
    {
        $booking = $this->confirmed();

        $this->assertSame(0, $booking->charges()->where('type', 'tax')->count());
    }

    public function test_tax_applies_in_centavos_and_stays_out_of_revenue(): void
    {
        $this->tax(12);
        $booking = $this->confirmed('venice', '2031-03-03', '2031-03-05'); // 2 x 1,700 weekday

        $tax = $booking->charges()->where('type', 'tax')->whereNull('voided_at')->sole();
        $this->assertSame(40800, (int) $tax->amount_cents);
        $this->assertSame('Synthetic Tax (12%)', $tax->description);
        $this->assertSame(380800, (int) $booking->fresh()->total_cents);

        $booking->update(['created_at' => '2031-03-01 04:00:00']);
        $kpis = app(ReportService::class)->kpis('2031-03-01', '2031-03-31');
        $this->assertSame(340000, $kpis['earned_cents']);
        $this->assertSame(340000, $kpis['gross_operating_cents'], 'Tax collected is not revenue.');
        $this->assertSame(340000, $kpis['booked_cents']);
    }

    public function test_tax_rounds_half_up_in_whole_centavos(): void
    {
        $this->tax(0.5); // 0.5% of a rate ending in .01 lands exactly on half a centavo at 101 centavos
        RateRule::where('room_id', $this->room('venice')->id)->update(['weekday_cents' => 101, 'weekend_cents' => 101]);

        $quote = app(PricingService::class)->quote($this->room('venice'), '2031-03-03', '2031-03-04', 2);

        $this->assertSame(1, collect($quote['lines'])->firstWhere('type', 'tax')['amount_cents'], '0.505 centavos rounds up to 1.');
        $this->assertSame(102, $quote['total_cents']);
    }

    public function test_tax_uses_the_check_in_date_and_does_not_rewrite_confirmed_prices(): void
    {
        $this->tax(1.25, '2031-03-01', '2031-03-31');
        $march = $this->confirmed('venice', '2031-03-03', '2031-03-04'); // 170000 x 1.25% = 2125
        $april = $this->confirmed('venice', '2031-04-03', '2031-04-04');
        $this->assertSame(2125, (int) $march->charges()->where('type', 'tax')->sole()->amount_cents);
        $this->assertSame(0, $april->charges()->where('type', 'tax')->count());

        $this->tax(50, '2031-03-01');
        $this->assertSame(1, $march->fresh()->charges()->where('type', 'tax')->count(), 'A later rule leaves the confirmed booking alone.');

        // Repricing supersedes the old tax line rather than stacking a second.
        $this->bookings->amend($march, ['adults' => 2, 'reason' => 'x']);
        $active = BookingCharge::where('booking_id', $march->id)->where('type', 'tax')->whereNull('voided_at')->pluck('amount_cents')->map(fn ($c) => (int) $c)->sort()->values()->all();
        $this->assertSame([2125, 85000], $active);
    }

    public function test_only_settings_managers_can_add_or_end_a_tax_and_rules_are_never_deleted(): void
    {
        $this->post('/admin/settings/taxes', ['name' => 'Bad', 'percent' => 'twelve', 'effective_from' => '2031-01-01'])->assertSessionHasErrors('percent');
        $this->post('/admin/settings/taxes', ['name' => 'Levy', 'percent' => '1.5', 'effective_from' => '2031-01-01'])->assertSessionHasNoErrors();
        $rule = TaxRule::sole();
        $this->assertSame(150, (int) $rule->rate_basis_points);

        $this->post("/admin/settings/taxes/{$rule->id}/end", ['effective_to' => '2030-01-01'])->assertSessionHasErrors('effective_to');
        $this->post("/admin/settings/taxes/{$rule->id}/end", ['effective_to' => '2031-12-31'])->assertSessionHasNoErrors();
        $this->assertSame('2031-12-31', TaxRule::sole()->effective_to->toDateString());

        $this->actingAs($this->staff('Finance / Bookkeeper'))->post('/admin/settings/taxes', ['name' => 'X', 'percent' => '5', 'effective_from' => '2031-01-01'])->assertForbidden();
    }

    // Housekeeping

    public function test_supplies_recorded_on_a_task_reduce_stock_once(): void
    {
        $booking = $this->confirmed();
        $this->bookings->checkIn($booking, true, 'Synthetic');
        $this->bookings->checkOut($booking);
        $task = HousekeepingTask::sole();
        $item = InventoryItem::first();
        app(\App\Domain\Inventory\Warehouse::class)->purchase([['item_id' => $item->id, 'quantity' => 10]], null, null);
        $cleaner = $this->staff('Housekeeping / Laundry');
        $body = ['checklist' => [['label' => 'Floors', 'done' => '1']], 'supplies' => [['inventory_item_id' => $item->id, 'quantity' => 2]]];

        $this->actingAs($cleaner)->post("/admin/tasks/{$task->id}/start");
        $this->post("/admin/tasks/{$task->id}/complete", $body)->assertSessionMissing('error');
        $this->post("/admin/tasks/{$task->id}/complete", $body)->assertSessionHas('error');

        $movement = InventoryMovement::where('type', 'stock_out')->sole();
        $this->assertSame(-2, (int) $movement->quantity);
        $this->assertSame($task->id, (int) $movement->housekeeping_task_id);
        $this->assertSame($this->room('venice')->property_id, (int) $movement->property_id);
    }

    public function test_finishing_a_task_without_supplies_changes_no_stock_and_others_cannot_record_them(): void
    {
        $booking = $this->confirmed();
        $this->bookings->checkIn($booking, true, 'Synthetic');
        $this->bookings->checkOut($booking);
        $task = HousekeepingTask::sole();
        $checklist = [['label' => 'Floors', 'done' => '1']];
        $supervisor = User::factory()->create();
        $supervisor->givePermissionTo('housekeeping.manage');

        $this->post("/admin/tasks/{$task->id}/start");
        $this->actingAs($supervisor)->post("/admin/tasks/{$task->id}/complete", ['checklist' => $checklist, 'supplies' => [['inventory_item_id' => InventoryItem::first()->id, 'quantity' => 1]]])->assertForbidden();
        $this->assertSame('in_progress', $task->fresh()->status);

        $this->post("/admin/tasks/{$task->id}/complete", ['checklist' => $checklist])->assertSessionMissing('error');
        $this->assertSame('for_inspection', $task->fresh()->status);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_auto_assign_spreads_work_by_current_load(): void
    {
        $busy = $this->staff('Housekeeping / Laundry');
        $free = $this->staff('Housekeeping / Laundry');
        $room = $this->room('venice');
        HousekeepingTask::create(['type' => 'turnover', 'room_id' => $room->id, 'status' => 'assigned', 'assigned_to' => $busy->id]);
        $first = HousekeepingTask::create(['type' => 'turnover', 'room_id' => $this->room('bali')->id, 'status' => 'pending']);
        $second = HousekeepingTask::create(['type' => 'turnover', 'room_id' => $this->room('roma')->id, 'status' => 'pending']);
        $third = HousekeepingTask::create(['type' => 'turnover', 'room_id' => $this->room('rio')->id, 'status' => 'pending']);

        $this->post('/admin/tasks/auto-assign')->assertSessionMissing('error');

        $this->assertSame($free->id, $first->fresh()->assigned_to);
        $this->assertSame([$busy->id, $free->id], collect([$second->fresh()->assigned_to, $third->fresh()->assigned_to])->sort()->values()->all());

        $this->actingAs($busy)->post('/admin/tasks/auto-assign')->assertForbidden();
    }

    public function test_auto_assign_explains_when_there_is_no_housekeeping_staff(): void
    {
        HousekeepingTask::create(['type' => 'turnover', 'room_id' => $this->room('bali')->id, 'status' => 'pending']);

        $this->post('/admin/tasks/auto-assign')->assertSessionHas('error');
    }

    // Incidents

    public function test_incident_timeline_shows_communications_and_decisions_and_respects_roles(): void
    {
        $booking = $this->confirmed();
        $incident = Incident::create(['room_id' => $this->room('venice')->id, 'booking_id' => $booking->id, 'item' => 'Lamp', 'discovered_on' => Local::today(), 'status' => 'under_review']);

        $this->post("/admin/incidents/{$incident->id}/notes", ['channel' => 'telepathy', 'body' => 'x'])->assertSessionHasErrors('channel');
        $this->post("/admin/incidents/{$incident->id}/notes", ['channel' => 'messenger', 'body' => 'Guest agreed to review the photo.'])->assertSessionHasNoErrors();
        $this->post("/admin/incidents/{$incident->id}/decide", ['decision' => 'approve', 'approved' => '500.00', 'owner_resolution' => 'Guest accepted.'])->assertSessionMissing('error');

        $this->get("/admin/incidents/{$incident->id}")->assertOk()->assertInertia(fn ($p) => $p
            ->where('rows.0.kind', 'Messenger')->where('rows.0.detail', 'Guest agreed to review the photo.')
            ->where('rows.1.kind', 'Approve')->where('rows.1.detail', 'Guest accepted.'));

        $this->actingAs($this->staff('Housekeeping / Laundry'))->get("/admin/incidents/{$incident->id}")->assertForbidden();
        $this->post("/admin/incidents/{$incident->id}/notes", ['channel' => 'internal', 'body' => 'x'])->assertForbidden();
    }

    // Parking calendar

    public function test_parking_calendar_shows_active_holders_for_the_month_only(): void
    {
        $space = ParkingSpace::firstOrFail();
        ParkingAssignment::create(['parking_space_id' => $space->id, 'holder_name' => 'Synthetic In', 'start_date' => '2031-02-27', 'end_date' => '2031-03-02', 'status' => 'active']);
        ParkingAssignment::create(['parking_space_id' => $space->id, 'holder_name' => 'Synthetic Cancelled', 'start_date' => '2031-03-05', 'end_date' => '2031-03-06', 'status' => 'cancelled']);
        ParkingAssignment::create(['parking_space_id' => $space->id, 'holder_name' => 'Synthetic Out', 'start_date' => '2031-04-01', 'end_date' => '2031-04-02', 'status' => 'active']);

        $this->get('/admin/parking/calendar?month=2031-03')->assertOk()->assertInertia(fn ($p) => $p->component('Admin/ParkingCalendar')->where('days', 31)->has('assignments', 1)->where('assignments.0.holder', 'Synthetic In'));
        $this->get('/admin/parking/calendar?month=2031-13')->assertSessionHasErrors('month');

        $this->actingAs($this->staff('Housekeeping / Laundry'))->get('/admin/parking/calendar')->assertForbidden();
    }

    // Notifications

    public function test_people_can_mute_a_category_but_direct_assignments_still_arrive(): void
    {
        $this->post('/admin/account/notifications', ['muted' => ['nonsense']])->assertSessionHasErrors('muted.0');
        $this->post('/admin/account/notifications', ['muted' => ['arrivals']])->assertSessionHasNoErrors();
        $this->owner->refresh();

        SystemNotice::toPermission('bookings.manage', 'Muted one', null, 'arrivals');
        SystemNotice::toPermission('bookings.manage', 'Other one', null, 'holds');
        $this->owner->notify(new SystemNotice('Assigned to you'));

        $this->assertEqualsCanonicalizing(['Other one', 'Assigned to you'], $this->owner->notifications()->get()->pluck('data.title')->all());
    }

    public function test_arrival_reminder_follows_the_configured_lead_time(): void
    {
        $inTwoDays = Local::now()->addDays(2)->toDateString();
        $this->confirmed('venice', $inTwoDays, Local::now()->addDays(3)->toDateString());

        $this->artisan('uniqa:daily-alerts')->assertSuccessful();
        $this->assertSame(0, $this->owner->notifications()->where('data', 'like', '%arrival%')->count());

        Settings::set('alerts.arrival_lead_days', 2);
        $this->artisan('uniqa:daily-alerts')->assertSuccessful();
        $this->assertStringContainsString('in 2 days', $this->owner->notifications()->where('data', 'like', '%arrival%')->sole()->data['title']);
    }

    // Report filters

    public function test_ledger_and_collections_filter_by_source_room_and_method(): void
    {
        $this->confirmed('venice', '2031-03-03', '2031-03-05', 'airbnb');
        $booking = $this->confirmed('bali', '2031-03-03', '2031-03-05');
        $reports = app(ReportService::class);

        $rooms = fn (array $filters) => collect($reports->table('room_ledger', '2031-03-01', '2031-03-07', null, 'stay', $filters)[1])->pluck(1)->unique()->values()->all();
        $this->assertSame(['VENICE'], $rooms(['source' => $this->source('airbnb')]));
        $this->assertSame(['BALI'], $rooms(['room' => $this->room('bali')->id]));

        $finance = app(FinanceService::class);
        foreach (['gcash', 'cash'] as $method) {
            $finance->review($finance->recordReceipt(['booking_id' => $booking->id, 'received_at' => '2031-03-02 10:00:00', 'amount_cents' => 100, 'method' => $method]), true);
        }
        $methods = collect($reports->table('collections', '2031-03-01', '2031-03-07', null, 'stay', ['method' => 'gcash'])[1])->pluck(3)->unique()->values()->all();
        $this->assertSame(['gcash'], $methods);

        $this->get('/admin/reports?type=collections&method=bitcoin')->assertSessionHasErrors('method');
    }
}
