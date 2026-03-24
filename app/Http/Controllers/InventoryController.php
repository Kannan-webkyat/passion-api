<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    private function checkPermission(string $permission)
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index()
    {
        $items = InventoryItem::with('category', 'vendor', 'purchaseUom', 'issueUom', 'tax', 'locations')->orderBy('name')->get();
        $sums = DB::table('inventory_item_locations')
            ->whereIn('inventory_item_id', $items->pluck('id'))
            ->groupBy('inventory_item_id')
            ->selectRaw('inventory_item_id, COALESCE(SUM(quantity), 0) as total')
            ->pluck('total', 'inventory_item_id');

        foreach ($items as $item) {
            $item->setAttribute('current_stock', (float) ($sums[$item->id] ?? 0));
        }

        return response()->json($items);
    }

    public function store(Request $request)
    {
        $this->checkPermission('manage-inventory');
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
            'reorder_level' => 'nullable|numeric|min:0',
            'current_stock' => 'nullable|numeric|min:0',
            'is_direct_sale' => 'nullable|boolean',
            'description' => 'nullable|string',
        ]);

        $validated['is_direct_sale'] = (bool) ($validated['is_direct_sale'] ?? false);
        $item = InventoryItem::create($validated);

        $unitCost = round(floatval($validated['cost_price'] ?? 0), 4);
        $item->update(['cost_price' => $unitCost]);

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
        $item->setAttribute('current_stock', (float) InventoryItem::sumQuantityAcrossLocations($item->id));

        return response()->json($item);
    }

    public function update(Request $request, InventoryItem $item)
    {
        $this->checkPermission('manage-inventory');
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
            'reorder_level' => 'nullable|numeric|min:0',
            'current_stock' => 'nullable|numeric|min:0',
            'is_direct_sale' => 'nullable|boolean',
            'description' => 'nullable|string',
        ]);

        $oldStock = $item->current_stock;
        $item->update($validated);

        // Keep as Purchase Unit Price
        $unitCost = round(floatval($validated['cost_price'] ?? 0), 4);
        $item->update(['cost_price' => $unitCost]);

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
        $this->checkPermission('manage-inventory');
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
        $this->checkPermission('manage-inventory');
        $validated = $request->validate([
            'item_id'        => 'required|exists:inventory_items,id',
            'location_id'    => 'required|exists:inventory_locations,id',
            'quantity'       => 'required|numeric|min:0.01',
            'to_location_id' => 'nullable|exists:inventory_locations,id',
            'notes'          => 'nullable|string',
        ]);

        $item        = \App\Models\InventoryItem::findOrFail($validated['item_id']);
        $sourceLocation = \App\Models\InventoryLocation::findOrFail($validated['location_id']);
        $destLocation   = isset($validated['to_location_id'])
            ? \App\Models\InventoryLocation::find($validated['to_location_id'])
            : null;

        DB::beginTransaction();
        try {
            // 1. Ensure source row exists (supports negative stock)
            DB::table('inventory_item_locations')->updateOrInsert(
                ['inventory_item_id' => $item->id, 'inventory_location_id' => $sourceLocation->id],
                ['updated_at' => now(), 'created_at' => now()]
            );

            // 2. Decrement source location stock
            DB::table('inventory_item_locations')
                ->where('inventory_item_id', $item->id)
                ->where('inventory_location_id', $sourceLocation->id)
                ->decrement('quantity', $validated['quantity']);

            $unitCost = floatval($item->cost_price ?? 0) / floatval($item->conversion_factor ?: 1);
            $qty      = (float) $validated['quantity'];
            $refId    = (string) \Illuminate\Support\Str::uuid();

            // 3. Log OUT Transaction
            $outTx = \App\Models\InventoryTransaction::create([
                'inventory_item_id'    => $item->id,
                'inventory_location_id'=> $sourceLocation->id,
                'type'                 => 'out',
                'quantity'             => $qty,
                'unit_cost'            => round($unitCost, 4),
                'total_cost'           => round($qty * $unitCost, 2),
                'reason'               => $destLocation ? 'Transfer' : 'Consumption',
                'notes'                => $validated['notes'] ?? ($destLocation ? "Transfer to {$destLocation->name}" : "Manual consumption"),
                'user_id'              => auth()->id(),
                'reference_id'         => $refId,
                'reference_type'       => 'requisition',
            ]);

            // 4. Handle Transfer (Increment Destination)
            if ($destLocation) {
                DB::table('inventory_item_locations')->updateOrInsert(
                    ['inventory_item_id' => $item->id, 'inventory_location_id' => $destLocation->id],
                    ['updated_at' => now(), 'created_at' => now()]
                );
                DB::table('inventory_item_locations')
                    ->where('inventory_item_id', $item->id)
                    ->where('inventory_location_id', $destLocation->id)
                    ->increment('quantity', $qty);

                \App\Models\InventoryTransaction::create([
                    'inventory_item_id'    => $item->id,
                    'inventory_location_id'=> $destLocation->id,
                    'type'                 => 'in',
                    'quantity'             => $qty,
                    'unit_cost'            => round($unitCost, 4),
                    'total_cost'           => round($qty * $unitCost, 2),
                    'reason'               => 'Transfer',
                    'notes'                => "Received from {$sourceLocation->name}",
                    'user_id'              => auth()->id(),
                    'reference_id'         => $refId,
                    'reference_type'       => 'requisition',
                ]);
            }

            InventoryItem::syncStoredCurrentStockFromLocations($item->id);

            DB::commit();

            return response()->json($outTx->load('item'), 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Stock adjustment (add or reduce) at a specific location.
     * For wastage, components in fridge, assembled from fridge, corrections, etc.
     */
    public function adjustStock(Request $request)
    {
        $this->checkPermission('manage-inventory');

        $validated = $request->validate([
            'inventory_item_id' => 'required|exists:inventory_items,id',
            'inventory_location_id' => 'required|exists:inventory_locations,id',
            'quantity' => 'required|numeric',
            'reason' => 'required|string|in:Wastage,Expired,Breakage,Theft,Staff meal,Manual Adjustment,Correction,Components Stored,Assembled from Storage',
            'notes' => 'nullable|string|max:500',
        ]);

        $item = InventoryItem::findOrFail($validated['inventory_item_id']);
        $location = \App\Models\InventoryLocation::findOrFail($validated['inventory_location_id']);
        $qty = (float) $validated['quantity'];

        if ($qty == 0) {
            return response()->json(['message' => 'Quantity cannot be zero.'], 422);
        }

        $isReduce = $qty < 0;
        $qtyAbs = abs($qty);

        $unitCost = floatval($item->cost_price ?? 0) / floatval($item->conversion_factor ?: 1);
        $lineCost = round($qtyAbs * $unitCost, 2);

        DB::beginTransaction();
        try {
            DB::table('inventory_item_locations')->updateOrInsert(
                ['inventory_item_id' => $item->id, 'inventory_location_id' => $location->id],
                ['updated_at' => now(), 'created_at' => now()]
            );

            if ($isReduce) {
                DB::table('inventory_item_locations')
                    ->where('inventory_item_id', $item->id)
                    ->where('inventory_location_id', $location->id)
                    ->decrement('quantity', $qtyAbs);
            } else {
                DB::table('inventory_item_locations')
                    ->where('inventory_item_id', $item->id)
                    ->where('inventory_location_id', $location->id)
                    ->increment('quantity', $qtyAbs);
            }

            InventoryTransaction::create([
                'inventory_item_id' => $item->id,
                'inventory_location_id' => $location->id,
                'type' => $isReduce ? 'out' : 'in',
                'quantity' => $qtyAbs,
                'unit_cost' => round($unitCost, 4),
                'total_cost' => $lineCost,
                'reason' => $validated['reason'],
                'notes' => $validated['notes'] ?? ($isReduce ? 'Stock reduced' : 'Stock added'),
                'user_id' => auth()->id(),
            ]);

            InventoryItem::syncStoredCurrentStockFromLocations($item->id);

            DB::commit();

            return response()->json([
                'message' => $isReduce ? 'Stock reduced successfully.' : 'Stock added successfully.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
