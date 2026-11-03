<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Property;
use App\Models\Room;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $properties = Property::orderBy('id')->get();
        $stock = DB::table('inventory_movements')->selectRaw('inventory_item_id, property_id, SUM(quantity) as qty')->groupBy('inventory_item_id', 'property_id')->get()
            ->groupBy('inventory_item_id');
        $items = InventoryItem::orderBy('name')->get();

        $columns = [['key' => 'sku', 'label' => 'SKU'], ['key' => 'name', 'label' => 'Item'], ['key' => 'unit', 'label' => 'Unit'], ['key' => 'reorder_level', 'label' => 'Reorder at']];
        foreach ($properties as $p) {
            $columns[] = ['key' => "p{$p->id}", 'label' => $p->name];
        }
        $columns[] = ['key' => 'state', 'label' => 'Stock', 'type' => 'status'];

        $rows = $items->map(function ($i) use ($properties, $stock) {
            $row = ['id' => $i->id, 'sku' => $i->sku, 'name' => $i->name, 'unit' => $i->unit, 'reorder_level' => $i->reorder_level];
            $counted = false;
            $low = false;
            foreach ($properties as $p) {
                $line = ($stock[$i->id] ?? collect())->firstWhere('property_id', $p->id);
                $row["p{$p->id}"] = $line ? (int) $line->qty : 'Not counted';
                if ($line) {
                    $counted = true;
                    $low = $low || ($i->reorder_level > 0 && $line->qty <= $i->reorder_level);
                }
            }
            $row['state'] = ! $counted ? 'not_counted' : ($low ? 'low_stock' : 'ok');

            return $row;
        });

        return Inertia::render('Admin/Resource', [
            'title' => 'Inventory & Refills',
            'subtitle' => 'Items are examples from the RENOVATION sheet. The REFILLS tabs are empty, so no stock exists until a real count is entered. Stock falls only when use is recorded.',
            'columns' => $columns,
            'rows' => $rows,
            'create' => ($user->can('inventory.manage') || $user->can('inventory.consume')) ? ['label' => 'Record stock movement', 'url' => '/admin/inventory/movements', 'fields' => [
                ['name' => 'inventory_item_id', 'label' => 'Item', 'type' => 'select', 'required' => true, 'options' => $items->map(fn ($i) => ['value' => $i->id, 'label' => $i->name])->all()],
                ['name' => 'property_id', 'label' => 'Property', 'type' => 'select', 'required' => true, 'options' => $properties->map(fn ($p) => ['value' => $p->id, 'label' => $p->name])->all()],
                ['name' => 'type', 'label' => 'Movement', 'type' => 'select', 'required' => true, 'options' => $user->can('inventory.manage')
                    ? [['value' => 'receipt', 'label' => 'Stock received or counted'], ['value' => 'issue', 'label' => 'Used or issued to a room'], ['value' => 'adjustment', 'label' => 'Adjustment (+ or -)']]
                    : [['value' => 'issue', 'label' => 'Used or issued to a room']]],
                ['name' => 'quantity', 'label' => 'Quantity', 'type' => 'number', 'required' => true],
                ['name' => 'room_id', 'label' => 'Room', 'type' => 'select', 'options' => Room::orderBy('id')->get()->map(fn ($r) => ['value' => $r->id, 'label' => $r->name])->all()],
                ['name' => 'note', 'label' => 'Note', 'type' => 'text'],
            ]] : null,
            'secondaryCreate' => $user->can('inventory.manage') ? ['label' => 'Add item', 'url' => '/admin/inventory/items', 'fields' => [
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['name' => 'sku', 'label' => 'SKU', 'type' => 'text', 'required' => true],
                ['name' => 'unit', 'label' => 'Unit', 'type' => 'text', 'default' => 'pc'],
                ['name' => 'reorder_level', 'label' => 'Reorder at', 'type' => 'number', 'default' => 0],
                ['name' => 'unit_cost', 'label' => 'Unit cost (PHP)', 'type' => 'money'],
                ['name' => 'supplier', 'label' => 'Supplier', 'type' => 'text'],
            ]] : null,
        ]);
    }

    public function storeItem(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'sku' => 'required|string|max:60|unique:inventory_items,sku', 'unit' => 'nullable|string|max:20', 'reorder_level' => 'nullable|integer|min:0', 'unit_cost' => ['nullable', Money::RULE], 'supplier' => 'nullable|string|max:120']);
        InventoryItem::create([
            'name' => $data['name'], 'sku' => $data['sku'], 'unit' => $data['unit'] ?? 'pc', 'reorder_level' => $data['reorder_level'] ?? 0,
            'unit_cost_cents' => Money::cents($data['unit_cost'] ?? null), 'supplier' => $data['supplier'] ?? null,
        ]);

        return back()->with('success', 'Item added.');
    }

    public function storeMovement(Request $request)
    {
        $allowed = $request->user()->can('inventory.manage') ? ['receipt', 'issue', 'adjustment'] : ['issue'];
        $data = $request->validate([
            'inventory_item_id' => 'required|exists:inventory_items,id',
            'property_id' => 'required|exists:properties,id',
            'type' => ['required', Rule::in($allowed)],
            'quantity' => 'required|integer|not_in:0|min:-100000|max:100000',
            'room_id' => 'nullable|exists:rooms,id',
            'note' => 'nullable|string|max:255',
        ]);
        $qty = (int) $data['quantity'];
        $qty = match ($data['type']) {
            'issue' => -abs($qty),
            'receipt' => abs($qty),
            default => $qty,
        };
        $movement = InventoryMovement::create(['quantity' => $qty, 'user_id' => $request->user()->id] + collect($data)->except('quantity')->all());
        Audit::log('inventory.moved', $movement, ['quantity' => $qty]);

        return back()->with('success', 'Stock movement recorded.');
    }
}
