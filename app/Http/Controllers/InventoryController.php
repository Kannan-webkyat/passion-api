<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function index()
    {
        return response()->json(
            InventoryItem::with('category', 'vendor', 'purchaseUom', 'issueUom', 'tax', 'locations')->orderBy('name')->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'              => 'required|string|max:255',
            'sku'               => 'required|string|max:100|unique:inventory_items,sku',
            'category_id'       => 'required|exists:inventory_categories,id',
            'tax_id'            => 'nullable|exists:inventory_taxes,id',
            'purchase_uom_id'   => 'required|exists:inventory_uoms,id',
            'issue_uom_id'      => 'required|exists:inventory_uoms,id',
            'conversion_factor' => 'required|numeric|min:0.01',
            'vendor_id'         => 'nullable|exists:vendors,id',
            'cost_price'        => 'nullable|numeric|min:0',
            'reorder_level'     => 'nullable|integer|min:0',
            'current_stock'     => 'nullable|integer|min:0',
            'description'       => 'nullable|string',
        ]);

        $item = InventoryItem::create($validated);

        if ($item->current_stock > 0) {
            $mainStore = \App\Models\InventoryLocation::where('type', 'main_store')->first();
            if ($mainStore) {
                DB::table('inventory_item_locations')->updateOrInsert(
                    ['inventory_item_id' => $item->id, 'inventory_location_id' => $mainStore->id],
                    ['quantity' => $item->current_stock, 'reorder_level' => $item->reorder_level, 'updated_at' => now(), 'created_at' => now()]
                );

                InventoryTransaction::create([
                    'inventory_item_id' => $item->id,
                    'inventory_location_id' => $mainStore->id,
                    'type' => 'in',
                    'quantity' => $item->current_stock,
                    'reason' => 'Initial Stock',
                    'user_id' => auth()->id(),
                ]);
            }
        }

        return response()->json($item->load('category', 'vendor', 'purchaseUom', 'issueUom', 'tax', 'locations'), 201);
    }

    public function show(InventoryItem $item)
    {
        return response()->json($item->load('category', 'vendor', 'purchaseUom', 'issueUom', 'tax', 'transactions'));
    }

    public function update(Request $request, InventoryItem $item)
    {
        $validated = $request->validate([
            'name'              => 'required|string|max:255',
            'sku'               => 'required|string|max:100|unique:inventory_items,sku,' . $item->id,
            'category_id'       => 'required|exists:inventory_categories,id',
            'tax_id'            => 'nullable|exists:inventory_taxes,id',
            'purchase_uom_id'   => 'required|exists:inventory_uoms,id',
            'issue_uom_id'      => 'required|exists:inventory_uoms,id',
            'conversion_factor' => 'required|numeric|min:0.01',
            'vendor_id'         => 'nullable|exists:vendors,id',
            'cost_price'        => 'nullable|numeric|min:0',
            'reorder_level'     => 'nullable|integer|min:0',
            'current_stock'     => 'nullable|integer|min:0',
            'description'       => 'nullable|string',
        ]);

        $oldStock = $item->current_stock;
        $item->update($validated);

        // If manual stock edit, sync with Main Store
        if (isset($validated['current_stock']) && $validated['current_stock'] != $oldStock) {
            $mainStore = \App\Models\InventoryLocation::where('type', 'main_store')->first();
            if ($mainStore) {
                DB::table('inventory_item_locations')->updateOrInsert(
                    ['inventory_item_id' => $item->id, 'inventory_location_id' => $mainStore->id],
                    ['quantity' => $item->current_stock, 'updated_at' => now()]
                );

                InventoryTransaction::create([
                    'inventory_item_id' => $item->id,
                    'inventory_location_id' => $mainStore->id,
                    'type' => $item->current_stock > $oldStock ? 'in' : 'out',
                    'quantity' => abs($item->current_stock - $oldStock),
                    'reason' => 'Manual Adjustment',
                    'notes' => 'Stock edited via Item Master',
                    'user_id' => auth()->id(),
                ]);
            }
        }

        return response()->json($item->load('category', 'vendor', 'purchaseUom', 'issueUom', 'tax'));
    }

    public function destroy(InventoryItem $item)
    {
        $item->delete();
        return response()->json(null, 204);
    }

    public function stats()
    {
        $items = InventoryItem::with('category', 'vendor', 'purchaseUom', 'issueUom')->get();

        $totalValue      = $items->sum(fn($i) => $i->current_stock * $i->cost_price);
        $lowStockCount   = $items->filter(fn($i) => $i->current_stock <= $i->reorder_level)->count();
        $recentTx        = InventoryTransaction::with(['item', 'location'])->latest()->take(10)->get();

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
            'item_id'       => 'required|exists:inventory_items,id',
            'location_id'   => 'required|exists:inventory_locations,id',
            'quantity'      => 'required|numeric|min:0.01',
            'department_id' => 'required|exists:departments,id',
            'notes'         => 'nullable|string',
        ]);

        $item = \App\Models\InventoryItem::findOrFail($validated['item_id']);
        $dept = \App\Models\Department::findOrFail($validated['department_id']);

        // Check stock at location
        $locationStock = DB::table('inventory_item_locations')
            ->where('inventory_item_id', $item->id)
            ->where('inventory_location_id', $validated['location_id'])
            ->first();

        if (!$locationStock || $locationStock->quantity < $validated['quantity']) {
            return response()->json(['message' => 'Insufficient stock in this location'], 422);
        }

        DB::beginTransaction();
        try {
            // 1. Decrement Global Stock
            $item->decrement('current_stock', $validated['quantity']);

            // 2. Decrement Location Stock
            DB::table('inventory_item_locations')
                ->where('inventory_item_id', $item->id)
                ->where('inventory_location_id', $validated['location_id'])
                ->decrement('quantity', $validated['quantity']);

            // 3. Log Transaction
            $tx = \App\Models\InventoryTransaction::create([
                'inventory_item_id' => $item->id,
                'inventory_location_id' => $validated['location_id'],
                'department_id'     => $dept->id,
                'type'              => 'out',
                'quantity'          => $validated['quantity'],
                'department'        => $dept->name, // Keep for legacy
                'reason'            => 'Consumption',
                'notes'             => ($validated['notes'] ?? '') . ' (Consumed from ' . \App\Models\InventoryLocation::find($validated['location_id'])->name . ')',
                'user_id'           => auth()->id(),
            ]);

            DB::commit();
            return response()->json($tx->load('item'), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
