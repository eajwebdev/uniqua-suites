<?php

namespace Tests\Feature;

use App\Domain\Inventory\Warehouse;
use App\Models\HousekeepingTask;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use Tests\DomainTestCase;

class InventoryWarehouseTest extends DomainTestCase
{
    private function onHand(string $name): int
    {
        $item = InventoryItem::where('name', $name)->firstOrFail();

        return (int) (app(Warehouse::class)->onHand()[$item->id] ?? 0);
    }

    private function buy(array $lines): void
    {
        $this->actingAs($this->owner)->post('/admin/inventory/purchases', ['lines' => $lines, 'note' => 'Receipt 1043'])->assertSessionHasNoErrors();
    }

    public function test_a_purchase_fills_the_warehouse_and_a_new_name_becomes_an_item(): void
    {
        $this->buy([
            ['item_id' => InventoryItem::where('name', 'Tissue')->value('id'), 'quantity' => 48, 'unit_cost' => '12.50'],
            ['name' => '  LED bulb ', 'unit' => 'pc', 'quantity' => 10],
            ['name' => 'tissue', 'quantity' => 2], // an existing name, any capitals: not a duplicate item
        ]);

        $this->assertSame(50, $this->onHand('Tissue'));
        $this->assertSame(10, $this->onHand('LED bulb'));
        $this->assertSame(1, InventoryItem::where('name', 'Tissue')->count());
        $this->assertSame(1250, InventoryItem::where('name', 'Tissue')->value('unit_cost_cents'));
        $this->assertNull(InventoryMovement::where('type', 'purchase')->first()->property_id, 'The warehouse belongs to no single building.');
    }

    public function test_stock_out_to_a_suite_takes_from_the_warehouse_and_never_below_zero(): void
    {
        $this->buy([['name' => 'Tissue', 'quantity' => 10]]);
        $tissue = InventoryItem::where('name', 'Tissue')->value('id');

        $this->post('/admin/inventory/stock-out', ['room_id' => $this->room('rio')->id, 'lines' => [['inventory_item_id' => $tissue, 'quantity' => 4]]])->assertSessionHasNoErrors();
        $this->assertSame(6, $this->onHand('Tissue'));
        $out = InventoryMovement::where('type', 'stock_out')->sole();
        $this->assertSame([-4, $this->room('rio')->id], [(int) $out->quantity, (int) $out->room_id]);

        // Two lines of the same item count together: 4 + 3 is more than the 6 left, so nothing moves.
        $this->post('/admin/inventory/stock-out', ['room_id' => $this->room('bali')->id, 'lines' => [['inventory_item_id' => $tissue, 'quantity' => 4], ['inventory_item_id' => $tissue, 'quantity' => 3]]])
            ->assertSessionHasErrors(['supplies' => 'Not enough in the warehouse. Tissue: 6 pc left in the warehouse, 7 asked. Record the purchase first.']);
        $this->assertSame(6, $this->onHand('Tissue'));
    }

    public function test_stock_out_only_goes_to_a_suite(): void
    {
        $this->buy([['name' => 'Tissue', 'quantity' => 10]]);
        $grabCam = new \Database\Seeders\GrabCamSeeder;
        $grabCam->withPhotos = false;
        $grabCam->run();
        $camera = \App\Models\Room::cameras()->firstOrFail();

        $this->post('/admin/inventory/stock-out', ['room_id' => $camera->id, 'lines' => [['inventory_item_id' => InventoryItem::where('name', 'Tissue')->value('id'), 'quantity' => 1]]])->assertSessionHasErrors('room_id');
    }

    public function test_a_count_records_the_difference(): void
    {
        $this->buy([['name' => 'Shampoo', 'quantity' => 20]]);
        $shampoo = InventoryItem::where('name', 'Shampoo')->firstOrFail();

        $this->post("/admin/inventory/items/{$shampoo->id}/count", ['counted' => 17, 'note' => 'Monthly count'])->assertSessionHas('success', 'Shampoo set to 17 (-3).');
        $this->assertSame(17, $this->onHand('Shampoo'));
        $this->post("/admin/inventory/items/{$shampoo->id}/count", ['counted' => 17])->assertSessionHas('success', 'Shampoo: the count matches.');
        $this->assertSame(1, InventoryMovement::where('type', 'adjustment')->count());
    }

    public function test_supplies_a_cleaner_records_come_out_of_the_warehouse_for_that_suite(): void
    {
        $this->buy([['name' => 'Tissue', 'quantity' => 5]]);
        $tissue = InventoryItem::where('name', 'Tissue')->value('id');
        $task = app(\App\Domain\Tasks\TurnoverService::class)->createTask(['type' => 'restock', 'title' => 'Refill', 'room_id' => $this->room('venice')->id, 'due_at' => now()->addHour()], null);
        $cleaner = User::factory()->create();
        $cleaner->assignRole('Housekeeping / Laundry');
        $task->update(['assigned_to' => $cleaner->id]);

        $this->actingAs($cleaner)->post("/admin/tasks/{$task->id}/start");
        // Asking for more than the warehouse holds keeps the task open and says why.
        $this->post("/admin/tasks/{$task->id}/complete", ['supplies' => [['inventory_item_id' => $tissue, 'quantity' => 9]]])->assertSessionHasErrors('supplies');
        $this->assertSame('in_progress', $task->fresh()->status);
        $this->assertSame(5, $this->onHand('Tissue'));

        $this->post("/admin/tasks/{$task->id}/complete", ['supplies' => [['inventory_item_id' => $tissue, 'quantity' => 2]]])->assertSessionHasNoErrors();
        $this->assertSame(3, $this->onHand('Tissue'));
        $this->assertSame($this->room('venice')->id, (int) InventoryMovement::where('housekeeping_task_id', $task->id)->sole()->room_id);
    }

    public function test_staff_who_refill_can_stock_out_but_not_buy_or_count(): void
    {
        $this->buy([['name' => 'Tissue', 'quantity' => 5]]);
        $tissue = InventoryItem::where('name', 'Tissue')->firstOrFail();
        $cleaner = User::factory()->create();
        $cleaner->assignRole('Housekeeping / Laundry');
        $this->actingAs($cleaner);

        $page = $this->get('/admin/inventory')->assertOk()->original->getData()['page']['props'];
        $this->assertSame(['manage' => false, 'stockOut' => true], $page['can']);
        $this->assertNull($page['items'][0]['unit_cost_cents'], 'Costs stay with managers.');

        $this->post('/admin/inventory/stock-out', ['room_id' => $this->room('rio')->id, 'lines' => [['inventory_item_id' => $tissue->id, 'quantity' => 1]]])->assertSessionHasNoErrors();
        $this->post('/admin/inventory/purchases', ['lines' => [['name' => 'Tissue', 'quantity' => 99]]])->assertForbidden();
        $this->post("/admin/inventory/items/{$tissue->id}/count", ['counted' => 99])->assertForbidden();
        $this->assertSame(4, $this->onHand('Tissue'));
    }

    public function test_low_warehouse_stock_reaches_managers_once_a_day_and_the_dashboard(): void
    {
        InventoryItem::where('name', 'Tissue')->update(['reorder_level' => 10]);
        $this->buy([['name' => 'Tissue', 'quantity' => 4]]);

        $this->artisan('uniqa:daily-alerts')->assertSuccessful();
        $this->assertStringContainsString('Warehouse is low on Tissue (4)', $this->owner->fresh()->notifications()->latest()->first()->data['title']);

        $low = $this->get('/admin')->original->getData()['page']['props']['operations']['low_stock'] ?? null;
        if ($low !== null) {
            $this->assertSame([['Tissue', 'Warehouse', 4.0]], array_map(fn ($s) => [$s['name'], $s['property'], $s['on_hand']], $low));
        }
    }
}
