<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Inventory\Warehouse;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Room;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Warehouse stock: purchases in, stock-outs to suites, and counts. */
class InventoryController extends Controller
{
    public function __construct(private Warehouse $warehouse) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $manage = $user->can('inventory.manage');
        $onHand = $this->warehouse->onHand();
        $since = now()->subDays(30);

        $lastPurchase = InventoryMovement::where('type', Warehouse::PURCHASE)->groupBy('inventory_item_id')->selectRaw('inventory_item_id, MAX(created_at) as at')->pluck('at', 'inventory_item_id');
        $outLately = InventoryMovement::where('type', Warehouse::STOCK_OUT)->where('created_at', '>=', $since)->groupBy('inventory_item_id')->selectRaw('inventory_item_id, -SUM(quantity) as qty')->pluck('qty', 'inventory_item_id');

        $items = InventoryItem::orderBy('name')->get()->map(fn (InventoryItem $i) => [
            'id' => $i->id,
            'name' => $i->name,
            'sku' => $i->sku,
            'unit' => $i->unit,
            'reorder_level' => (int) $i->reorder_level,
            'unit_cost_cents' => $manage ? $i->unit_cost_cents : null,
            'on_hand' => (int) ($onHand[$i->id] ?? 0),
            'out_30_days' => (int) ($outLately[$i->id] ?? 0),
            'last_purchase' => isset($lastPurchase[$i->id]) ? \Carbon\CarbonImmutable::parse($lastPurchase[$i->id], 'UTC')->toIso8601String() : null, // stored in UTC
        ]);

        $roomFilter = $request->integer('room') ?: null;
        $movements = InventoryMovement::with(['item:id,name,unit', 'room:id,name', 'user:id,name'])
            ->when($roomFilter, fn ($q) => $q->where('room_id', $roomFilter))
            ->latest('id')->limit(60)->get()
            ->map(fn (InventoryMovement $m) => [
                'id' => $m->id,
                'type' => $m->type,
                'item' => $m->item?->name,
                'unit' => $m->item?->unit,
                'quantity' => (int) $m->quantity,
                'room' => $m->room?->name,
                'note' => $m->note,
                'by' => $m->user?->name,
                'at' => $m->created_at,
                'task' => $m->housekeeping_task_id,
            ]);

        return Inertia::render('Admin/Inventory', [
            'items' => $items,
            'suites' => Room::suites()->orderBy('name')->get(['id', 'name'])->map(fn ($r) => ['value' => $r->id, 'label' => $r->name]),
            'movements' => $movements,
            'roomFilter' => $roomFilter,
            'can' => ['manage' => $manage, 'stockOut' => $manage || $user->can('inventory.consume')],
        ]);
    }

    public function purchase(Request $request)
    {
        $data = $request->validate([
            'lines' => 'required|array|min:1|max:50',
            'lines.*.item_id' => 'nullable|integer|exists:inventory_items,id',
            'lines.*.name' => 'required_without:lines.*.item_id|nullable|string|max:120',
            'lines.*.unit' => 'nullable|string|max:20',
            'lines.*.quantity' => 'required|integer|min:1|max:100000',
            'lines.*.unit_cost' => ['nullable', Money::RULE],
            'note' => 'nullable|string|max:255',
        ], ['lines.*.name.required_without' => 'Choose an item or type its name.']);

        $lines = collect($data['lines'])->map(fn ($l) => $l + ['unit_cost_cents' => Money::cents($l['unit_cost'] ?? null)])->all();
        $count = $this->warehouse->purchase($lines, $data['note'] ?? null, $request->user()->id);

        return back()->with('success', "Purchase recorded: {$count} item(s) added to the warehouse.");
    }

    public function stockOut(Request $request)
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer', fn ($a, $v, $fail) => Room::suites()->whereKey($v)->exists() ? null : $fail('Choose a suite.')],
            'lines' => 'required|array|min:1|max:50',
            'lines.*.inventory_item_id' => 'required|integer|exists:inventory_items,id',
            'lines.*.quantity' => 'required|integer|min:1|max:10000',
            'note' => 'nullable|string|max:255',
        ]);
        $room = Room::findOrFail($data['room_id']);
        $this->warehouse->stockOut($room, $data['lines'], $data['note'] ?? null, $request->user()->id);

        return back()->with('success', "Stocked out to {$room->name}. The warehouse is updated.");
    }

    public function storeItem(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120|unique:inventory_items,name', 'unit' => 'nullable|string|max:20', 'reorder_level' => 'nullable|integer|min:0|max:100000', 'unit_cost' => ['nullable', Money::RULE]]);
        $item = InventoryItem::create([
            'name' => $data['name'],
            'sku' => $this->sku($data['name']),
            'unit' => ($data['unit'] ?? null) ?: 'pc',
            'reorder_level' => $data['reorder_level'] ?? 0,
            'unit_cost_cents' => Money::cents($data['unit_cost'] ?? null),
        ]);

        return back()->with('success', "{$item->name} added. Record a purchase to put stock in the warehouse.");
    }

    public function updateItem(Request $request, InventoryItem $item)
    {
        $data = $request->validate(['name' => 'required|string|max:120|unique:inventory_items,name,'.$item->id, 'unit' => 'required|string|max:20', 'reorder_level' => 'required|integer|min:0|max:100000']);
        $item->update($data);

        return back()->with('success', "{$item->name} saved.");
    }

    public function count(Request $request, InventoryItem $item)
    {
        $data = $request->validate(['counted' => 'required|integer|min:0|max:1000000', 'note' => 'nullable|string|max:255']);
        $difference = $this->warehouse->count($item, (int) $data['counted'], $data['note'] ?? null, $request->user()->id);

        return back()->with('success', $difference === 0 ? "{$item->name}: the count matches." : sprintf('%s set to %d (%+d).', $item->name, $data['counted'], $difference));
    }

    private function sku(string $name): string
    {
        $sku = $base = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::slug($name)) ?: 'ITEM';
        for ($n = 2; InventoryItem::where('sku', $sku)->exists(); $n++) {
            $sku = "{$base}-{$n}";
        }

        return $sku;
    }
}
