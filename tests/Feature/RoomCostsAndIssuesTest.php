<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Incident;
use App\Models\WorkOrder;
use App\Support\Local;
use Tests\DomainTestCase;

/** Room expenses per month, guest complaints with priorities, and completing a job with its cost. */
class RoomCostsAndIssuesTest extends DomainTestCase
{
    private function props(string $url): array
    {
        return $this->get($url)->assertOk()->original->getData()['page']['props'];
    }

    private function rows(string $url): array
    {
        $rows = $this->props($url)['rows'];

        return $rows['data'] ?? $rows;
    }

    public function test_guest_complaint_is_linked_to_the_stay_and_shown_red_first(): void
    {
        $booking = $this->confirmed('venice');
        $this->post('/admin/work-orders', ['title' => 'Leaking faucet', 'severity' => 'low', 'room_id' => $this->room('venice')->id])->assertSessionHasNoErrors();
        $this->post('/admin/work-orders', [
            'title' => 'AC not cooling, needs cleaning', 'severity' => 'high', 'source' => 'guest_complaint', 'booking_reference' => $booking->reference,
        ])->assertSessionHasNoErrors();

        $order = WorkOrder::where('title', 'like', 'AC%')->firstOrFail();
        $this->assertSame($booking->id, $order->booking_id);
        $this->assertSame($this->room('venice')->id, $order->room_id, 'the room comes from the booking');
        $this->assertSame('guest_complaint', $order->source);

        $rows = $this->rows('/admin/work-orders');
        $this->assertSame('High', $rows[0]['priority']);
        $this->assertStringContainsString($booking->reference, $rows[0]['source']);
        $this->assertSame(1, $this->props('/admin/work-orders')['stats'][0]['value'], 'one urgent or high issue pending');
        $this->assertCount(1, $this->rows('/admin/work-orders?priority=urgent'));
    }

    public function test_unknown_booking_reference_is_refused(): void
    {
        $this->post('/admin/work-orders', ['title' => 'Noise', 'severity' => 'normal', 'booking_reference' => 'UQ-NOPE'])->assertSessionHasErrors('booking_reference');
    }

    public function test_completing_moves_the_job_to_completed_and_records_the_cost_once(): void
    {
        $order = WorkOrder::create(['title' => 'AC cleaning', 'room_id' => $this->room('venice')->id, 'severity' => 'critical', 'created_by' => $this->owner->id]);

        $this->post("/admin/work-orders/{$order->id}/complete", ['note' => 'Cleaned filters', 'cost' => '1500', 'record_expense' => true])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('resolved', $order->status);
        $this->assertSame($this->owner->id, $order->completed_by);
        $this->assertSame('Cleaned filters', $order->completion_note);
        $expense = Expense::where('work_order_id', $order->id)->sole();
        $this->assertSame(150000, (int) $expense->amount_cents);
        $this->assertSame($this->room('venice')->id, $expense->room_id);
        $this->assertSame('Repairs & Maintenance', $expense->category->name);

        $this->assertCount(0, $this->rows('/admin/work-orders'), 'no longer pending');
        $this->assertCount(1, $this->rows('/admin/work-orders?view=completed'));

        // Reopened and completed again: the cost is not added a second time.
        $this->post("/admin/work-orders/{$order->id}/status", ['status' => 'open'])->assertSessionHasNoErrors();
        $this->post("/admin/work-orders/{$order->id}/complete", ['cost' => '1500', 'record_expense' => true]);
        $this->assertSame(1, Expense::where('work_order_id', $order->id)->count());
    }

    public function test_completing_without_a_cost_records_no_expense(): void
    {
        $order = WorkOrder::create(['title' => 'Reset breaker', 'room_id' => $this->room('venice')->id, 'severity' => 'normal', 'created_by' => $this->owner->id]);

        $this->post("/admin/work-orders/{$order->id}/complete", ['record_expense' => true])->assertSessionHasNoErrors();

        $this->assertSame('resolved', $order->fresh()->status);
        $this->assertSame(0, Expense::where('work_order_id', $order->id)->count());
    }

    public function test_damage_replacement_cost_goes_to_the_room(): void
    {
        $incident = Incident::create(['room_id' => $this->room('venice')->id, 'item' => 'Broken lamp', 'discovered_on' => Local::today(), 'status' => 'under_review']);

        $this->post("/admin/incidents/{$incident->id}/status", ['status' => 'resolved', 'repair_cost' => '800', 'record_expense' => true])->assertSessionHasNoErrors();

        $expense = Expense::where('incident_id', $incident->id)->sole();
        $this->assertSame('Damage & Replacement', $expense->category->name);
        $this->assertSame(80000, (int) $expense->amount_cents);
    }

    public function test_room_costs_add_up_each_rooms_expenses_by_month(): void
    {
        $utilities = ExpenseCategory::where('name', 'Electricity')->value('id');
        $venice = $this->room('venice')->id;
        $thisMonth = Local::now()->startOfMonth();
        foreach ([[$thisMonth->toDateString(), 120000], [$thisMonth->addDays(3)->toDateString(), 30000], [$thisMonth->subMonth()->toDateString(), 50000]] as [$date, $cents]) {
            Expense::create(['expense_category_id' => $utilities, 'room_id' => $venice, 'description' => 'Bill', 'amount_cents' => $cents, 'expense_date' => $date, 'status' => 'paid', 'paid_on' => $date]);
        }
        Expense::create(['expense_category_id' => $utilities, 'description' => 'Building bill', 'amount_cents' => 999900, 'expense_date' => $thisMonth->toDateString(), 'status' => 'paid', 'paid_on' => $thisMonth->toDateString()]);

        $rows = collect($this->rows('/admin/room-costs'))->keyBy('id');
        $key = 'm'.$thisMonth->format('Ym');
        $last = 'm'.$thisMonth->subMonth()->format('Ym');

        $this->assertSame(150000, $rows[$venice][$key]);
        $this->assertSame(50000, $rows[$venice][$last]);
        $this->assertSame(200000, $rows[$venice]['total']);
        $this->assertSame(200000, $rows['total']['total'], 'spend on no room is left out');
        $this->assertSame("/admin/expenses?room={$venice}", $rows[$venice]['room']['href']);
        $this->assertCount(3, $this->rows("/admin/expenses?room={$venice}"));
    }

    public function test_a_cost_over_the_approval_limit_is_recorded_as_incurred_and_the_job_still_completes(): void
    {
        \App\Support\Settings::set('finance.expense_approval_threshold_cents', 500000);
        $order = WorkOrder::create(['title' => 'Replace AC compressor', 'room_id' => $this->room('venice')->id, 'severity' => 'high', 'created_by' => $this->owner->id]);

        $this->post("/admin/work-orders/{$order->id}/complete", ['cost' => '8000', 'record_expense' => true])->assertSessionHasNoErrors();

        $this->assertSame('resolved', $order->fresh()->status);
        $this->assertSame('incurred', Expense::where('work_order_id', $order->id)->sole()->status);
    }

    public function test_an_incident_and_its_work_order_are_one_repair_in_room_costs(): void
    {
        $order = WorkOrder::create(['title' => 'Incident: Broken TV', 'room_id' => $this->room('venice')->id, 'severity' => 'critical', 'created_by' => $this->owner->id]);
        $incident = Incident::create(['room_id' => $this->room('venice')->id, 'work_order_id' => $order->id, 'item' => 'Broken TV', 'discovered_on' => Local::today(), 'status' => 'under_review']);

        $this->post("/admin/work-orders/{$order->id}/complete", ['cost' => '3000', 'record_expense' => true])->assertSessionHasNoErrors();
        $this->post("/admin/incidents/{$incident->id}/status", ['status' => 'resolved', 'repair_cost' => '3000', 'record_expense' => true])->assertSessionHasNoErrors();

        $this->assertSame(1, Expense::where('room_id', $this->room('venice')->id)->count(), 'counted once, not twice');
    }

    public function test_room_costs_show_damage_charged_to_guests(): void
    {
        $booking = $this->confirmed('venice');
        $incident = Incident::create(['room_id' => $this->room('venice')->id, 'booking_id' => $booking->id, 'item' => 'Stained sheet', 'discovered_on' => Local::today(), 'status' => 'under_review']);
        $this->post("/admin/incidents/{$incident->id}/decide", ['decision' => 'approve', 'approved' => '500', 'owner_resolution' => 'Guest agreed'])->assertSessionHasNoErrors();

        $row = collect($this->rows('/admin/room-costs'))->firstWhere('id', $this->room('venice')->id);
        $this->assertSame(50000, $row['recovered']);
    }
}
