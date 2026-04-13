<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\InventoryTransaction;
use App\Models\PurchaseOrder;
use App\Models\PosOrderItem;
use App\Models\Vendor;

class InventoryReportController extends Controller
{
    private function checkPermission(string $permission): void
    {
        $user = auth()->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }
        if ($user->hasRole('Admin') || $user->hasRole('Super Admin')) {
            return;
        }

        // Backward compatibility: inventory managers can access all inventory reports.
        if ($user->can('manage-inventory')) {
            return;
        }

        // Dashboard permission is treated as umbrella for inventory reports.
        if ($user->can('inventory-report-summary')) {
            return;
        }

        if (! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    /**
     * Stock Status & Valuation Report
     */
    public function stockStatus(Request $request)
    {
        $this->checkPermission('inventory-report-status');
        $categoryId = $request->query('category_id');
        $locationId = $request->query('location_id');
        $search = $request->query('search');

        // Note: Joining is needed for sorting by category name.
        // We use 'category_id' as verified by tinker.
        $query = InventoryItem::with(['category', 'issueUom'])
            ->leftJoin('inventory_categories', 'inventory_items.category_id', '=', 'inventory_categories.id')
            ->select('inventory_items.*');

        if ($categoryId && $categoryId !== 'all') {
            $query->where('inventory_items.category_id', $categoryId);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('inventory_items.name', 'like', "%{$search}%")
                  ->orWhere('inventory_items.sku', 'like', "%{$search}%");
            });
        }

        $items = $query->orderBy('inventory_categories.name')->orderBy('inventory_items.name')->get();
        $locations = InventoryLocation::all();

        // Cross-tabulate stock from inventory_item_locations
        $stockData = DB::table('inventory_item_locations')
            ->select('inventory_item_id', 'inventory_location_id', 'quantity')
            ->get()
            ->groupBy('inventory_item_id');

        $report = $items->map(function ($item) use ($stockData, $locations, $locationId) {
            $itemStocks = $stockData->get($item->id) ?? collect();
            
            $locationBreakdown = [];
            $totalQty = 0;

            foreach ($locations as $loc) {
                $qty = (float) ($itemStocks->where('inventory_location_id', $loc->id)->first()?->quantity ?? 0);
                $locationBreakdown[$loc->id] = $qty;
                
                if (!$locationId || $locationId == $loc->id) {
                    $totalQty += $qty;
                }
            }

            // Correct Valuation logic: current_stock * unit_cost
            $unitCost = (float) ($item->cost_price ?? 0) / (float) ($item->conversion_factor ?: 1);
            $valuationValue = $totalQty * $unitCost;

            return [
                'id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'category' => $item->category?->name ?? 'Uncategorized',
                'uom' => $item->issueUom?->short_name ?? 'unit',
                'unit_cost' => round($unitCost, 4),
                'total_qty' => round($totalQty, 3),
                'valuation' => round($valuationValue, 2),
                'is_low' => $totalQty <= ($item->reorder_level ?? 0),
                'location_stock' => $locationBreakdown
            ];
        });

        if ($locationId && $locationId !== 'all') {
            $report = $report->filter(fn($r) => $r['location_stock'][$locationId] != 0)->values();
        }

        return response()->json([
            'data' => $report,
            'summary' => [
                'total_items' => $report->count(),
                'total_valuation' => round($report->sum('valuation'), 2),
                'low_stock_count' => $report->where('is_low', true)->count(),
            ],
            'locations' => $locations,
            'categories' => InventoryCategory::all()
        ]);
    }

    /**
     * Stock Movement Ledger (Detailed History)
     */
    public function stockLedger(Request $request)
    {
        $this->checkPermission('inventory-report-ledger');
        $itemId = $request->query('item_id');
        $locationId = $request->query('location_id');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $query = InventoryTransaction::with(['item.issueUom', 'location', 'user'])
            ->orderBy('created_at', 'desc');

        if ($itemId) $query->where('inventory_item_id', $itemId);
        if ($locationId) $query->where('inventory_location_id', $locationId);
        if ($startDate) $query->whereDate('created_at', '>=', $startDate);
        if ($endDate) $query->whereDate('created_at', '<=', $endDate);

        $transactions = $query->paginate(50);

        return response()->json($transactions);
    }

    /**
     * Consumption Reconciliation: Theoretical (Sales × Recipe) vs actual issues (sum of `out`
     * transactions, excluding transfers/production/finished goods) minus POS void reversals (`in`
     * with reason Inventory Reversal). Purchase receipts and store receipts are not subtracted.
     */
    public function consumption(Request $request)
    {
        $this->checkPermission('inventory-report-consumption');
        $startDate = $request->query('from') ?? $request->query('start_date') ?? now()->subDays(7)->toDateString();
        $endDate = $request->query('to') ?? $request->query('end_date') ?? now()->toDateString();
        $locationId = $request->query('location_id');

        $salesItems = PosOrderItem::with([
                'menuItem.recipe.ingredients.inventoryItem', 
                'variant',
                'combo.menuItems.recipe.ingredients.inventoryItem',
                'combo.menuItems.variant',
                'order.restaurant'
            ])
            ->where('inventory_deducted', true)
            ->whereBetween('created_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);

        if ($locationId) {
            $salesItems->whereHas('order.restaurant', function($r) use ($locationId) {
                $r->where('kitchen_location_id', $locationId)
                  ->orWhere('bar_location_id', $locationId);
            });
        }

        $salesItems = $salesItems->get();

        $theoretical = []; 
        
        $processItem = function($orderItem, $parentQty = null, $restaurant = null) use (&$theoretical, $locationId) {
            $menuItem = $orderItem->menuItem;
            if (!$menuItem) return;

            $qty = $parentQty ?? $orderItem->quantity;
            $recipe = $menuItem->recipe;
            
            // Determine likely deduction location for this item
            $itemLocationId = ($menuItem->is_direct_sale && $restaurant && $restaurant->bar_location_id) 
                ? $restaurant->bar_location_id 
                : ($restaurant ? $restaurant->kitchen_location_id : null);

            // If we are filtering by a specific location, only count if it matches
            if ($locationId && $itemLocationId && $itemLocationId != $locationId) {
                return;
            }

            if ($recipe && $recipe->is_active && !($recipe->requires_production ?? true)) {
                $yield = max(1, (float)($recipe->yield_quantity ?? 1));
                // Apply variant portion-size scaling (matches POS deduction logic)
                $scale = 1.0;
                if ($orderItem->menu_item_variant_id && ($ml = (float)($orderItem->variant?->ml_quantity ?? 0)) > 0 && $ml <= 10) {
                    $scale = $ml;
                }
                $multiplier = ($qty * $scale) / $yield;
                foreach ($recipe->ingredients as $ing) {
                    $theoretical[$ing->inventory_item_id] = ($theoretical[$ing->inventory_item_id] ?? 0) + ($ing->raw_quantity * $multiplier);
                }
            } 
            elseif ($menuItem->inventory_item_id) {
                $deductQty = (float)$qty;
                if ($orderItem->menu_item_variant_id && ($ml = (float)($orderItem->variant?->ml_quantity ?? 0)) > 0) {
                    $deductQty = $ml * (float)$qty;
                }
                $theoretical[$menuItem->inventory_item_id] = ($theoretical[$menuItem->inventory_item_id] ?? 0) + $deductQty;
            }
        };

        foreach ($salesItems as $sale) {
            $restaurant = $sale->order?->restaurant;
            if ($sale->combo_id && $sale->combo) {
                foreach ($sale->combo->menuItems as $cmi) {
                    $dummy = (object)[
                        'menuItem' => $cmi,
                        'quantity' => $sale->quantity,
                        'menu_item_variant_id' => null,
                        'variant' => null
                    ];
                    $processItem($dummy, $sale->quantity, $restaurant);
                }
            } else {
                $processItem($sale, null, $restaurant);
            }
        }

        $consumptionOutReasonsExcluded = ['Transfer', 'Internal Issue', 'Production', 'Finished Goods'];

        $outsQuery = InventoryTransaction::whereDate('created_at', '>=', $startDate)
            ->whereDate('created_at', '<=', $endDate)
            ->where('type', 'out')
            ->whereNotIn('reason', $consumptionOutReasonsExcluded);

        if ($locationId) {
            $outsQuery->where('inventory_location_id', $locationId);
        }

        $outsData = $outsQuery->select('inventory_item_id', DB::raw('SUM(quantity) as total_qty'))
            ->groupBy('inventory_item_id')
            ->get();

        $reversalInsQuery = InventoryTransaction::whereDate('created_at', '>=', $startDate)
            ->whereDate('created_at', '<=', $endDate)
            ->where('type', 'in')
            ->where('reason', 'Inventory Reversal');

        if ($locationId) {
            $reversalInsQuery->where('inventory_location_id', $locationId);
        }

        $reversalInsData = $reversalInsQuery->select('inventory_item_id', DB::raw('SUM(quantity) as total_qty'))
            ->groupBy('inventory_item_id')
            ->get();

        $actuals = [];
        foreach ($outsData as $row) {
            $actuals[$row->inventory_item_id] = (float) $row->total_qty;
        }
        foreach ($reversalInsData as $row) {
            $id = $row->inventory_item_id;
            $actuals[$id] = ($actuals[$id] ?? 0) - (float) $row->total_qty;
        }

        $itemIds = collect($theoretical)->keys()->merge(collect($actuals)->keys())->unique();
        $items = InventoryItem::with(['issueUom', 'category'])->whereIn('id', $itemIds)->get();

        $report = $items->map(function($item) use ($theoretical, $actuals) {
            $theo = (float) ($theoretical[$item->id] ?? 0);
            $act  = (float) ($actuals[$item->id] ?? 0);
            $variance = $act - $theo;
            $variancePct = $theo > 1e-6 ? ($variance / $theo) * 100 : ($act > 1e-6 ? 100 : 0);
            $unitCost = (float) ($item->cost_price ?? 0) / (float) ($item->conversion_factor ?: 1);

            return [
                'id' => $item->id,
                'item_name' => $item->name,
                'sku' => $item->sku,
                'category' => $item->category?->name ?? 'Uncategorized',
                'uom' => $item->issueUom?->short_name ?? 'unit',
                'theoretical_usage' => round($theo, 3),
                'actual_usage' => round($act, 3),
                'variance' => round($variance, 3),
                'variance_percentage' => round($variancePct, 1),
                'cost_price' => round($unitCost, 2),
                'variance_value' => round($variance * $unitCost, 2)
            ];
        })->sortByDesc(fn($r) => abs($r['variance_value']))->values();

        return response()->json([
            'data' => $report,
            'summary' => [
                'total_theoretical_cost' => round($report->sum(fn($r) => $r['theoretical_usage'] * $r['cost_price']), 2),
                'total_actual_cost' => round($report->sum(fn($r) => $r['actual_usage'] * $r['cost_price']), 2),
                'total_variance_value' => round($report->sum('variance_value'), 2),
                'high_variance_count' => $report->filter(fn($r) => abs($r['variance_percentage'] ?? 0) > 10)->count(),
            ]
        ]);
    }

    /**
     * Wastage & Adjustments Report — reasons must match {@see InventoryController::adjustStock}.
     */
    public function adjustments(Request $request)
    {
        $this->checkPermission('inventory-report-adjustments');
        $reason = $request->query('reason');
        $startDate = $request->query('from') ?? $request->query('start_date');
        $endDate = $request->query('to') ?? $request->query('end_date');
        $search = trim((string) $request->query('search', ''));

        $adjustmentReasons = [
            'Wastage', 'Expired', 'Breakage', 'Theft', 'Staff meal',
            'Manual Adjustment', 'Correction', 'Components Stored', 'Assembled from Storage',
        ];

        $query = InventoryTransaction::with(['item.issueUom', 'user', 'location'])
            ->whereIn('reason', $adjustmentReasons)
            ->orderBy('created_at', 'desc');

        if ($reason && $reason !== 'all') {
            $query->where('reason', $reason);
        }
        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }
        if ($search !== '') {
            $term = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($q) use ($term) {
                $q->whereHas('item', function ($iq) use ($term) {
                    $iq->where('name', 'like', $term)
                        ->orWhere('sku', 'like', $term);
                })->orWhereHas('user', function ($uq) use ($term) {
                    $uq->where('name', 'like', $term);
                });
            });
        }

        $results = $query->get();

        $data = $results->map(function ($t) {
            return [
                'id' => $t->id,
                'inventory_item_id' => $t->inventory_item_id,
                'item_name' => $t->item?->name ?? 'Unknown',
                'sku' => $t->item?->sku ?? '-',
                'qty' => (float) $t->quantity,
                'transaction_type' => $t->type,
                'uom' => $t->item?->issueUom?->short_name ?? '-',
                'unit_cost' => (float) $t->unit_cost,
                'total_loss' => (float) $t->total_cost,
                'reason' => $t->reason,
                'location_name' => $t->location?->name ?? 'N/A',
                'user_name' => $t->user?->name ?? 'System',
                'created_at' => $t->created_at->toIso8601String(),
            ];
        });

        $outRows = $data->filter(fn (array $r) => ($r['transaction_type'] ?? '') === 'out');
        $inRows = $data->filter(fn (array $r) => ($r['transaction_type'] ?? '') === 'in');

        $summary = [
            'total_loss_value' => round((float) $outRows->sum('total_loss'), 2),
            'total_addition_value' => round((float) $inRows->sum('total_loss'), 2),
            'total_incidents' => $data->count(),
            'by_reason' => $data->groupBy('reason')->map(fn ($group) => [
                'count' => $group->count(),
                'value' => round((float) $group->sum('total_loss'), 2),
            ]),
        ];

        return response()->json([
            'data' => $data->values(),
            'summary' => $summary,
        ]);
    }

    /**
     * Purchase History & Price Trending (received PO lines; amounts prorated to quantity received).
     */
    public function purchaseHistory(Request $request)
    {
        $this->checkPermission('inventory-report-purchase-history');
        $vendorId = $request->query('vendor_id');
        $itemId = $request->query('item_id');
        $startDate = $request->query('from') ?? $request->query('start_date');
        $endDate = $request->query('to') ?? $request->query('end_date');
        $search = trim((string) $request->query('search', ''));

        $query = PurchaseOrder::with(['vendor', 'items.inventoryItem.issueUom'])
            ->where('status', 'received')
            ->orderBy('received_at', 'desc');

        if ($vendorId && $vendorId !== 'all') {
            $query->where('vendor_id', $vendorId);
        }
        if ($startDate) {
            $query->whereDate('received_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('received_at', '<=', $endDate);
        }

        $pos = $query->get();

        $flatItems = [];
        foreach ($pos as $po) {
            $receivedAt = $po->received_at;
            if ($receivedAt !== null) {
                $receivedAt = $receivedAt instanceof \DateTimeInterface
                    ? $receivedAt->format(\DateTimeInterface::ATOM)
                    : (string) $receivedAt;
            }

            foreach ($po->items as $pi) {
                if ($itemId && (int) $pi->inventory_item_id !== (int) $itemId) {
                    continue;
                }

                $qtyOrdered = (float) ($pi->quantity_ordered ?: 0);
                $qtyReceived = (float) $pi->quantity_received;
                // Vendor payable gross for received qty (supports partial receipts)
                $totalCost = (float) ($qtyOrdered > 0
                    ? ((float) ($pi->total_amount ?? 0)) * ($qtyReceived / $qtyOrdered)
                    : 0);

                $flatItems[] = [
                    'id' => $pi->id,
                    'po_number' => $po->po_number,
                    'received_at' => $receivedAt,
                    'vendor_name' => $po->vendor?->name ?? '—',
                    'item_id' => $pi->inventory_item_id,
                    'item_name' => $pi->inventoryItem?->name ?? '—',
                    'uom' => $pi->inventoryItem?->issueUom?->short_name ?? '—',
                    'qty' => $qtyReceived,
                    'unit_cost' => (float) $pi->unit_price,
                    'total_cost' => $totalCost,
                ];
            }
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $flatItems = array_values(array_filter($flatItems, function (array $row) use ($needle) {
                return str_contains(mb_strtolower((string) ($row['item_name'] ?? '')), $needle)
                    || str_contains(mb_strtolower((string) ($row['po_number'] ?? '')), $needle);
            }));
        }

        $collection = collect($flatItems);

        return response()->json([
            'data' => $flatItems,
            'summary' => [
                'total_spend' => round((float) $collection->sum('total_cost'), 2),
                'avg_unit_price' => $collection->isNotEmpty()
                    ? round((float) $collection->avg('unit_cost'), 4)
                    : 0,
            ],
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Reorder Planning Report — uses sum of location quantities (same source of truth as stock status),
     * not the cached inventory_items.current_stock column. Skips items with no reorder threshold.
     */
    public function reorderReport(Request $request)
    {
        $this->checkPermission('inventory-report-reorder');

        $candidateIds = DB::table('inventory_items as i')
            ->leftJoinSub(
                DB::table('inventory_item_locations')
                    ->selectRaw('inventory_item_id, SUM(quantity) as qty_sum')
                    ->groupBy('inventory_item_id'),
                'loc',
                'i.id',
                '=',
                'loc.inventory_item_id'
            )
            ->where('i.reorder_level', '>', 0)
            ->whereRaw('COALESCE(loc.qty_sum, 0) <= i.reorder_level')
            ->pluck('i.id');

        if ($candidateIds->isEmpty()) {
            return response()->json([
                'data' => [],
                'summary' => [
                    'items_to_reorder' => 0,
                    'total_reorder_cost' => 0,
                    'critical_shortfall' => 0,
                ],
            ]);
        }

        $qtyByItemId = DB::table('inventory_item_locations')
            ->whereIn('inventory_item_id', $candidateIds)
            ->selectRaw('inventory_item_id, SUM(quantity) as qty_sum')
            ->groupBy('inventory_item_id')
            ->pluck('qty_sum', 'inventory_item_id');

        $items = InventoryItem::with(['category', 'issueUom', 'vendor'])
            ->whereIn('id', $candidateIds)
            ->orderBy('name')
            ->get();

        $report = $items->map(function ($item) use ($qtyByItemId) {
            $onHand = (float) ($qtyByItemId[$item->id] ?? 0);
            $reorder = (float) $item->reorder_level;
            $suggestedOrder = max(0, (2 * $reorder) - $onHand);
            $issueUnitCost = (float) ($item->cost_price ?? 0) / (float) ($item->conversion_factor ?: 1);

            return [
                'id' => $item->id,
                'item_name' => $item->name,
                'sku' => $item->sku,
                'category' => $item->category?->name ?? 'Uncategorized',
                'uom' => $item->issueUom?->short_name ?? 'unit',
                'current_stock' => round($onHand, 3),
                'reorder_level' => $reorder,
                'suggested_order' => round($suggestedOrder, 2),
                'vendor_name' => $item->vendor?->name ?? 'Not Assigned',
                'estimated_cost' => round($suggestedOrder * $issueUnitCost, 2),
            ];
        })->values();

        return response()->json([
            'data' => $report,
            'summary' => [
                'items_to_reorder' => $report->count(),
                'total_reorder_cost' => round((float) $report->sum('estimated_cost'), 2),
                'critical_shortfall' => $report->where('current_stock', '<=', 0)->count(),
            ],
        ]);
    }

    /**
     * Slow-moving stock: positive on-hand (sum of locations) with no `out` transaction in the last N days.
     * Uses location totals, not inventory_items.current_stock. Omits zero on-hand SKUs (no tied-up capital).
     */
    public function slowMovingReport(Request $request)
    {
        $this->checkPermission('inventory-report-slow-moving');
        $days = max(1, min(365, (int) $request->query('days', 30)));
        $cutoff = now()->subDays($days);

        $positiveStockIds = DB::table('inventory_item_locations')
            ->select('inventory_item_id')
            ->groupBy('inventory_item_id')
            ->havingRaw('SUM(quantity) > 0')
            ->pluck('inventory_item_id');

        $recentlyUsedIds = InventoryTransaction::query()
            ->where('type', 'out')
            ->where('created_at', '>=', $cutoff)
            ->distinct()
            ->pluck('inventory_item_id');

        $slowIds = $positiveStockIds->diff($recentlyUsedIds)->values();

        if ($slowIds->isEmpty()) {
            return response()->json([
                'data' => [],
                'summary' => [
                    'stagnant_items' => 0,
                    'stagnant_valuation' => 0,
                    'avg_inactivity_days' => 0,
                ],
            ]);
        }

        $qtyByItemId = DB::table('inventory_item_locations')
            ->whereIn('inventory_item_id', $slowIds)
            ->selectRaw('inventory_item_id, SUM(quantity) as qty_sum')
            ->groupBy('inventory_item_id')
            ->pluck('qty_sum', 'inventory_item_id');

        $lastOutByItem = InventoryTransaction::query()
            ->select('inventory_item_id', DB::raw('MAX(created_at) as last_out_at'))
            ->where('type', 'out')
            ->whereIn('inventory_item_id', $slowIds)
            ->groupBy('inventory_item_id')
            ->pluck('last_out_at', 'inventory_item_id');

        $items = InventoryItem::with(['category', 'issueUom'])
            ->whereIn('id', $slowIds)
            ->orderBy('name')
            ->get();

        $report = $items->map(function ($item) use ($qtyByItemId, $lastOutByItem) {
            $onHand = (float) ($qtyByItemId[$item->id] ?? 0);
            $unitCost = (float) ($item->cost_price ?? 0) / (float) ($item->conversion_factor ?: 1);
            $lastRaw = $lastOutByItem[$item->id] ?? null;
            $lastAt = $lastRaw ? \Carbon\Carbon::parse($lastRaw) : null;
            $daysInactive = $lastAt ? now()->diffInDays($lastAt) : 999;

            return [
                'id' => $item->id,
                'item_name' => $item->name,
                'sku' => $item->sku,
                'category' => $item->category?->name ?? 'Uncategorized',
                'uom' => $item->issueUom?->short_name ?? 'unit',
                'current_stock' => round($onHand, 3),
                'valuation' => round($onHand * $unitCost, 2),
                'last_movement' => $lastAt ? $lastAt->toIso8601String() : null,
                'days_inactive' => (int) $daysInactive,
            ];
        })->sortByDesc('valuation')->values();

        return response()->json([
            'data' => $report,
            'summary' => [
                'stagnant_items' => $report->count(),
                'stagnant_valuation' => round((float) $report->sum('valuation'), 2),
                'avg_inactivity_days' => round((float) ($report->where('days_inactive', '<', 999)->avg('days_inactive') ?: 0), 1),
            ],
        ]);
    }

    /**
     * Overstock Analysis — on-hand = sum of location quantities (not inventory_items.current_stock).
     * Rule: (reorder &gt; 0 and on_hand &gt; 1.5 × reorder) OR (reorder = 0 and on_hand &gt; 100).
     * Excess qty = max(0, on_hand − target) where target = reorder × 1.2 if reorder &gt; 0 else 50.
     */
    public function overstockReport(Request $request)
    {
        $this->checkPermission('inventory-report-overstock');

        $overstockIds = DB::table('inventory_items as i')
            ->leftJoinSub(
                DB::table('inventory_item_locations')
                    ->selectRaw('inventory_item_id, SUM(quantity) as qty_sum')
                    ->groupBy('inventory_item_id'),
                'loc',
                'i.id',
                '=',
                'loc.inventory_item_id'
            )
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->where('i.reorder_level', '>', 0)
                        ->whereRaw('COALESCE(loc.qty_sum, 0) > i.reorder_level * 1.5');
                })->orWhere(function ($q3) {
                    $q3->where(function ($q4) {
                        $q4->where('i.reorder_level', '=', 0)->orWhereNull('i.reorder_level');
                    })->whereRaw('COALESCE(loc.qty_sum, 0) > 100');
                });
            })
            ->pluck('i.id');

        if ($overstockIds->isEmpty()) {
            return response()->json([
                'data' => [],
                'summary' => [
                    'overstocked_items' => 0,
                    'total_excess_valuation' => 0,
                    'avg_overstock_pct' => 0,
                ],
            ]);
        }

        $qtyByItemId = DB::table('inventory_item_locations')
            ->whereIn('inventory_item_id', $overstockIds)
            ->selectRaw('inventory_item_id, SUM(quantity) as qty_sum')
            ->groupBy('inventory_item_id')
            ->pluck('qty_sum', 'inventory_item_id');

        $items = InventoryItem::with(['category', 'issueUom', 'vendor'])
            ->whereIn('id', $overstockIds)
            ->orderBy('name')
            ->get();

        $report = $items->map(function ($item) use ($qtyByItemId) {
            $onHand = (float) ($qtyByItemId[$item->id] ?? 0);
            $reorder = (float) $item->reorder_level;
            $targetStock = $reorder > 0 ? $reorder * 1.2 : 50;
            $excessQty = max(0, $onHand - $targetStock);
            $unitCost = (float) ($item->cost_price ?? 0) / (float) ($item->conversion_factor ?: 1);

            return [
                'id' => $item->id,
                'item_name' => $item->name,
                'sku' => $item->sku,
                'category' => $item->category?->name ?? 'Uncategorized',
                'uom' => $item->issueUom?->short_name ?? 'unit',
                'current_stock' => round($onHand, 3),
                'reorder_level' => $reorder,
                'target_level' => round($targetStock, 3),
                'excess_qty' => round($excessQty, 2),
                'excess_valuation' => round($excessQty * $unitCost, 2),
                'vendor_name' => $item->vendor?->name ?? 'Not Assigned',
            ];
        })->sortByDesc('excess_valuation')->values();

        $withReorder = $report->filter(fn (array $r) => ($r['reorder_level'] ?? 0) > 0);

        return response()->json([
            'data' => $report,
            'summary' => [
                'overstocked_items' => $report->count(),
                'total_excess_valuation' => round((float) $report->sum('excess_valuation'), 2),
                'avg_overstock_pct' => $withReorder->isNotEmpty()
                    ? round((float) $withReorder->avg(
                        fn (array $r) => ($r['current_stock'] / $r['reorder_level']) * 100
                    ), 1)
                    : null,
            ],
        ]);
    }

    /**
     * Dashboard Summary for Reports Page
     */
    public function dashboardSummary(Request $request)
    {
        $this->checkPermission('inventory-report-summary');
        $statusResp = $this->stockStatus($request)->getData();
        $adjustResp = $this->adjustments($request)->getData();
        
        // Fetch recent pending POs
        $pendingPOs = PurchaseOrder::whereIn('status', ['Ordered', 'Pending'])->count();
        
        return response()->json([
            'valuation' => $statusResp->summary->total_valuation,
            'low_stock' => $statusResp->summary->low_stock_count,
            'total_items' => $statusResp->summary->total_items,
            'recent_loss' => $adjustResp->summary->total_loss_value,
            'pending_pos' => $pendingPOs,
            'critical_items' => collect($statusResp->data)->where('is_low', true)->take(5)->values()
        ]);
    }
}
