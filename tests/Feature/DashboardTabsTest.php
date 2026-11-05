<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Support\Local;
use Tests\DomainTestCase;

class DashboardTabsTest extends DomainTestCase
{
    private function props(string $url = '/admin'): array
    {
        return $this->get($url)->assertOk()->original->getData()['page']['props'];
    }

    private function expense(string $category, int $cents, string $status = 'incurred', array $extra = []): Expense
    {
        return Expense::create($extra + [
            'expense_category_id' => ExpenseCategory::firstOrCreate(['name' => $category])->id,
            'description' => "Synthetic {$category}",
            'amount_cents' => $cents,
            'expense_date' => Local::today(),
            'status' => $status,
        ]);
    }

    private function as(array $permissions): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);
    }

    public function test_the_expense_breakdowns_add_up_to_the_same_total_as_reports(): void
    {
        $this->expense('Water', 150000, 'paid');
        $this->expense('Water', 50000);
        $this->expense('Electricity', 300000, 'incurred', ['room_id' => $this->room('venice')->id]);

        $props = $this->props();
        $expenses = $props['expenses'];

        $this->assertSame(500000, $props['money']['expenses_cents']);
        $this->assertSame(500000, array_sum(array_column($expenses['by_category'], 'cents')));
        $this->assertSame(500000, array_sum(array_column($expenses['by_room'], 'cents')));
        $this->assertSame('Electricity', $expenses['by_category'][0]['name']);
        $this->assertSame(150000, $expenses['paid_cents']);
        $this->assertSame(350000, $expenses['unpaid_cents']);
        $this->assertSame(['VENICE', 'General'], array_column($expenses['by_room'], 'name'));
    }

    public function test_a_reversed_bill_is_not_listed_as_still_to_pay(): void
    {
        $this->expense('Water', 50000);
        $wrong = $this->expense('Electricity', 300000);
        app(\App\Domain\Finance\FinanceService::class)->reverseExpense($wrong, 'Entered twice');

        $expenses = $this->props()['expenses'];

        $this->assertSame(1, $expenses['bills_count']);
        $this->assertSame(50000, $expenses['bills_cents']);
        $this->assertSame(['Synthetic Water'], array_column($expenses['bills'], 'description'));
        $this->assertSame(['Synthetic Water'], array_column($expenses['largest'], 'description'));
        $this->assertSame(50000, $expenses['unpaid_cents'], 'The reversal cancels the bill in the period total too.');
    }

    public function test_an_old_unpaid_bill_is_still_listed_outside_the_period(): void
    {
        $this->expense('Water', 50000, 'incurred', ['expense_date' => Local::now()->subYear()->toDateString()]);

        $expenses = $this->props()['expenses'];

        $this->assertSame(0, $expenses['unpaid_cents']);
        $this->assertSame(1, $expenses['bills_count']);
    }

    public function test_income_shows_how_collected_money_came_in(): void
    {
        $booking = $this->confirmed('venice', Local::today(), Local::now()->addDay()->toDateString());
        $finance = app(\App\Domain\Finance\FinanceService::class);
        $finance->review($finance->recordReceipt(['booking_id' => $booking->id, 'received_at' => Local::now()->toDateTimeString(), 'amount_cents' => 100000, 'method' => 'gcash', 'external_reference' => 'SYN-1']), true);
        $finance->recordReceipt(['booking_id' => $booking->id, 'received_at' => Local::now()->toDateTimeString(), 'amount_cents' => 25000, 'method' => 'cash', 'external_reference' => 'SYN-2']);

        $props = $this->props();
        $income = $props['income'];

        $this->assertSame([['method' => 'gcash', 'cents' => 100000]], $income['by_method']);
        $this->assertSame($props['money']['collected_cents'], array_sum(array_column($income['by_method'], 'cents')) + $income['payouts_cents'] - $income['refunds_cents']);
        $this->assertSame(1, $income['pending_count']);
        $this->assertSame(25000, $income['pending_cents']);
        $this->assertSame($booking->id, $income['unpaid'][0]['booking_id']);
    }

    public function test_bookings_tab_counts_and_lists_only_what_is_ahead(): void
    {
        $this->confirmed('venice', Local::now()->addDays(2)->toDateString(), Local::now()->addDays(4)->toDateString());
        $this->confirmed('bali', Local::now()->addDays(20)->toDateString(), Local::now()->addDays(21)->toDateString());
        $this->inquiry('tokyo', Local::now()->addDays(5)->toDateString(), Local::now()->addDays(6)->toDateString());

        $tab = $this->props()['bookingsTab'];

        $this->assertSame(3, $tab['created']);
        $this->assertSame(2, $tab['confirmed']);
        $this->assertSame(1, $tab['upcoming_count']);
        $this->assertSame(['VENICE'], $tab['upcoming'][0]['rooms']);
        $this->assertSame(2, $tab['upcoming'][0]['nights']);
        $this->assertSame(1, $tab['inquiries']);
        $this->assertSame('inquiry', $tab['waiting'][0]['status']);
    }

    public function test_operations_lists_the_late_task_and_low_supplies(): void
    {
        $task = app(\App\Domain\Tasks\TurnoverService::class)->createTask(['type' => 'restock', 'title' => 'Restock towels', 'room_id' => $this->room('rio')->id, 'due_at' => now()->subHour()], null);
        \App\Models\WorkOrder::create(['room_id' => $this->room('rio')->id, 'title' => 'Normal job', 'severity' => 'normal', 'status' => 'open']);
        \App\Models\WorkOrder::create(['title' => 'Urgent job', 'severity' => 'critical', 'status' => 'open']);
        $low = \App\Models\InventoryItem::create(['sku' => 'SYN-1', 'name' => 'Synthetic soap', 'unit' => 'pc', 'reorder_level' => 50]);
        $enough = \App\Models\InventoryItem::create(['sku' => 'SYN-2', 'name' => 'Synthetic towel', 'unit' => 'pc', 'reorder_level' => 5]);
        foreach ([[$low, 20], [$enough, 40]] as [$item, $quantity]) {
            \App\Models\InventoryMovement::create(['inventory_item_id' => $item->id, 'property_id' => \App\Models\Property::orderBy('id')->value('id'), 'type' => 'receive', 'quantity' => $quantity]);
        }

        $operations = $this->props()['operations'];

        $this->assertSame(['Synthetic soap'], array_column($operations['low_stock'], 'name'));
        $this->assertEquals(20, $operations['low_stock'][0]['on_hand']);

        $this->assertSame($task->id, $operations['tasks'][0]['id']);
        $this->assertSame('Restock towels', $operations['tasks'][0]['title']);
        $this->assertTrue($operations['tasks'][0]['late']);
        $this->assertSame(['Urgent job', 'Normal job'], array_column($operations['repairs'], 'title'));
    }

    public function test_someone_without_money_access_gets_no_money_anywhere(): void
    {
        $this->expense('Water', 50000);
        $this->confirmed('venice', Local::today(), Local::now()->addDay()->toDateString());
        $this->as(['dashboard.view', 'bookings.view']);

        $props = $this->props();

        $this->assertNull($props['income']);
        $this->assertNull($props['expenses']);
        $this->assertNull($props['money']);
        $this->assertNotNull($props['bookingsTab']);
        $this->assertStringNotContainsString('_cents', json_encode($props['bookingsTab']));
        $this->assertNull($props['operations']['tasks']);
        $this->assertNull($props['operations']['repairs']);
        $this->assertNull($props['operations']['low_stock']);
    }

    public function test_money_access_alone_shows_totals_without_guest_or_vendor_details(): void
    {
        $this->expense('Water', 50000);
        $this->confirmed('venice', Local::today(), Local::now()->addDay()->toDateString());
        $this->as(['dashboard.view', 'dashboard.finance']);

        $props = $this->props();

        $this->assertSame(50000, $props['expenses']['bills_cents']);
        $this->assertNull($props['expenses']['bills']);
        $this->assertNull($props['expenses']['largest']);
        $this->assertSame(1, $props['income']['unpaid_count']);
        $this->assertNull($props['income']['unpaid']);
        $this->assertNull($props['bookingsTab'], 'No booking access, so no bookings tab.');
    }
}
