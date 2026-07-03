<?php

namespace App\Http\Controllers;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTransaction;
use App\Models\PosOrderItem;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Services\InventoryDeductionStoreResolver;
use App\Services\ProcurementCostTerminology;
use App\Services\ConsumptionActualsService;
use App\Services\RecipeBomExpander;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                'total_stock' => round($totalQty, 3),
                'reorder_level' => (float) ($item->reorder_level ?? 0),
                'valuation' => round($valuationValue, 2),
                'is_low' => $totalQty <= ($item->reorder_level ?? 0),
                'location_stock' => $locationBreakdown,
            ];
        });

        if ($locationId && $locationId !== 'all') {
            $report = $report->filter(fn ($r) => $r['location_stock'][$locationId] != 0)->values();
        }

        return response()->json([
            'data' => $report,
            'summary' => [
                'total_items' => $report->count(),
                'total_valuation' => round($report->sum('valuation'), 2),
                'low_stock_count' => $report->where('is_low', true)->count(),
            ],
            'locations' => $locations,
            'categories' => InventoryCategory::all(),
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

        if ($itemId) {
            $query->where('inventory_item_id', $itemId);
        }
        if ($locationId) {
            $query->where('inventory_location_id', $locationId);
        }
        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

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
            'order.restaurant',
        ])
            ->where('inventory_deducted', true)
            ->whereHas('order', function ($q) use ($startDate, $endDate) {
                $q->where(function ($dated) use ($startDate, $endDate) {
                    $dated->whereBetween('business_date', [$startDate, $endDate])
                        ->orWhere(function ($legacy) use ($startDate, $endDate) {
                            $legacy->whereNull('business_date')
                                ->whereBetween('created_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);
                        });
                });
            });

        if ($locationId) {
            $salesItems->whereHas('order.restaurant', function ($r) use ($locationId) {
                $r->where('kitchen_location_id', $locationId)
                    ->orWhere('bar_location_id', $locationId);
            });
        }

        $salesItems = $salesItems->get();

        $theoretical = [];

        $processItem = function ($orderItem, $parentQty = null, $restaurant = null) use (&$theoretical, $locationId) {
            $menuItem = $orderItem->menuItem;
            if (! $menuItem) {
                return;
            }

            $qty = $parentQty ?? $orderItem->quantity;
            $recipe = $menuItem->recipe;

            $kitchenStore = $restaurant?->kitchen_location_id
                ? InventoryLocation::find($restaurant->kitchen_location_id)
                : null;
            $barStore = $restaurant?->bar_location_id
                ? InventoryLocation::find($restaurant->bar_location_id)
                : null;
            $targetStore = app(InventoryDeductionStoreResolver::class)->resolve(
                $menuItem,
                $kitchenStore,
                $barStore,
                $restaurant
            );
            $itemLocationId = $targetStore?->id;

            if ($locationId && $itemLocationId && $itemLocationId != $locationId) {
                return;
            }

            if ($recipe && $recipe->is_active && ! ($recipe->requires_production ?? true)) {
                $yield = max(1, (float) ($recipe->yield_quantity ?? 1));
                // Apply variant portion-size scaling (matches POS deduction logic)
                $scale = 1.0;
                if ($orderItem->menu_item_variant_id && ($ml = (float) ($orderItem->variant?->ml_quantity ?? 0)) > 0 && $ml <= 10) {
                    $scale = $ml;
                }
                $multiplier = ($qty * $scale) / $yield;
                foreach (app(RecipeBomExpander::class)->flattenedRequirements($recipe, $multiplier) as $itemId => $rawQty) {
                    $theoretical[$itemId] = ($theoretical[$itemId] ?? 0) + $rawQty;
                }
            } elseif ($menuItem->inventory_item_id) {
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
                    $dummy = (object) [
                        'menuItem' => $cmi,
                        'quantity' => $sale->quantity,
                        'menu_item_variant_id' => null,
                        'variant' => null,
                    ];
                    $processItem($dummy, $sale->quantity, $restaurant);
                }
            } else {
                $processItem($sale, null, $restaurant);
            }
        }

        $actuals = app(ConsumptionActualsService::class)->netUsageByItem($startDate, $endDate, $locationId ? (int) $locationId : null);

        $itemIds = collect($theoretical)->keys()->merge(collect($actuals)->keys())->unique();
        $items = InventoryItem::with(['issueUom', 'category'])->whereIn('id', $itemIds)->get();

        $report = $items->map(function ($item) use ($theoretical, $actuals) {
            $theo = (float) ($theoretical[$item->id] ?? 0);
            $act = (float) ($actuals[$item->id] ?? 0);
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
                'variance_value' => round($variance * $unitCost, 2),
            ];
        })->sortByDesc(fn ($r) => abs($r['variance_value']))->values();

        return response()->json([
            'data' => $report,
            'summary' => [
                'total_theoretical_cost' => round($report->sum(fn ($r) => $r['theoretical_usage'] * $r['cost_price']), 2),
                'total_actual_cost' => round($report->sum(fn ($r) => $r['actual_usage'] * $r['cost_price']), 2),
                'total_variance_value' => round($report->sum('variance_value'), 2),
                'high_variance_count' => $report->filter(fn ($r) => abs($r['variance_percentage'] ?? 0) > 10)->count(),
            ],
        ]);
    }

    /**
     * Wastage & Adjustments Report — reasons match {@see InventoryController::adjustStock}
     * and recovery lines from {@see InventoryController::recoveryBreakdown}.
     */
    public function adjustments(Request $request)
    {
        $this->checkPermission('inventory-report-adjustments');
        $reason = $request->query('reason');
        $startDate = $request->query('from') ?? $request->query('start_date');
        $endDate = $request->query('to') ?? $request->query('end_date');
        $search = trim((string) $request->query('search', ''));

        $adjustmentReasons = [
            'Wastage',
            'Expired',
            'Breakage',
            'Theft',
            'Staff meal',
            'Manual Adjustment',
            'Correction',
            'Components Stored',
            'Assembled from Storage',
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
            $term = '%' . addcslashes($search, '%_\\') . '%';
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
                'reference_id' => $t->reference_id,
                'reference_type' => $t->reference_type,
            ];
        });

        $outRows = $data->filter(fn(array $r) => ($r['transaction_type'] ?? '') === 'out');
        $inRows = $data->filter(fn(array $r) => ($r['transaction_type'] ?? '') === 'in');

        $summary = [
            'total_loss_value' => round((float) $outRows->sum('total_loss'), 2),
            'total_addition_value' => round((float) $inRows->sum('total_loss'), 2),
            'total_incidents' => $data->count(),
            'by_reason' => $data->groupBy('reason')->map(fn($group) => [
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
     * Recovery / breakdown report.
     * Group recovery transactions by reference_id for clean audit.
     * GET /inventory/reports/recovery?from=&to=&search=
     */
    public function recovery(Request $request)
    {
        $this->checkPermission('inventory-report-adjustments');

        $startDate = $request->query('from') ?? $request->query('start_date');
        $endDate = $request->query('to') ?? $request->query('end_date');
        $search = trim((string) $request->query('search', ''));

        $query = InventoryTransaction::with(['item.issueUom', 'user', 'location'])
            ->where('reference_type', 'recovery_breakdown')
            ->whereNotNull('reference_id')
            ->orderBy('created_at', 'desc');

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
                })->orWhereHas('location', function ($lq) use ($term) {
                    $lq->where('name', 'like', $term);
                })->orWhere('notes', 'like', $term);
            });
        }

        $rows = $query->get();
        $groups = $rows->groupBy('reference_id');

        $data = $groups->map(function ($txs, $refId) {
            $first = $txs->sortBy('created_at')->first();
            $createdAt = $first?->created_at?->toIso8601String();
            $locationName = $first?->location?->name ?? 'N/A';
            $userName = $first?->user?->name ?? 'System';

            $source = $txs->firstWhere('reason', 'Recovery: source consumed');
            $sourceRow = $source ? [
                'inventory_item_id' => $source->inventory_item_id,
                'item_name' => $source->item?->name ?? 'Unknown',
                'sku' => $source->item?->sku ?? '-',
                'qty' => (float) $source->quantity,
                'uom' => $source->item?->issueUom?->short_name ?? '-',
            ] : null;

            $recovered = $txs->where('reason', 'Recovery: returned to stock')->map(fn ($t) => [
                'inventory_item_id' => $t->inventory_item_id,
                'item_name' => $t->item?->name ?? 'Unknown',
                'sku' => $t->item?->sku ?? '-',
                'qty' => (float) $t->quantity,
                'uom' => $t->item?->issueUom?->short_name ?? '-',
            ])->values();

            $wasted = $txs->where('reason', 'Wastage')->map(fn ($t) => [
                'inventory_item_id' => $t->inventory_item_id,
                'item_name' => $t->item?->name ?? 'Unknown',
                'sku' => $t->item?->sku ?? '-',
                'qty' => (float) $t->quantity,
                'uom' => $t->item?->issueUom?->short_name ?? '-',
            ])->values();

            $notes = $txs->pluck('notes')->filter()->unique()->values();

            return [
                'reference_id' => $refId,
                'created_at' => $createdAt,
                'location_name' => $locationName,
                'user_name' => $userName,
                'source' => $sourceRow,
                'recovered' => $recovered,
                'wasted' => $wasted,
                'notes' => $notes,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'summary' => [
                'groups' => $data->count(),
                'rows' => $rows->count(),
            ],
        ]);
    }

    /**
     * Vendor Spend report — PO agreed payables (NOT inventory WAC).
     *
     * @see ProcurementCostTerminology::VENDOR_SPEND
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
            ->whereIn('status', ['received', 'partial'])
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
                if ($qtyReceived <= 0) {
                    continue;
                }
                $vendorSpendTotal = (float) ($qtyOrdered > 0
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
                    'vendor_unit_rate' => (float) $pi->unit_price,
                    'vendor_spend_total' => $vendorSpendTotal,
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
            ...ProcurementCostTerminology::vendorSpendReportMeta(),
            'data' => $flatItems,
            'summary' => [
                'total_vendor_spend' => round((float) $collection->sum('vendor_spend_total'), 2),
                'avg_vendor_unit_rate' => $collection->isNotEmpty()
                    ? round((float) $collection->avg('vendor_unit_rate'), 4)
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

        $withReorder = $report->filter(fn(array $r) => ($r['reorder_level'] ?? 0) > 0);

        return response()->json([
            'data' => $report,
            'summary' => [
                'overstocked_items' => $report->count(),
                'total_excess_valuation' => round((float) $report->sum('excess_valuation'), 2),
                'avg_overstock_pct' => $withReorder->isNotEmpty()
                    ? round((float) $withReorder->avg(
                        fn(array $r) => ($r['current_stock'] / $r['reorder_level']) * 100
                    ), 1)
                    : null,
            ],
        ]);
    }

    /**
     * Excise (Bar) Report
     *
     * Excel-style output:
     * - Opening: bottles + loose litres
     * - Receipts: bottles + loose litres
     * - Sales: bottles + pegs (1 peg = 60ml by default)
     * - Closing: bottles + loose litres
     *
     * Assumptions:
     * - Spirits are tracked in ml (issue UOM = ml, conversion_factor = bottle ml)
     * - Beer is tracked in pcs (issue UOM = Pcs, conversion_factor = 1)
     * - POS generates inventory_transactions out rows with reason 'POS Order'
     */
    public function exciseBar(Request $request)
    {
        $this->checkPermission('inventory-report-summary');

        $date = $request->query('date') ?? $request->query('business_date');
        if (! $date) {
            return response()->json(['message' => 'date is required (YYYY-MM-DD).'], 422);
        }

        $locationId = $request->query('location_id');
        if (! $locationId || $locationId === 'auto') {
            $locationId = InventoryLocation::query()->where('type', 'bar_store')->orderBy('id')->value('id')
                ?? InventoryLocation::query()->where('name', 'Bar Store')->orderBy('id')->value('id');
        }
        if (! $locationId) {
            return response()->json(['message' => 'No bar store location found.'], 422);
        }

        $pegMl = (float) ($request->query('peg_ml') ?? 60);
        $pegMl = $pegMl > 0 ? $pegMl : 60;

        $start = \Carbon\Carbon::parse($date)->startOfDay();
        $end = \Carbon\Carbon::parse($date)->endOfDay();

        // Items present in this bar store (even zero, we still allow report)
        $qtyNowByItemId = DB::table('inventory_item_locations')
            ->where('inventory_location_id', $locationId)
            ->selectRaw('inventory_item_id, COALESCE(quantity, 0) as qty')
            ->pluck('qty', 'inventory_item_id');

        $itemIds = $qtyNowByItemId->keys();
        if ($itemIds->isEmpty()) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'date' => $start->toDateString(),
                    'location_id' => (int) $locationId,
                    'peg_ml' => $pegMl,
                ],
                'summary' => [
                    'rows' => 0,
                ],
            ]);
        }

        $items = InventoryItem::with(['issueUom', 'category'])
            ->whereIn('id', $itemIds)
            ->orderBy('name')
            ->get();

        // Aggregate transactions for the day and after the day (to reverse from current stock).
        $txAggDay = InventoryTransaction::query()
            ->whereIn('inventory_item_id', $itemIds)
            ->where('inventory_location_id', $locationId)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("inventory_item_id,
                SUM(CASE WHEN type = 'in' THEN quantity ELSE 0 END) as in_qty,
                SUM(CASE WHEN type = 'out' THEN quantity ELSE 0 END) as out_qty,
                SUM(CASE WHEN type = 'out' AND reason = 'POS Order' THEN quantity ELSE 0 END) as pos_out_qty
            ")
            ->groupBy('inventory_item_id')
            ->get()
            ->keyBy('inventory_item_id');

        $txAggAfter = InventoryTransaction::query()
            ->whereIn('inventory_item_id', $itemIds)
            ->where('inventory_location_id', $locationId)
            ->where('created_at', '>', $end)
            ->selectRaw("inventory_item_id,
                SUM(CASE WHEN type = 'in' THEN quantity ELSE 0 END) as in_qty,
                SUM(CASE WHEN type = 'out' THEN quantity ELSE 0 END) as out_qty
            ")
            ->groupBy('inventory_item_id')
            ->get()
            ->keyBy('inventory_item_id');

        $splitBottleLoose = function (float $qty, float $bottleMl): array {
            if ($bottleMl <= 0.0001) {
                return ['bottles' => 0.0, 'loose_litres' => 0.0];
            }
            $bottles = floor($qty / $bottleMl);
            $looseMl = max(0.0, $qty - ($bottles * $bottleMl));
            return [
                'bottles' => $bottles,
                'loose_litres' => round($looseMl / 1000, 3),
            ];
        };

        $splitSalesBottlePeg = function (float $qtyMl, float $bottleMl, float $pegMl) : array {
            if ($bottleMl <= 0.0001 || $pegMl <= 0.0001) {
                return ['bottles' => 0.0, 'pegs' => 0.0];
            }
            $bottles = floor($qtyMl / $bottleMl);
            $remMl = max(0.0, $qtyMl - ($bottles * $bottleMl));
            $pegs = $remMl / $pegMl;
            return [
                'bottles' => $bottles,
                'pegs' => round($pegs, 2),
            ];
        };

        $rows = $items->map(function (InventoryItem $item) use (
            $qtyNowByItemId,
            $txAggDay,
            $txAggAfter,
            $splitBottleLoose,
            $splitSalesBottlePeg,
            $pegMl
        ) {
            $uom = strtolower((string) ($item->issueUom?->short_name ?? ''));
            $bottleMl = (float) ($item->conversion_factor ?: 0);

            $nowQty = (float) ($qtyNowByItemId[$item->id] ?? 0);

            $day = $txAggDay->get($item->id);
            $dayIn = (float) ($day?->in_qty ?? 0);
            $dayOut = (float) ($day?->out_qty ?? 0);
            $posOut = (float) ($day?->pos_out_qty ?? 0);
            $netDay = $dayIn - $dayOut;

            $after = $txAggAfter->get($item->id);
            $afterNet = (float) ($after?->in_qty ?? 0) - (float) ($after?->out_qty ?? 0);

            // current = opening + netDay + afterNet
            $openingQty = $nowQty - $netDay - $afterNet;
            $closingQty = $openingQty + $netDay;

            // Spirits-like (ml tracked)
            if ($uom === 'ml' && $bottleMl > 0) {
                $opening = $splitBottleLoose($openingQty, $bottleMl);
                $receipts = $splitBottleLoose($dayIn, $bottleMl);
                $sales = $splitSalesBottlePeg($posOut, $bottleMl, $pegMl);
                $closing = $splitBottleLoose($closingQty, $bottleMl);

                return [
                    'item_id' => $item->id,
                    'item_name' => $item->name,
                    'category' => $item->category?->name ?? '—',
                    'uom' => $item->issueUom?->short_name ?? '—',
                    'bottle_ml' => (int) round($bottleMl),
                    'opening_bottles' => (float) $opening['bottles'],
                    'opening_loose_litres' => (float) $opening['loose_litres'],
                    'receipts_bottles' => (float) $receipts['bottles'],
                    'receipts_loose_litres' => (float) $receipts['loose_litres'],
                    'sales_bottles' => (float) $sales['bottles'],
                    'sales_pegs' => (float) $sales['pegs'],
                    'closing_bottles' => (float) $closing['bottles'],
                    'closing_loose_litres' => (float) $closing['loose_litres'],
                    'debug' => [
                        'opening_qty_ml' => round($openingQty, 3),
                        'receipts_in_ml' => round($dayIn, 3),
                        'pos_out_ml' => round($posOut, 3),
                        'closing_qty_ml' => round($closingQty, 3),
                    ],
                ];
            }

            // Beer / pcs-like
            return [
                'item_id' => $item->id,
                'item_name' => $item->name,
                'category' => $item->category?->name ?? '—',
                'uom' => $item->issueUom?->short_name ?? '—',
                'bottle_ml' => null,
                'opening_bottles' => round($openingQty, 2),
                'opening_loose_litres' => null,
                'receipts_bottles' => round($dayIn, 2),
                'receipts_loose_litres' => null,
                'sales_bottles' => round($posOut, 2),
                'sales_pegs' => null,
                'closing_bottles' => round($closingQty, 2),
                'closing_loose_litres' => null,
                'debug' => [
                    'opening_qty' => round($openingQty, 3),
                    'receipts_in' => round($dayIn, 3),
                    'pos_out' => round($posOut, 3),
                    'closing_qty' => round($closingQty, 3),
                ],
            ];
        })->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'date' => $start->toDateString(),
                'location_id' => (int) $locationId,
                'peg_ml' => $pegMl,
            ],
            'summary' => [
                'rows' => $rows->count(),
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

        $allItems = collect($statusResp->data ?? []);
        $lowStockItems = $allItems->where('is_low', true)->values();
        $totalItems = (int) ($statusResp->summary->total_items ?? 0);
        $lowStock = (int) ($statusResp->summary->low_stock_count ?? 0);

        $criticalShortages = (int) $lowStockItems
            ->filter(fn($item) => (float) ($item->total_qty ?? $item->total_stock ?? 0) <= 0)
            ->count();

        $lowRatio = $totalItems > 0 ? $lowStock / $totalItems : 0;
        $criticalRatio = $totalItems > 0 ? $criticalShortages / $totalItems : 0;
        $healthScore = (int) max(
            0,
            min(
                100,
                round(100 - ($lowRatio * 45) - ($criticalRatio * 40) - min($pendingPOs * 3, 12))
            )
        );

        $topReorders = $lowStockItems
            ->map(function ($item) {
                $stock = (float) ($item->total_qty ?? $item->total_stock ?? 0);
                $reorder = (float) ($item->reorder_level ?? 0);
                $shortfall = max(0.0, $reorder - $stock);

                return [
                    'id' => $item->id ?? null,
                    'name' => $item->name ?? null,
                    'sku' => $item->sku ?? null,
                    'category' => $item->category ?? null,
                    'total_stock' => $stock,
                    'total_qty' => $stock,
                    'reorder_level' => $reorder,
                    'shortfall' => round($shortfall, 3),
                    'severity' => $stock <= 0 ? 'critical' : 'low',
                    'is_low' => true,
                ];
            })
            ->sortByDesc('shortfall')
            ->take(5)
            ->values();

        $periodDays = 7;
        $periodStart = now()->subDays($periodDays)->startOfDay();

        $topConsumedRows = InventoryTransaction::query()
            ->where('type', '=', 'out')
            ->where('created_at', '>=', $periodStart)
            ->select('inventory_item_id', DB::raw('SUM(quantity) as total_qty'))
            ->groupBy('inventory_item_id')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->get();

        $consumedItemIds = $topConsumedRows->pluck('inventory_item_id')->filter()->all();
        $consumedItems = InventoryItem::with('category')
            ->whereIn('id', $consumedItemIds)
            ->get()
            ->keyBy('id');

        $topConsumed = $topConsumedRows->map(function ($row) use ($consumedItems) {
            $item = $consumedItems->get($row->inventory_item_id);

            return [
                'id' => (int) $row->inventory_item_id,
                'name' => $item?->name ?? 'Unknown item',
                'sku' => $item?->sku ?? null,
                'category' => $item?->category?->name ?? 'Uncategorized',
                'quantity' => round((float) $row->total_qty, 3),
            ];
        })->values();

        $categories = $lowStockItems
            ->groupBy(fn($item) => $item->category ?: 'Uncategorized')
            ->map(function ($items, $category) {
                return [
                    'category' => (string) $category,
                    'low_stock_count' => $items->count(),
                    'critical_count' => $items
                        ->filter(fn($item) => (float) ($item->total_qty ?? $item->total_stock ?? 0) <= 0)
                        ->count(),
                ];
            })
            ->sortByDesc('low_stock_count')
            ->values()
            ->take(6);

        $totalOut = (float) InventoryTransaction::query()
            ->where('type', '=', 'out')
            ->where('created_at', '>=', $periodStart)
            ->sum('quantity');

        $totalIn = (float) InventoryTransaction::query()
            ->where('type', '=', 'in')
            ->where('created_at', '>=', $periodStart)
            ->sum('quantity');

        $recentActivity = InventoryTransaction::with(['item', 'location'])
            ->latest()
            ->limit(8)
            ->get()
            ->map(function (InventoryTransaction $tx) {
                return [
                    'id' => $tx->id,
                    'type' => $tx->type,
                    'quantity' => (float) $tx->quantity,
                    'reason' => $tx->reason,
                    'created_at' => $tx->created_at?->toIso8601String(),
                    'item' => [
                        'name' => $tx->item?->name,
                        'sku' => $tx->item?->sku,
                    ],
                    'location' => [
                        'name' => $tx->location?->name,
                    ],
                ];
            })
            ->values();

        return response()->json([
            'valuation' => $statusResp->summary->total_valuation,
            'low_stock' => $lowStock,
            'total_items' => $totalItems,
            'recent_loss' => $adjustResp->summary->total_loss_value,
            'pending_pos' => $pendingPOs,
            'critical_items' => $lowStockItems->take(20)->values(),
            'health_score' => $healthScore,
            'critical_shortages' => $criticalShortages,
            'top_reorders' => $topReorders,
            'top_consumed' => $topConsumed,
            'categories' => $categories,
            'consumption_summary' => [
                'period_days' => $periodDays,
                'total_out' => round($totalOut, 3),
                'total_in' => round($totalIn, 3),
                'net_movement' => round($totalIn - $totalOut, 3),
            ],
            'recent_activity' => $recentActivity,
        ]);
    }
}
