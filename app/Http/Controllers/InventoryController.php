<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTransaction;
use App\Models\InventoryTax;
use App\Exceptions\LiquorTaxValidationException;
use App\Services\LiquorTaxValidator;
use App\Services\Accounting\InventoryAdjustmentPoster;
use App\Services\Accounting\InventoryConsumptionPoster;
use App\Services\Accounting\LedgerBackedTransaction;
use App\Services\InventoryAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryController extends Controller
{
    /** Positive quantity always reduces stock (units lost). */
    private const STOCK_OUT_REASONS = [
        'Wastage',
        'Expired',
        'Breakage',
        'Theft',
        'Staff meal',
    ];

    /**
     * Cost per issue UOM from purchase-UOM cost (matches GRN, POS deductions, stock valuation report).
     * cost_price = per purchase unit; conversion_factor = issue units per 1 purchase unit.
     */
    private function issueUnitCostFromPurchaseFields(float $costPricePerPurchaseUnit, float $conversionFactor): float
    {
        $cf = $conversionFactor > 0 ? $conversionFactor : 1.0;

        return round($costPricePerPurchaseUnit / $cf, 4);
    }

    private function checkPermission(string $permission)
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    /** @param  array<string, mixed>  $validated */
    private function normalizeAlcoholLiquorFields(array &$validated): void
    {
        $validated['is_alcohol'] = (bool) ($validated['is_alcohol'] ?? false);
        $validated['is_cess_applicable'] = (bool) ($validated['is_cess_applicable'] ?? false);

        if (! $validated['is_alcohol']) {
            $validated['is_cess_applicable'] = false;
            $validated['liquor_category'] = null;
            $validated['cess_amount'] = null;

            return;
        }

        $validated['tax_id'] = null;
        $validated['is_direct_sale'] = true;
        $validated['is_prepared_item'] = false;

        if (! $validated['is_cess_applicable']) {
            $validated['cess_amount'] = null;
        }
    }

    /** @param  array<string, mixed>  $validated */
    private function validateItemMasterTaxMapping(array $validated): void
    {
        $tax = isset($validated['tax_id'])
            ? InventoryTax::query()->find($validated['tax_id'])
            : null;

        LiquorTaxValidator::validateItemMasterTax(
            (bool) $validated['is_alcohol'],
            $tax?->type,
            $validated['name'] ?? null
        );
    }

    /** Empty or whitespace-only SKU is stored as null (multiple nulls allowed under unique). */
    private function mergeNormalizedSku(Request $request): void
    {
        if (! $request->has('sku')) {
            return;
        }
        $raw = $request->input('sku');
        $normalized = (is_string($raw) && trim($raw) !== '') ? trim($raw) : null;
        $request->merge(['sku' => $normalized]);
    }

    public function index()
    {
        InventoryAuthorization::assertViewCatalog();

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
        $this->mergeNormalizedSku($request);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|max:100|unique:inventory_items,sku',
            'category_id' => 'required|exists:inventory_categories,id',
            'tax_id' => 'nullable|exists:inventory_taxes,id',
            'purchase_uom_id' => 'required|exists:inventory_uoms,id',
            'issue_uom_id' => 'required|exists:inventory_uoms,id',
            'conversion_factor' => 'required|numeric|min:0.01',
            'vendor_id' => 'nullable|exists:vendors,id',
            'cost_price' => 'nullable|numeric|min:0',
            'inspection_penalty_charge' => 'nullable|numeric|min:0',
            'reorder_level' => 'nullable|numeric|min:0',
            'is_direct_sale' => 'nullable|boolean',
            'is_prepared_item' => 'nullable|boolean',
            'is_alcohol' => 'nullable|boolean',
            'is_cess_applicable' => 'nullable|boolean',
            'cess_amount' => 'nullable|numeric|min:0',
            'liquor_category' => 'nullable|string|max:32',
            'description' => 'nullable|string',
        ]);

        $validated['is_direct_sale'] = (bool) ($validated['is_direct_sale'] ?? false);
        $validated['is_prepared_item'] = (bool) ($validated['is_prepared_item'] ?? false);
        $this->normalizeAlcoholLiquorFields($validated);

        try {
            $this->validateItemMasterTaxMapping($validated);
        } catch (LiquorTaxValidationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($request->has('current_stock') && (float) $request->input('current_stock') > 0) {
            return response()->json([
                'message' => 'Do not set stock when creating an item. After saving, use Adjust Stock → Opening Stock.',
            ], 422);
        }

        $validated['cost_price'] = round((float) ($validated['cost_price'] ?? 0), 4);
        $validated['inspection_penalty_charge'] = round((float) ($validated['inspection_penalty_charge'] ?? 0), 2);
        $validated['current_stock'] = 0;
        $item = InventoryItem::create($validated);

        InventoryItem::syncStoredCurrentStockFromLocations($item->id);
        $item->refresh();

        return response()->json($item->load('category', 'vendor', 'purchaseUom', 'issueUom', 'tax', 'locations'), 201);
    }

    public function show(InventoryItem $item)
    {
        InventoryAuthorization::assertViewCatalog();

        $item->load('category', 'vendor', 'purchaseUom', 'issueUom', 'tax', 'transactions');
        $item->setAttribute('current_stock', (float) InventoryItem::sumQuantityAcrossLocations($item->id));

        return response()->json($item);
    }

    public function update(Request $request, InventoryItem $item)
    {
        $this->checkPermission('manage-inventory');
        $this->mergeNormalizedSku($request);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:100|unique:inventory_items,sku,' . $item->id,
            'category_id' => 'required|exists:inventory_categories,id',
            'tax_id' => 'nullable|exists:inventory_taxes,id',
            'purchase_uom_id' => 'required|exists:inventory_uoms,id',
            'issue_uom_id' => 'required|exists:inventory_uoms,id',
            'conversion_factor' => 'required|numeric|min:0.01',
            'vendor_id' => 'nullable|exists:vendors,id',
            'cost_price' => 'nullable|numeric|min:0',
            'inspection_penalty_charge' => 'nullable|numeric|min:0',
            'reorder_level' => 'nullable|numeric|min:0',
            'is_direct_sale' => 'nullable|boolean',
            'is_prepared_item' => 'nullable|boolean',
            'is_alcohol' => 'nullable|boolean',
            'is_cess_applicable' => 'nullable|boolean',
            'cess_amount' => 'nullable|numeric|min:0',
            'liquor_category' => 'nullable|string|max:32',
            'description' => 'nullable|string',
        ]);

        $validated['is_direct_sale'] = (bool) ($validated['is_direct_sale'] ?? false);
        $validated['is_prepared_item'] = (bool) ($validated['is_prepared_item'] ?? false);
        $this->normalizeAlcoholLiquorFields($validated);

        try {
            $this->validateItemMasterTaxMapping($validated);
        } catch (LiquorTaxValidationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($request->has('current_stock')) {
            $requestedStock = (float) $request->input('current_stock');
            $actualStock = InventoryItem::sumQuantityAcrossLocations($item->id);
            if (abs($requestedStock - $actualStock) > 1e-6) {
                return response()->json([
                    'message' => 'Stock cannot be changed from the item form. Use Adjust Stock (Opening Stock for first count, or Correction / Wastage for changes).',
                ], 422);
            }
        }

        $validated['cost_price'] = round((float) ($validated['cost_price'] ?? 0), 4);
        $validated['inspection_penalty_charge'] = round((float) ($validated['inspection_penalty_charge'] ?? 0), 2);
        unset($validated['current_stock']);
        $item->update($validated);

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
        InventoryAuthorization::assertViewCatalog();

        // Aggregate in SQL: hydrating every item + relations here duplicated the
        // /inventory/items payload on each dashboard load.
        $locationSums = DB::table('inventory_item_locations')
            ->selectRaw('inventory_item_id, SUM(quantity) as qty')
            ->groupBy('inventory_item_id');

        $totals = DB::table('inventory_items as i')
            ->leftJoinSub($locationSums, 'l', 'l.inventory_item_id', '=', 'i.id')
            ->selectRaw('COUNT(*) as total_items')
            ->selectRaw('COALESCE(SUM(COALESCE(l.qty, 0) * COALESCE(i.cost_price, 0) / IF(COALESCE(i.conversion_factor, 0) = 0, 1, i.conversion_factor)), 0) as total_value')
            ->selectRaw('SUM(CASE WHEN COALESCE(l.qty, 0) <= COALESCE(i.reorder_level, 0) THEN 1 ELSE 0 END) as low_stock_count')
            ->first();

        $recentTx = InventoryTransaction::with(['item', 'location'])->latest()->take(10)->get();

        return response()->json([
            'total_items' => (int) ($totals->total_items ?? 0),
            'total_value' => (float) ($totals->total_value ?? 0),
            'low_stock_count' => (int) ($totals->low_stock_count ?? 0),
            'recent_transactions' => $recentTx,
        ]);
    }

    public function issue(Request $request)
    {
        $this->checkPermission('manage-inventory');
        $validated = $request->validate([
            'item_id' => 'required|exists:inventory_items,id',
            'location_id' => 'required|exists:inventory_locations,id',
            'quantity' => 'required|numeric|min:0.01',
            'to_location_id' => 'nullable|exists:inventory_locations,id',
            'notes' => 'nullable|string',
        ]);

        $item = \App\Models\InventoryItem::findOrFail($validated['item_id']);
        $sourceLocation = \App\Models\InventoryLocation::findOrFail($validated['location_id']);
        $destLocation = isset($validated['to_location_id'])
            ? \App\Models\InventoryLocation::find($validated['to_location_id'])
            : null;
        $qty = (float) $validated['quantity'];

        DB::beginTransaction();
        try {
            $sourceStock = (float) (DB::table('inventory_item_locations')
                ->where('inventory_item_id', $item->id)
                ->where('inventory_location_id', $sourceLocation->id)
                ->lockForUpdate()
                ->value('quantity') ?? 0);

            if ($sourceStock + 1e-6 < $qty) {
                DB::rollBack();

                return response()->json([
                    'message' => "Insufficient stock at {$sourceLocation->name}. Available: {$sourceStock}, requested: {$qty}.",
                ], 422);
            }

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
            $refId = (string) \Illuminate\Support\Str::uuid();

            // 3. Log OUT Transaction
            $outTx = \App\Models\InventoryTransaction::create([
                'inventory_item_id'    => $item->id,
                'inventory_location_id' => $sourceLocation->id,
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
                    'inventory_location_id' => $destLocation->id,
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

            if (! $destLocation) {
                app(InventoryConsumptionPoster::class)->post($outTx, auth()->id());
            }

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
            'reason' => 'required|string|in:Opening Stock,Wastage,Expired,Breakage,Theft,Staff meal,Manual Adjustment,Correction,Components Stored,Assembled from Storage',
            'notes' => 'nullable|string|max:500',
        ]);

        $item = InventoryItem::findOrFail($validated['inventory_item_id']);
        $location = \App\Models\InventoryLocation::findOrFail($validated['inventory_location_id']);
        $qty = (float) $validated['quantity'];

        if ($qty == 0) {
            return response()->json(['message' => 'Quantity cannot be zero.'], 422);
        }

        if ($qty > 0 && $validated['reason'] === 'Manual Adjustment') {
            $onHand = InventoryItem::sumQuantityAcrossLocations($item->id);
            if ($onHand <= 0) {
                return response()->json([
                    'message' => 'For the first stock entry use reason Opening Stock, not Manual Adjustment.',
                ], 422);
            }
        }

        if (in_array($validated['reason'], self::STOCK_OUT_REASONS, true)) {
            if ($qty < 0) {
                return response()->json([
                    'message' => 'For '.$validated['reason'].', enter the number of units lost as a positive quantity (e.g. 1).',
                ], 422);
            }
            $isReduce = true;
            $qtyAbs = $qty;
        } else {
            $isReduce = $qty < 0;
            $qtyAbs = abs($qty);
        }

        $unitCost = floatval($item->cost_price ?? 0) / floatval($item->conversion_factor ?: 1);
        $lineCost = round($qtyAbs * $unitCost, 2);

        try {
            app(LedgerBackedTransaction::class)->run(
                mutate: function () use ($item, $location, $isReduce, $qtyAbs, $unitCost, $lineCost, $validated) {
                    DB::table('inventory_item_locations')->updateOrInsert(
                        ['inventory_item_id' => $item->id, 'inventory_location_id' => $location->id],
                        ['updated_at' => now(), 'created_at' => now()]
                    );

                    if ($isReduce) {
                        $available = (float) (DB::table('inventory_item_locations')
                            ->where('inventory_item_id', $item->id)
                            ->where('inventory_location_id', $location->id)
                            ->lockForUpdate()
                            ->value('quantity') ?? 0);

                        if ($available + 1e-6 < $qtyAbs) {
                            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                                response()->json([
                                    'message' => "Insufficient stock at {$location->name}. Available: {$available}, requested: {$qtyAbs}.",
                                ], 422)
                            );
                        }

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

                    $transaction = InventoryTransaction::create([
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

                    return $transaction;
                },
                postJournal: fn (InventoryTransaction $transaction) => app(InventoryAdjustmentPoster::class)->postStrict($transaction, auth()->id()),
                journalRequired: fn (InventoryTransaction $transaction) => app(InventoryAdjustmentPoster::class)->isJournalRequired($transaction),
            );

            return response()->json([
                'message' => $isReduce ? 'Stock reduced successfully.' : 'Stock added successfully.',
            ]);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Single linked "recovery / breakdown" posting: consume a source SKU at a location,
     * optionally add recovered SKUs and post wastage lines — all share one reference_id.
     */
    public function recoveryBreakdown(Request $request)
    {
        $this->checkPermission('manage-inventory');

        $validated = $request->validate([
            'inventory_location_id' => 'required|exists:inventory_locations,id',
            'source_inventory_item_id' => 'required|exists:inventory_items,id',
            'source_quantity' => 'required|numeric|min:0.001',
            'recovered' => 'nullable|array',
            'recovered.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'recovered.*.quantity' => 'required|numeric|min:0.001',
            'wasted' => 'nullable|array',
            'wasted.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'wasted.*.quantity' => 'required|numeric|min:0.001',
            'notes' => 'nullable|string|max:500',
        ]);

        $location = InventoryLocation::findOrFail($validated['inventory_location_id']);
        $sourceId = (int) $validated['source_inventory_item_id'];
        $sourceQty = (float) $validated['source_quantity'];

        $recovered = $this->mergeRecoveryLines($validated['recovered'] ?? []);
        $wasted = $this->mergeRecoveryLines($validated['wasted'] ?? []);

        if ($recovered->isEmpty() && $wasted->isEmpty()) {
            return response()->json([
                'message' => 'Add at least one recovered or wasted line (quantities going back to stock or to wastage).',
            ], 422);
        }

        foreach ($recovered->pluck('inventory_item_id') as $rid) {
            if ($rid === $sourceId) {
                return response()->json([
                    'message' => 'Recovered lines cannot use the same inventory item as the source.',
                ], 422);
            }
        }
        foreach ($wasted->pluck('inventory_item_id') as $wid) {
            if ($wid === $sourceId) {
                return response()->json([
                    'message' => 'Wasted lines cannot use the same inventory item as the source.',
                ], 422);
            }
        }

        $recoveredIds = $recovered->pluck('inventory_item_id')->all();
        $wastedIds = $wasted->pluck('inventory_item_id')->all();
        if (count(array_intersect($recoveredIds, $wastedIds)) > 0) {
            return response()->json([
                'message' => 'The same inventory item cannot appear in both recovered and wasted lines.',
            ], 422);
        }

        $locId = (int) $location->id;
        $available = (float) (DB::table('inventory_item_locations')
            ->where('inventory_item_id', $sourceId)
            ->where('inventory_location_id', $locId)
            ->value('quantity') ?? 0);

        if ($available + 1e-6 < $sourceQty) {
            return response()->json([
                'message' => 'Insufficient stock at this location for the source item.',
                'required' => $sourceQty,
                'available' => $available,
            ], 422);
        }

        foreach ($wasted as $row) {
            $wasteItemId = (int) $row['inventory_item_id'];
            $wasteQty = (float) $row['quantity'];
            $wasteAvail = (float) (DB::table('inventory_item_locations')
                ->where('inventory_item_id', $wasteItemId)
                ->where('inventory_location_id', $locId)
                ->value('quantity') ?? 0);
            if ($wasteAvail + 1e-6 < $wasteQty) {
                return response()->json([
                    'message' => 'Insufficient stock at this location for a wasted line.',
                    'inventory_item_id' => $wasteItemId,
                    'required' => $wasteQty,
                    'available' => $wasteAvail,
                ], 422);
            }
        }

        $refId = (string) Str::uuid();
        $refType = 'recovery_breakdown';
        $userNote = trim((string) ($validated['notes'] ?? ''));
        $noteSuffix = $userNote !== '' ? "{$userNote} | Ref {$refId}" : "Ref {$refId}";

        $affectedIds = [];
        $postedTxIds = [];

        try {
            DB::transaction(function () use ($sourceId, $sourceQty, $locId, $location, $recovered, $wasted, $refId, $refType, $noteSuffix, &$affectedIds, &$postedTxIds) {
                $postedTxIds[] = $this->recoveryDecrementLocation($sourceId, $locId, $sourceQty, $location->department_id, $refId, $refType, 'Recovery: source consumed', $noteSuffix);
                $affectedIds[] = $sourceId;

                foreach ($wasted as $row) {
                    $id = (int) $row['inventory_item_id'];
                    $qty = (float) $row['quantity'];
                    $postedTxIds[] = $this->recoveryDecrementLocation($id, $locId, $qty, $location->department_id, $refId, $refType, 'Wastage', $noteSuffix);
                    $affectedIds[] = $id;
                }

                foreach ($recovered as $row) {
                    $id = (int) $row['inventory_item_id'];
                    $qty = (float) $row['quantity'];
                    $this->recoveryIncrementLocation($id, $locId, $qty, $location->department_id, $refId, $refType, 'Recovery: returned to stock', $noteSuffix);
                    $affectedIds[] = $id;
                }
            });

            foreach (array_unique($affectedIds) as $itemId) {
                InventoryItem::syncStoredCurrentStockFromLocations($itemId);
            }

            $consumptionPoster = app(InventoryConsumptionPoster::class);
            foreach (array_filter($postedTxIds) as $txId) {
                $tx = InventoryTransaction::find($txId);
                if ($tx && $tx->reason === 'Wastage') {
                    $consumptionPoster->post($tx, auth()->id());
                }
            }
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'Recovery / breakdown recorded.',
            'reference_id' => $refId,
            'reference_type' => $refType,
        ], 201);
    }

    /**
     * @param  array<int, array{inventory_item_id?: mixed, quantity?: mixed}>  $rows
     * @return \Illuminate\Support\Collection<int, array{inventory_item_id: int, quantity: float}>
     */
    private function mergeRecoveryLines(array $rows)
    {
        $map = [];
        foreach ($rows as $row) {
            $id = (int) ($row['inventory_item_id'] ?? 0);
            $q = (float) ($row['quantity'] ?? 0);
            if ($id < 1 || $q <= 0) {
                continue;
            }
            $map[$id] = ($map[$id] ?? 0) + $q;
        }

        return collect($map)->map(fn (float $qty, int $id) => [
            'inventory_item_id' => $id,
            'quantity' => round($qty, 4),
        ])->values();
    }

    private function recoveryDecrementLocation(
        int $inventoryItemId,
        int $locationId,
        float $qtyAbs,
        ?int $departmentId,
        string $refId,
        string $refType,
        string $reason,
        string $notes
    ): int {
        $item = InventoryItem::findOrFail($inventoryItemId);
        $unitCost = floatval($item->cost_price ?? 0) / floatval($item->conversion_factor ?: 1);
        $lineCost = round($qtyAbs * $unitCost, 2);

        DB::table('inventory_item_locations')->updateOrInsert(
            ['inventory_item_id' => $inventoryItemId, 'inventory_location_id' => $locationId],
            ['updated_at' => now(), 'created_at' => now()]
        );

        DB::table('inventory_item_locations')
            ->where('inventory_item_id', $inventoryItemId)
            ->where('inventory_location_id', $locationId)
            ->decrement('quantity', $qtyAbs);

        $tx = InventoryTransaction::create([
            'inventory_item_id' => $inventoryItemId,
            'inventory_location_id' => $locationId,
            'department_id' => $departmentId,
            'type' => 'out',
            'quantity' => $qtyAbs,
            'unit_cost' => round($unitCost, 4),
            'total_cost' => $lineCost,
            'reason' => $reason,
            'notes' => $notes,
            'user_id' => auth()->id(),
            'reference_id' => $refId,
            'reference_type' => $refType,
        ]);

        return (int) $tx->id;
    }

    private function recoveryIncrementLocation(
        int $inventoryItemId,
        int $locationId,
        float $qtyAbs,
        ?int $departmentId,
        string $refId,
        string $refType,
        string $reason,
        string $notes
    ): void {
        $item = InventoryItem::findOrFail($inventoryItemId);
        $unitCost = floatval($item->cost_price ?? 0) / floatval($item->conversion_factor ?: 1);
        $lineCost = round($qtyAbs * $unitCost, 2);

        DB::table('inventory_item_locations')->updateOrInsert(
            ['inventory_item_id' => $inventoryItemId, 'inventory_location_id' => $locationId],
            ['updated_at' => now(), 'created_at' => now()]
        );

        DB::table('inventory_item_locations')
            ->where('inventory_item_id', $inventoryItemId)
            ->where('inventory_location_id', $locationId)
            ->increment('quantity', $qtyAbs);

        InventoryTransaction::create([
            'inventory_item_id' => $inventoryItemId,
            'inventory_location_id' => $locationId,
            'department_id' => $departmentId,
            'type' => 'in',
            'quantity' => $qtyAbs,
            'unit_cost' => round($unitCost, 4),
            'total_cost' => $lineCost,
            'reason' => $reason,
            'notes' => $notes,
            'user_id' => auth()->id(),
            'reference_id' => $refId,
            'reference_type' => $refType,
        ]);
    }
}
