<?php

namespace App\Domain\Inventory;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Room;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The single supply warehouse. On hand = the sum of every movement of an item:
 * purchases and counts add, stock-outs to a suite (from this page or a finished cleaning task) take away.
 * Stock never goes below zero: a stock-out larger than what is on hand is refused.
 */
class Warehouse
{
    public const PURCHASE = 'purchase';

    public const STOCK_OUT = 'stock_out';

    public const COUNT = 'adjustment';

    /** item id => quantity on hand. */
    public function onHand(?array $itemIds = null): Collection
    {
        return InventoryMovement::query()
            ->when($itemIds !== null, fn ($q) => $q->whereIn('inventory_item_id', $itemIds))
            ->groupBy('inventory_item_id')
            ->selectRaw('inventory_item_id, SUM(quantity) as qty')
            ->pluck('qty', 'inventory_item_id')
            ->map(fn ($q) => (int) $q);
    }

    /**
     * Lines: ['item_id' => ?int, 'name' => ?string, 'unit' => ?string, 'quantity' => int, 'unit_cost_cents' => ?int].
     * A name that is not an item yet becomes a new item, so a receipt can be entered as it reads.
     */
    public function purchase(array $lines, ?string $note, ?int $userId): int
    {
        return DB::transaction(function () use ($lines, $note, $userId) {
            foreach ($lines as $line) {
                $item = $this->resolveItem($line);
                if ($line['unit_cost_cents'] ?? null) {
                    $item->update(['unit_cost_cents' => $line['unit_cost_cents']]);
                }
                $movement = InventoryMovement::create([
                    'inventory_item_id' => $item->id,
                    'type' => self::PURCHASE,
                    'quantity' => abs((int) $line['quantity']),
                    'unit_cost_cents' => $line['unit_cost_cents'] ?? null,
                    'note' => $note,
                    'user_id' => $userId,
                ]);
                Audit::log('inventory.purchased', $movement, ['quantity' => $movement->quantity]);
            }

            return count($lines);
        });
    }

    /** Lines: ['inventory_item_id' => int, 'quantity' => int]. All or nothing. */
    public function stockOut(Room $room, array $lines, ?string $note, ?int $userId, ?int $taskId = null): void
    {
        // Same item twice on one form counts as one line.
        $wanted = collect($lines)->groupBy('inventory_item_id')->map(fn ($g) => $g->sum(fn ($l) => abs((int) $l['quantity'])))->filter();
        if ($wanted->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($room, $wanted, $note, $userId, $taskId) {
            // Locks the items so two people stocking out at once cannot both take the last pack.
            $items = InventoryItem::whereIn('id', $wanted->keys())->lockForUpdate()->get()->keyBy('id');
            $onHand = $this->onHand($wanted->keys()->all());

            $short = $wanted->filter(fn ($qty, $id) => $qty > ($onHand[$id] ?? 0))
                ->map(fn ($qty, $id) => sprintf('%s: %d %s left in the warehouse, %d asked.', $items[$id]->name, $onHand[$id] ?? 0, $items[$id]->unit, $qty));
            if ($short->isNotEmpty()) {
                throw ValidationException::withMessages(['supplies' => 'Not enough in the warehouse. '.$short->implode(' ').' Record the purchase first.']);
            }

            foreach ($wanted as $id => $qty) {
                $movement = InventoryMovement::create([
                    'inventory_item_id' => $id,
                    'property_id' => $room->property_id,
                    'room_id' => $room->id,
                    'housekeeping_task_id' => $taskId,
                    'type' => self::STOCK_OUT,
                    'quantity' => -$qty,
                    'note' => $note ?? ($taskId ? 'Used on task #'.$taskId : null),
                    'user_id' => $userId,
                ]);
                Audit::log('inventory.moved', $movement, ['quantity' => -$qty, 'room' => $room->id, 'task' => $taskId]);
            }
        });
    }

    /** Sets the warehouse to what was actually counted; the difference is recorded, never hidden. */
    public function count(InventoryItem $item, int $counted, ?string $note, ?int $userId): int
    {
        return DB::transaction(function () use ($item, $counted, $note, $userId) {
            InventoryItem::whereKey($item->id)->lockForUpdate()->first();
            $difference = $counted - (int) ($this->onHand([$item->id])[$item->id] ?? 0);
            if ($difference !== 0) {
                $movement = InventoryMovement::create([
                    'inventory_item_id' => $item->id,
                    'type' => self::COUNT,
                    'quantity' => $difference,
                    'note' => $note ?: "Counted {$counted}",
                    'user_id' => $userId,
                ]);
                Audit::log('inventory.counted', $movement, ['quantity' => $difference, 'counted' => $counted]);
            }

            return $difference;
        });
    }

    private function resolveItem(array $line): InventoryItem
    {
        if (! empty($line['item_id'])) {
            return InventoryItem::findOrFail($line['item_id']);
        }
        $name = trim(preg_replace('/\s+/', ' ', (string) $line['name']));
        $existing = InventoryItem::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if ($existing) {
            return $existing;
        }

        $sku = $base = Str::upper(Str::slug($name)) ?: 'ITEM';
        for ($n = 2; InventoryItem::where('sku', $sku)->exists(); $n++) {
            $sku = "{$base}-{$n}";
        }

        return InventoryItem::create(['name' => $name, 'sku' => $sku, 'unit' => trim((string) ($line['unit'] ?? '')) ?: 'pc']);
    }
}
