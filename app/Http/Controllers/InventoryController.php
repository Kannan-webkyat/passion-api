<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index()
    {
        return response()->json(
            InventoryItem::with('category', 'vendor')->orderBy('name')->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'sku'             => 'required|string|max:100|unique:inventory_items,sku',
            'category_id'     => 'nullable|exists:inventory_categories,id',
            'vendor_id'       => 'nullable|exists:vendors,id',
            'unit_of_measure' => 'nullable|string|max:50',
            'cost_price'      => 'nullable|numeric|min:0',
            'reorder_level'   => 'nullable|integer|min:0',
            'current_stock'   => 'nullable|integer|min:0',
            'description'     => 'nullable|string',
        ]);

        $item = InventoryItem::create($validated);
        return response()->json($item->load('category', 'vendor'), 201);
    }

    public function show(InventoryItem $inventoryItem)
    {
        return response()->json($inventoryItem->load('category', 'vendor', 'transactions'));
    }

    public function update(Request $request, InventoryItem $inventoryItem)
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'sku'             => 'required|string|max:100|unique:inventory_items,sku,' . $inventoryItem->id,
            'category_id'     => 'nullable|exists:inventory_categories,id',
            'vendor_id'       => 'nullable|exists:vendors,id',
            'unit_of_measure' => 'nullable|string|max:50',
            'cost_price'      => 'nullable|numeric|min:0',
            'reorder_level'   => 'nullable|integer|min:0',
            'current_stock'   => 'nullable|integer|min:0',
            'description'     => 'nullable|string',
        ]);

        $inventoryItem->update($validated);
        return response()->json($inventoryItem->load('category', 'vendor'));
    }

    public function destroy(InventoryItem $inventoryItem)
    {
        $inventoryItem->delete();
        return response()->json(null, 204);
    }

    public function stats()
    {
        $items = InventoryItem::with('category', 'vendor')->get();

        $totalValue      = $items->sum(fn($i) => $i->current_stock * $i->cost_price);
        $lowStockCount   = $items->filter(fn($i) => $i->current_stock <= $i->reorder_level)->count();
        $recentTx        = InventoryTransaction::with('item')->latest()->take(10)->get();

        return response()->json([
            'total_items'         => $items->count(),
            'total_value'         => $totalValue,
            'low_stock_count'     => $lowStockCount,
            'recent_transactions' => $recentTx,
        ]);
    }

    public function issue(Request $request)
    {
        $validated = $request->validate([
            'item_id'    => 'required|exists:inventory_items,id',
            'quantity'   => 'required|integer|min:1',
            'department' => 'required|string',
            'notes'      => 'nullable|string',
        ]);

        $item = InventoryItem::findOrFail($validated['item_id']);

        if ($item->current_stock < $validated['quantity']) {
            return response()->json(['message' => 'Insufficient stock'], 422);
        }

        $item->decrement('current_stock', $validated['quantity']);

        $tx = InventoryTransaction::create([
            'inventory_item_id' => $item->id,
            'type'              => 'out',
            'quantity'          => $validated['quantity'],
            'department'        => $validated['department'],
            'reason'            => 'Issuance',
            'notes'             => $validated['notes'] ?? null,
            'user_id'           => auth()->id(),
        ]);

        return response()->json($tx->load('item'), 201);
    }
}
