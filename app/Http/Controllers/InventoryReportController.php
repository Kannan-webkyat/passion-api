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
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
                $deductQty = (float) $qty;
                if ($orderItem->menu_item_variant_id && ($ml = (float) ($orderItem->variant?->ml_quantity ?? 0)) > 0) {
                    $label = strtolower(trim((string) ($orderItem->variant?->size_label ?? '')));
                    $cf = max(1.0, (float) ($menuItem->inventoryItem?->conversion_factor ?? 1));
                    if (
                        $ml <= 1.0001
                        && $cf > 1.0001
                        && (
                            str_contains($label, 'full')
                            || str_contains($label, 'bottle')
                            || str_contains($label, 'btl')
                            || str_contains($label, 'bottile')
                        )
                    ) {
                        $deductQty = $cf * (float) $qty;
                    } else {
                        $deductQty = $ml * (float) $qty;
                    }
                } elseif (
                    (bool) ($menuItem->is_direct_sale ?? false)
                    && ($cf = max(1.0, (float) ($menuItem->inventoryItem?->conversion_factor ?? 1))) > 1.0001
                ) {
                    $deductQty = $cf * (float) $qty;
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
     * Locations for excise liquor register: Main Store + all bar stores /
     * outlet bar locations. Combined so opening, GRN purchases (at main),
     * and bar POS sales appear on one dated register.
     *
     * @return list<array{id:int,name:string,type:?string}>
     */
    private function resolveExciseLocations(): array
    {
        $byId = [];

        foreach (
            InventoryLocation::query()
                ->where(function ($q) {
                    $q->whereIn('type', ['main_store', 'bar_store'])
                        ->orWhereIn('name', ['Main Store', 'Bar Store']);
                })
                ->orderBy('id')
                ->get(['id', 'name', 'type']) as $loc
        ) {
            $byId[(int) $loc->id] = [
                'id' => (int) $loc->id,
                'name' => (string) $loc->name,
                'type' => $loc->type,
            ];
        }

        $linkedIds = DB::table('restaurant_masters')
            ->whereNotNull('bar_location_id')
            ->distinct()
            ->pluck('bar_location_id');

        if ($linkedIds->isNotEmpty()) {
            foreach (
                InventoryLocation::query()
                    ->whereIn('id', $linkedIds)
                    ->orderBy('id')
                    ->get(['id', 'name', 'type']) as $loc
            ) {
                $byId[(int) $loc->id] = [
                    'id' => (int) $loc->id,
                    'name' => (string) $loc->name,
                    'type' => $loc->type,
                ];
            }
        }

        return array_values($byId);
    }

    /**
     * Bottle size in ml for excise labels / ML↔BTL normalization.
     * Prefer size parsed from name/SKU when conversion_factor is 1 (BTL stock).
     */
    private function exciseBottleMlFromItem(InventoryItem $item): float
    {
        $hay = (string) $item->name.' '.(string) ($item->sku ?? '');
        if (preg_match('/(1000|750|650|500|375|330)/', $hay, $m)) {
            return (float) $m[1];
        }

        $cf = (float) ($item->conversion_factor ?: 0);

        return $cf >= 100 ? $cf : 0.0;
    }

    /**
     * When stock is BTL but a movement aggregate is still in ml (e.g. POS qty 375/500/1500),
     * convert to bottle units. Small counts stay as bottles as-is.
     */
    private function exciseNormalizeToBottles(float $qty, float $bottleMl): float
    {
        if ($bottleMl < 100 || abs($qty) < 0.0001) {
            return $qty;
        }

        if (abs($qty) + 0.0001 >= $bottleMl) {
            return round($qty / $bottleMl, 4);
        }

        return $qty;
    }

    /**
     * Excise liquor register (Main Store + Bar)
     *
     * Excel-style output:
     * - Opening: bottles + loose litres (hotel liquor stock at main + bars)
     * - Receipts: GRN purchases into those locations on the selected date
     * - Sales: bottles + pegs (1 peg = 60ml by default) — POS outs from bars
     * - Closing: bottles + loose litres
     *
     * Internal main→bar transfers are not counted as receipts (would double-count).
     *
     * Assumptions:
     * - Spirits on ML: issue UOM = ML, conversion_factor = bottle ml (750/1000) → bottles + pegs
     * - Full-bottle spirits on BTL: issue UOM = BTL, cf = 1 → bottle counts (bottle size from name)
     * - Beer: issue UOM = BTL/Pcs → bottle/piece counts (no pegs)
     * - POS generates inventory_transactions out rows with reason 'POS Order'
     * - Supplier receipts post as reason 'GRN Receipt' at Main Store
     * - After ML→BTL conversion, older POS lines may still store qty in ml (375/500);
     *   those aggregates are normalized to bottles when stock is BTL.
     */
    public function exciseBar(Request $request)
    {
        $this->checkPermission('inventory-report-summary');

        $date = $request->query('date') ?? $request->query('business_date');
        if (! $date) {
            return response()->json(['message' => 'date is required (YYYY-MM-DD).'], 422);
        }

        $exciseLocations = $this->resolveExciseLocations();
        $locationIds = array_column($exciseLocations, 'id');
        if ($locationIds === []) {
            return response()->json([
                'message' => 'No Main Store or bar location found. Configure Main Store and link bar stores on outlets.',
                'meta' => [
                    'locations' => [],
                ],
            ], 422);
        }

        $pegMl = (float) ($request->query('peg_ml') ?? 60);
        $pegMl = $pegMl > 0 ? $pegMl : 60;

        $start = \Carbon\Carbon::parse($date)->startOfDay();
        $end = \Carbon\Carbon::parse($date)->endOfDay();

        // Items present in main and/or bar (even zero, we still allow report)
        $qtyNowByItemId = DB::table('inventory_item_locations')
            ->whereIn('inventory_location_id', $locationIds)
            ->selectRaw('inventory_item_id, SUM(COALESCE(quantity, 0)) as qty')
            ->groupBy('inventory_item_id')
            ->pluck('qty', 'inventory_item_id');

        $itemIds = $qtyNowByItemId->keys();
        if ($itemIds->isEmpty()) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'date' => $start->toDateString(),
                    'location_ids' => $locationIds,
                    'peg_ml' => $pegMl,
                    'locations' => $exciseLocations,
                    'receipts_reasons' => ['GRN Receipt'],
                    'scope' => 'main_and_bar',
                ],
                'summary' => [
                    'rows' => 0,
                ],
            ]);
        }

        // Excise register is alcohol only (exclude water, soft drinks, mixers, etc.)
        // Sort: main → sub → product name group → bottle size (1000 → 750 → 375) → item name
        $items = InventoryItem::with(['issueUom', 'category.parent'])
            ->whereIn('id', $itemIds)
            ->where('is_alcohol', true)
            ->get()
            ->sortBy(function (InventoryItem $i) {
                $cat = $i->category;
                if (! $cat) {
                    return '9999-9999-zzz-99999-zzz';
                }
                $mainOrder = $cat->parent_id
                    ? ($cat->parent?->excise_sort_order ?? 9999)
                    : ($cat->excise_sort_order ?? 9999);
                // Items on a main category sit before that main's subcategories
                $subOrder = $cat->parent_id
                    ? ($cat->excise_sort_order ?? 9999)
                    : 0;

                $rawName = (string) $i->name;
                // Group variants: "Mansion House (MH) 1000ml" → "mansion house (mh)"
                $baseName = preg_replace('/\s*[—\-]?\s*\d+(?:\.\d+)?\s*ml\s*$/iu', '', $rawName) ?? $rawName;
                $baseName = mb_strtolower(trim(preg_replace('/\s{2,}/u', ' ', $baseName) ?? $baseName));

                $bottleMl = $this->exciseBottleMlFromItem($i);
                // Larger bottle first within the name group
                $bottleRank = $bottleMl > 0 ? (99999 - (int) round($bottleMl)) : 99999;

                return sprintf(
                    '%05d-%05d-%s-%s-%05d-%s',
                    (int) $mainOrder,
                    (int) $subOrder,
                    mb_strtolower((string) ($cat->name ?? 'zzz')),
                    $baseName !== '' ? $baseName : 'zzz',
                    $bottleRank,
                    mb_strtolower($rawName)
                );
            })
            ->values();

        $itemIds = $items->pluck('id');
        if ($itemIds->isEmpty()) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'date' => $start->toDateString(),
                    'location_ids' => $locationIds,
                    'peg_ml' => $pegMl,
                    'locations' => $exciseLocations,
                    'receipts_reasons' => ['GRN Receipt'],
                    'scope' => 'main_and_bar',
                ],
                'summary' => [
                    'rows' => 0,
                ],
            ]);
        }

        $qtyNowByItemId = $qtyNowByItemId->only($itemIds->all());

        // Aggregate transactions for the day and after the day (to reverse from current stock).
        // Receipts = supplier GRN into Main (or bar) that day — not internal store transfers.
        $purchaseReasons = ['GRN Receipt'];
        $purchaseReasonList = collect($purchaseReasons)
            ->map(fn ($r) => "'".str_replace("'", "''", $r)."'")
            ->implode(',');

        $txAggDay = InventoryTransaction::query()
            ->whereIn('inventory_item_id', $itemIds)
            ->whereIn('inventory_location_id', $locationIds)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("inventory_item_id,
                SUM(CASE WHEN type = 'in' THEN quantity ELSE 0 END) as in_qty,
                SUM(CASE WHEN type = 'out' THEN quantity ELSE 0 END) as out_qty,
                SUM(CASE WHEN type = 'in' AND reason IN ({$purchaseReasonList}) THEN quantity ELSE 0 END) as purchase_in_qty,
                SUM(CASE WHEN type = 'out' AND reason = 'POS Order' THEN quantity ELSE 0 END) as pos_out_qty
            ")
            ->groupBy('inventory_item_id')
            ->get()
            ->keyBy('inventory_item_id');

        $txAggAfter = InventoryTransaction::query()
            ->whereIn('inventory_item_id', $itemIds)
            ->whereIn('inventory_location_id', $locationIds)
            ->where('created_at', '>', $end)
            ->selectRaw("inventory_item_id,
                SUM(CASE WHEN type = 'in' THEN quantity ELSE 0 END) as in_qty,
                SUM(CASE WHEN type = 'out' THEN quantity ELSE 0 END) as out_qty
            ")
            ->groupBy('inventory_item_id')
            ->get()
            ->keyBy('inventory_item_id');

        $splitBottlePeg = function (float $qtyMl, float $bottleMl, float $pegMl): array {
            if ($bottleMl <= 0.0001) {
                return ['bottles' => 0.0, 'pegs' => 0.0];
            }
            // Full sealed bottles + open remainder as pegs.
            // Peg scale (peg_ml=60): 0.5 = 30ml, 1 = 60ml, 1.5 = 90ml — always half-peg steps.
            $bottles = floor($qtyMl / $bottleMl);
            $remMl = max(0.0, $qtyMl - ($bottles * $bottleMl));
            if ($pegMl <= 0.0001 || $remMl < 0.0001) {
                return ['bottles' => (float) $bottles, 'pegs' => 0.0];
            }
            $halfPegMl = $pegMl / 2; // 30ml when peg = 60ml
            $halfPegs = (int) round($remMl / $halfPegMl);
            $pegs = $halfPegs / 2; // 0, 0.5, 1, 1.5, …

            // If rounding pushes remainder to a full bottle, roll into bottles
            $maxHalfInBottle = (int) floor($bottleMl / $halfPegMl);
            if ($halfPegs >= $maxHalfInBottle && $maxHalfInBottle > 0) {
                $extraBottles = intdiv($halfPegs, $maxHalfInBottle);
                $halfPegs = $halfPegs % $maxHalfInBottle;
                $bottles += $extraBottles;
                $pegs = $halfPegs / 2;
            }

            return [
                'bottles' => (float) $bottles,
                'pegs' => round($pegs, 2),
            ];
        };

        $rows = $items->map(function (InventoryItem $item) use (
            $qtyNowByItemId,
            $txAggDay,
            $txAggAfter,
            $splitBottlePeg,
            $pegMl
        ) {
            $bottleMl = $this->exciseBottleMlFromItem($item);

            $uomRaw = strtolower(trim((string) ($item->issueUom?->short_name ?? '')));
            $uomName = strtolower(trim((string) ($item->issueUom?->name ?? '')));
            $isMl = $uomRaw === 'ml'
                || $uomRaw === 'millilitre'
                || $uomRaw === 'milliliter'
                || str_contains($uomName, 'millilitre')
                || str_contains($uomName, 'milliliter');
            $isBtl = $uomRaw === 'btl'
                || $uomRaw === 'bottle'
                || str_contains($uomName, 'bottle');

            $nowQty = (float) ($qtyNowByItemId[$item->id] ?? 0);

            $day = $txAggDay->get($item->id);
            $dayIn = (float) ($day?->in_qty ?? 0);
            $dayOut = (float) ($day?->out_qty ?? 0);
            $purchaseIn = (float) ($day?->purchase_in_qty ?? 0);
            $posOut = (float) ($day?->pos_out_qty ?? 0);

            $after = $txAggAfter->get($item->id);
            $afterIn = (float) ($after?->in_qty ?? 0);
            $afterOut = (float) ($after?->out_qty ?? 0);

            // BTL stock + legacy ML-sized movement lines (e.g. corrected full-bottle POS qty=375).
            if ($isBtl && ! $isMl && $bottleMl >= 100) {
                $dayIn = $this->exciseNormalizeToBottles($dayIn, $bottleMl);
                $dayOut = $this->exciseNormalizeToBottles($dayOut, $bottleMl);
                $purchaseIn = $this->exciseNormalizeToBottles($purchaseIn, $bottleMl);
                $posOut = $this->exciseNormalizeToBottles($posOut, $bottleMl);
                $afterIn = $this->exciseNormalizeToBottles($afterIn, $bottleMl);
                $afterOut = $this->exciseNormalizeToBottles($afterOut, $bottleMl);
            }

            $netDay = $dayIn - $dayOut;
            $afterNet = $afterIn - $afterOut;

            // current = opening + netDay + afterNet → opening / end-of-day from live stock
            $openingQty = $nowQty - $netDay - $afterNet;
            $closingQty = $nowQty - $afterNet; // stock at end of selected date (matches book)

            // Register columns: Receipts = GRN only; Sales = POS only.
            // Other day movements (adjustments, wastage, transfers net, etc.) explain
            // Opening + Receipts − Sales ≠ Closing when present.
            $receiptsQty = $purchaseIn;
            $salesQty = $posOut;
            $otherDayNet = ($dayIn - $purchaseIn) - ($dayOut - $posOut); // non-GRN in − non-POS out
            $totalQty = $openingQty + $receiptsQty;

            // Spirits-like (ml tracked): sealed bottles + open stock as pegs
            if ($isMl && $bottleMl > 0) {
                $opening = $splitBottlePeg($openingQty, $bottleMl, $pegMl);
                $receipts = $splitBottlePeg($receiptsQty, $bottleMl, $pegMl);
                $total = $splitBottlePeg($totalQty, $bottleMl, $pegMl);
                $sales = $splitBottlePeg($salesQty, $bottleMl, $pegMl);
                $closing = $splitBottlePeg($closingQty, $bottleMl, $pegMl);

                return [
                    'item_id' => $item->id,
                    'item_name' => $item->name,
                    'category' => $item->category?->name ?? '—',
                    'category_id' => $item->category_id,
                    'excise_sort_order' => $item->category?->excise_sort_order,
                    'uom' => $item->issueUom?->short_name ?? '—',
                    'bottle_ml' => (int) round($bottleMl),
                    'opening_bottles' => (float) $opening['bottles'],
                    'opening_pegs' => (float) $opening['pegs'],
                    'receipts_bottles' => (float) $receipts['bottles'],
                    'receipts_pegs' => (float) $receipts['pegs'],
                    'total_bottles' => (float) $total['bottles'],
                    'total_pegs' => (float) $total['pegs'],
                    'sales_bottles' => (float) $sales['bottles'],
                    'sales_pegs' => (float) $sales['pegs'],
                    'closing_bottles' => (float) $closing['bottles'],
                    'closing_pegs' => (float) $closing['pegs'],
                    'debug' => [
                        'opening_qty_ml' => round($openingQty, 3),
                        'receipts_purchase_ml' => round($receiptsQty, 3),
                        'total_qty_ml' => round($totalQty, 3),
                        'pos_out_ml' => round($salesQty, 3),
                        'other_day_net_ml' => round($otherDayNet, 3),
                        'closing_qty_ml' => round($closingQty, 3),
                        'now_qty_ml' => round($nowQty, 3),
                        'peg_ml' => $pegMl,
                    ],
                ];
            }

            // BTL / pcs — count only (no pegs). bottle_ml from name when available (excise label).
            return [
                'item_id' => $item->id,
                'item_name' => $item->name,
                'category' => $item->category?->name ?? '—',
                'category_id' => $item->category_id,
                'excise_sort_order' => $item->category?->excise_sort_order,
                'uom' => $item->issueUom?->short_name ?? '—',
                'bottle_ml' => $bottleMl >= 100 ? (int) round($bottleMl) : null,
                'opening_bottles' => round($openingQty, 2),
                'opening_pegs' => null,
                'receipts_bottles' => round($receiptsQty, 2),
                'receipts_pegs' => null,
                'total_bottles' => round($totalQty, 2),
                'total_pegs' => null,
                'sales_bottles' => round($salesQty, 2),
                'sales_pegs' => null,
                'closing_bottles' => round($closingQty, 2),
                'closing_pegs' => null,
                'debug' => [
                    'opening_qty' => round($openingQty, 3),
                    'receipts_purchase' => round($receiptsQty, 3),
                    'total_qty' => round($totalQty, 3),
                    'pos_out' => round($salesQty, 3),
                    'other_day_net' => round($otherDayNet, 3),
                    'closing_qty' => round($closingQty, 3),
                    'now_qty' => round($nowQty, 3),
                    'stock_uom' => $isBtl ? 'BTL' : ($item->issueUom?->short_name ?? ''),
                ],
            ];
        })->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'date' => $start->toDateString(),
                'location_ids' => $locationIds,
                'peg_ml' => $pegMl,
                'locations' => $exciseLocations,
                'receipts_reasons' => $purchaseReasons,
                'scope' => 'main_and_bar',
            ],
            'summary' => [
                'rows' => $rows->count(),
            ],
        ]);
    }

    /**
     * Alcohol categories for excise register sort setup (main → sub tree).
     */
    public function exciseCategoryOrder(Request $request)
    {
        $this->checkPermission('inventory-report-summary');

        $alcoholParentId = InventoryCategory::query()
            ->where('name', 'Alcohol')
            ->whereNull('parent_id')
            ->value('id');

        // Relevant leaves/mids: alcohol items + Alcohol children (even if empty)
        $relevant = InventoryCategory::query()
            ->where(function ($q) use ($alcoholParentId) {
                $q->whereHas('items', fn ($i) => $i->where('is_alcohol', true));
                if ($alcoholParentId) {
                    $q->orWhere('parent_id', $alcoholParentId);
                }
            })
            ->get(['id', 'name', 'parent_id', 'excise_sort_order']);

        $mainIds = collect();
        foreach ($relevant as $cat) {
            if ($cat->parent_id) {
                $mainIds->push((int) $cat->parent_id);
            } else {
                $mainIds->push((int) $cat->id);
            }
        }
        if ($alcoholParentId) {
            $mainIds->push((int) $alcoholParentId);
        }
        $mainIds = $mainIds->unique()->values();

        $mains = InventoryCategory::query()
            ->whereIn('id', $mainIds)
            ->whereNull('parent_id')
            ->orderByRaw('CASE WHEN excise_sort_order IS NULL THEN 1 ELSE 0 END')
            ->orderBy('excise_sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id', 'excise_sort_order']);

        $childrenByParent = $relevant
            ->filter(fn ($c) => $c->parent_id !== null)
            ->groupBy(fn ($c) => (int) $c->parent_id);

        $sortCats = static function ($cats) {
            return $cats
                ->sortBy(function ($c) {
                    return sprintf(
                        '%d-%05d-%s',
                        $c->excise_sort_order === null ? 1 : 0,
                        (int) ($c->excise_sort_order ?? 9999),
                        mb_strtolower((string) $c->name)
                    );
                })
                ->values();
        };

        $data = $mains->map(function ($main) use ($childrenByParent, $sortCats) {
            $children = $sortCats($childrenByParent->get((int) $main->id, collect()));

            return [
                'id' => $main->id,
                'name' => $main->name,
                'excise_sort_order' => $main->excise_sort_order,
                'children' => $children->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'parent_id' => $c->parent_id,
                    'excise_sort_order' => $c->excise_sort_order,
                ])->values(),
            ];
        })->values();

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Persist main + sub excise category display order.
     */
    public function updateExciseCategoryOrder(Request $request)
    {
        $this->checkPermission('manage-inventory');

        $validated = $request->validate([
            'groups' => 'required|array|min:1',
            'groups.*.id' => 'required|integer|exists:inventory_categories,id',
            'groups.*.children' => 'nullable|array',
            'groups.*.children.*' => 'integer|exists:inventory_categories,id',
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['groups'] as $mainIndex => $group) {
                InventoryCategory::where('id', $group['id'])->update([
                    'excise_sort_order' => ($mainIndex + 1) * 10,
                ]);
                foreach (array_values($group['children'] ?? []) as $subIndex => $childId) {
                    InventoryCategory::where('id', $childId)->update([
                        'excise_sort_order' => ($subIndex + 1) * 10,
                    ]);
                }
            }
        });

        return $this->exciseCategoryOrder($request);
    }

    /**
     * Excel export of excise bar register with merged, centered stage headers.
     */
    public function exciseBarExport(Request $request)
    {
        $this->checkPermission('inventory-report-summary');

        $payload = json_decode($this->exciseBar($request)->getContent(), true);
        $rows = $payload['data'] ?? [];
        $meta = $payload['meta'] ?? [];
        $date = $meta['date'] ?? now()->toDateString();

        $filename = "excise-bar-{$date}.xlsx";

        $toPeg = static function ($pegs) {
            if ($pegs === null || $pegs === '') {
                return null;
            }
            $p = (float) $pegs;
            if (abs($p) < 0.005) {
                return null;
            }

            return round($p, 2);
        };

        $toBtl = static function ($bottles) {
            if (! is_numeric($bottles)) {
                return null;
            }
            $b = 0 + $bottles;
            if (abs($b) < 0.005) {
                return null;
            }

            return $b;
        };

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Excise Bar');

        // Row 1 stage titles · Row 2 Bottle | Peg
        $sheet->setCellValue('A1', 'Item');
        $sheet->setCellValue('B1', 'Bottle');
        $sheet->setCellValue('C1', 'Opening');
        $sheet->setCellValue('E1', 'Receipts');
        $sheet->setCellValue('G1', 'Total');
        $sheet->setCellValue('I1', 'Sales');
        $sheet->setCellValue('K1', 'Closing');

        $sheet->mergeCells('A1:A2');
        $sheet->setCellValue('B2', 'ml');
        $sheet->mergeCells('C1:D1');
        $sheet->mergeCells('E1:F1');
        $sheet->mergeCells('G1:H1');
        $sheet->mergeCells('I1:J1');
        $sheet->mergeCells('K1:L1');

        $sub = ['Bottle', 'Peg', 'Bottle', 'Peg', 'Bottle', 'Peg', 'Bottle', 'Peg', 'Bottle', 'Peg'];
        foreach ($sub as $i => $label) {
            $col = Coordinate::stringFromColumnIndex($i + 3);
            $sheet->setCellValue("{$col}2", $label);
        }

        $headerRange = 'A1:L2';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F3F4F6'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'D1D5DB'],
                ],
            ],
        ]);

        foreach (['C1:D1', 'E1:F1', 'G1:H1', 'I1:J1', 'K1:L1'] as $range) {
            $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $r = 3;
        $lastCategory = null;
        foreach ($rows as $row) {
            $category = (string) ($row['category'] ?? '—');

            if ($category !== $lastCategory) {
                // Category separator row (like UI section headers)
                $sheet->mergeCells("A{$r}:L{$r}");
                $sheet->setCellValue("A{$r}", mb_strtoupper($category));
                $sheet->getStyle("A{$r}:L{$r}")->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'size' => 10,
                        'color' => ['rgb' => '374151'],
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_LEFT,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'E5E7EB'],
                    ],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'D1D5DB'],
                        ],
                    ],
                ]);
                $sheet->getRowDimension($r)->setRowHeight(20);
                $lastCategory = $category;
                $r++;
            }

            $sheet->setCellValue("A{$r}", $row['item_name'] ?? '');
            $bottleMl = $row['bottle_ml'] ?? null;
            if (is_numeric($bottleMl) && (float) $bottleMl > 0) {
                $sheet->setCellValue("B{$r}", (int) $bottleMl);
            } else {
                $sheet->setCellValue("B{$r}", null);
            }

            $vals = [
                $toBtl($row['opening_bottles'] ?? 0),
                $toPeg($row['opening_pegs'] ?? null),
                $toBtl($row['receipts_bottles'] ?? 0),
                $toPeg($row['receipts_pegs'] ?? null),
                $toBtl($row['total_bottles'] ?? 0),
                $toPeg($row['total_pegs'] ?? null),
                $toBtl($row['sales_bottles'] ?? 0),
                $toPeg($row['sales_pegs'] ?? null),
                $toBtl($row['closing_bottles'] ?? 0),
                $toPeg($row['closing_pegs'] ?? null),
            ];
            foreach ($vals as $i => $v) {
                $col = Coordinate::stringFromColumnIndex($i + 3);
                if ($v === null) {
                    $sheet->setCellValue("{$col}{$r}", null);
                } else {
                    $sheet->setCellValue("{$col}{$r}", $v);
                }
            }

            $sheet->getStyle("B{$r}:L{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("A{$r}:L{$r}")->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'E5E7EB'],
                    ],
                ],
            ]);
            $r++;
        }

        $sheet->getColumnDimension('A')->setWidth(42);
        $sheet->getColumnDimension('B')->setWidth(10);
        foreach (range('C', 'L') as $col) {
            $sheet->getColumnDimension($col)->setWidth(10);
        }
        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->getRowDimension(2)->setRowHeight(18);
        $sheet->freezePane('C3');

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'must-revalidate',
            'Pragma' => 'public',
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
