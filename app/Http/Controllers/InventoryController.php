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
        $items = InventoryItem::with('category', 'vendor', 'purchaseUom', 'issueUom', 'tax', 'locations')->orderBy('name')->get();
        $sums = DB::table('inventory_item_locations')
            ->whereIn('inventory_item_id', $items->pluck('id'))
            ->groupBy('inventory_item_id')
            ->selectRaw('inventory_item_id, COALESCE(SUM(quantity), 0) as total')
            ->pluck('total', 'inventory_item_id');

        foreach ($items as $item) {
            $item->setAttribute('current_stock', (int) round((float) ($sums[$item->id] ?? 0)));
        }

        return response()->json($items);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:100|unique:inventory_items,sku',
            'category_id' => 'required|exists:inventory_categories,id',
            'tax_id' => 'nullable|exists:inventory_taxes,id',
            'purchase_uom_id' => 'required|exists:inventory_uoms,id',
            'issue_uom_id' => 'required|exists:inventory_uoms,id',
            'conversion_factor' => 'required|numeric|min:0.01',
            'vendor_id' => 'nullable|exists:vendors,id',
            'cost_price' => 'nullable|numeric|min:0',
            'reorder_level' => 'nullable|integer|min:0',
            'current_stock' => 'nullable|integer|min:0',
            'is_direct_sale' => 'nullable|boolean',
            'description' => 'nullable|string',
        ]);

        $validated['is_direct_sale'] = (bool) ($validated['is_direct_sale'] ?? false);
        $item = InventoryItem::create($validated);

        $cf = floatval($item->conversion_factor ?: 1);
        $unitCost = $cf > 0 ? (floatval($validated['cost_price'] ?? 0) / $cf) : floatval($validated['cost_price'] ?? 0);
        $item->update(['cost_price' => round($unitCost, 4)]);

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
                    'unit_cost' => round($unitCost, 4),
                    'total_cost' => round($item->current_stock * $unitCost, 2),
                    'reason' => 'Initial Stock',
                    'user_id' => auth()->id(),
                ]);
            }
        }

        InventoryItem::syncStoredCurrentStockFromLocations($item->id);
        $item->refresh();

        return response()->json($item->load('category', 'vendor', 'purchaseUom', 'issueUom', 'tax', 'locations'), 201);
    }

    public function show(InventoryItem $item)
    {
        $item->load('category', 'vendor', 'purchaseUom', 'issueUom', 'tax', 'transactions');
        $item->setAttribute('current_stock', (int) round(InventoryItem::sumQuantityAcrossLocations($item->id)));

        return response()->json($item);
    }

    public function update(Request $request, InventoryItem $item)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:100|unique:inventory_items,sku,'.$item->id,
            'category_id' => 'required|exists:inventory_categories,id',
            'tax_id' => 'nullable|exists:inventory_taxes,id',
            'purchase_uom_id' => 'required|exists:inventory_uoms,id',
            'issue_uom_id' => 'required|exists:inventory_uoms,id',
            'conversion_factor' => 'required|numeric|min:0.01',
            'vendor_id' => 'nullable|exists:vendors,id',
            'cost_price' => 'nullable|numeric|min:0',
            'reorder_level' => 'nullable|integer|min:0',
            'current_stock' => 'nullable|integer|min:0',
            'is_direct_sale' => 'nullable|boolean',
            'description' => 'nullable|string',
        ]);

        $oldStock = $item->current_stock;
        $item->update($validated);

        // Standardize to Issue Unit Price
        $cf = floatval($item->conversion_factor ?: 1);
        $unitCost = $cf > 0 ? (floatval($validated['cost_price'] ?? 0) / $cf) : floatval($validated['cost_price'] ?? 0);
        $item->update(['cost_price' => round($unitCost, 4)]);

        // If manual stock edit, sync with Main Store
        if (isset($validated['current_stock']) && $validated['current_stock'] != $oldStock) {
            $mainStore = \App\Models\InventoryLocation::where('type', 'main_store')->first();
            if ($mainStore) {
                DB::table('inventory_item_locations')->updateOrInsert(
                    ['inventory_item_id' => $item->id, 'inventory_location_id' => $mainStore->id],
                    ['quantity' => $item->current_stock, 'updated_at' => now()]
                );

                $qtyDelta = abs($item->current_stock - $oldStock);
                InventoryTransaction::create([
                    'inventory_item_id' => $item->id,
                    'inventory_location_id' => $mainStore->id,
                    'type' => $item->current_stock > $oldStock ? 'in' : 'out',
                    'quantity' => $qtyDelta,
                    'unit_cost' => round($unitCost, 4),
                    'total_cost' => round($qtyDelta * $unitCost, 2),
                    'reason' => 'Manual Adjustment',
                    'notes' => 'Stock edited via Item Master',
                    'user_id' => auth()->id(),
                ]);
            }
        }

        InventoryItem::syncStoredCurrentStockFromLocations($item->id);

        return response()->json($item->load('category', 'vendor', 'purchaseUom', 'issueUom', 'tax'));
    }

    public function destroy(InventoryItem $item)
    {
        try {
            $item->delete();

            return response()->json(null, 204);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1451 || $e->getCode() == '23000') {
                return response()->json(['message' => 'Cannot delete inventory item as it has existing transactions or recipes. Please adjust stock to 0 or mark it inactive if supported.'], 409);
            }
            throw $e;
        }
    }

    public function stats()
    {
        $items = InventoryItem::with('category', 'vendor', 'purchaseUom', 'issueUom')->get();
        $sums = DB::table('inventory_item_locations')
            ->whereIn('inventory_item_id', $items->pluck('id'))
            ->groupBy('inventory_item_id')
            ->selectRaw('inventory_item_id, COALESCE(SUM(quantity), 0) as total')
            ->pluck('total', 'inventory_item_id');

        $qty = fn (InventoryItem $i) => (float) ($sums[$i->id] ?? 0);

        $totalValue = $items->sum(fn ($i) => $qty($i) * ($i->cost_price / ($i->conversion_factor ?: 1)));
        $lowStockCount = $items->filter(fn ($i) => $qty($i) <= (float) $i->reorder_level)->count();
        $recentTx = InventoryTransaction::with(['item', 'location'])->latest()->take(10)->get();

        return response()->json([
            'total_items' => $items->count(),
            'total_value' => $totalValue,
            'low_stock_count' => $lowStockCount,
            'recent_transactions' => $recentTx,
        ]);
    }

    public function issue(Request $request)
    {
        $validated = $request->validate([
            'item_id' => 'required|exists:inventory_items,id',
            'location_id' => 'required|exists:inventory_locations,id',
            'quantity' => 'required|numeric|min:0.01',
            'department_id' => 'required|exists:departments,id',
            'notes' => 'nullable|string',
        ]);

        $item = \App\Models\InventoryItem::findOrFail($validated['item_id']);
        $dept = \App\Models\Department::findOrFail($validated['department_id']);

        // Check stock at location
        $locationStock = DB::table('inventory_item_locations')
            ->where('inventory_item_id', $item->id)
            ->where('inventory_location_id', $validated['location_id'])
            ->first();

        if (! $locationStock || $locationStock->quantity < $validated['quantity']) {
            return response()->json(['message' => 'Insufficient stock in this location'], 422);
        }

        DB::beginTransaction();
        try {
            // 1. Decrement Location Stock (source of truth)
            DB::table('inventory_item_locations')
                ->where('inventory_item_id', $item->id)
                ->where('inventory_location_id', $validated['location_id'])
                ->decrement('quantity', $validated['quantity']);

            // 3. Log Transaction (with unit_cost for auditing)
            $unitCost = floatval($item->cost_price ?? 0);
            $qty = (float) $validated['quantity'];
            $tx = \App\Models\InventoryTransaction::create([
                'inventory_item_id' => $item->id,
                'inventory_location_id' => $validated['location_id'],
                'department_id' => $dept->id,
                'type' => 'out',
                'quantity' => $qty,
                'unit_cost' => round($unitCost, 4),
                'total_cost' => round($qty * $unitCost, 2),
                'department' => $dept->name, // Keep for legacy
                'reason' => 'Consumption',
                'notes' => ($validated['notes'] ?? '').' (Consumed from '.\App\Models\InventoryLocation::find($validated['location_id'])->name.')',
                'user_id' => auth()->id(),
            ]);

            InventoryItem::syncStoredCurrentStockFromLocations($item->id);

            DB::commit();

            return response()->json($tx->load('item'), 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
