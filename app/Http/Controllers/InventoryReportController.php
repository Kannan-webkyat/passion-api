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

class InventoryReportController extends Controller
{
    private function checkPermission($permission)
    {
        // Simple permission check (can be integrated with middleware)
        if (!auth()->user() || !auth()->user()->can($permission) && auth()->user()->roles()->where('name', 'Admin')->count() === 0) {
            // Logged in user can manage if admin
        }
    }

    /**
     * Stock Status & Valuation Report
     */
    public function stockStatus(Request $request)
    {
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
     * Consumption Reconciliation: Theoretical (Sales * Recipe) vs Actual (Out-transactions)
     */
    public function consumption(Request $request)
    {
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
                $multiplier = $qty / $yield;
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

        $actualsQuery = InventoryTransaction::whereDate('created_at', '>=', $startDate)
            ->whereDate('created_at', '<=', $endDate)
            ->whereNotIn('reason', ['Transfer', 'Internal Issue', 'Production', 'Finished Goods']);

        if ($locationId) {
            $actualsQuery->where('inventory_location_id', $locationId);
        }

        $actualsData = $actualsQuery->select('inventory_item_id', 'type', DB::raw('SUM(quantity) as total_qty'))
            ->groupBy('inventory_item_id', 'type')
            ->get();

        $actuals = [];
        foreach ($actualsData as $data) {
            $current = $actuals[$data->inventory_item_id] ?? 0;
            // Subtract 'in' (reversals/returns) from 'out' (sales/wastage)
            $actuals[$data->inventory_item_id] = ($data->type === 'out') 
                ? $current + (float)$data->total_qty 
                : $current - (float)$data->total_qty;
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
     * Wastage & Adjustments Report
     */
    public function adjustments(Request $request)
    {
        $reason = $request->query('reason');
        $startDate = $request->query('from') ?? $request->query('start_date');
        $endDate = $request->query('to') ?? $request->query('end_date');

        $query = InventoryTransaction::with(['item.issueUom', 'user', 'location'])
            ->whereIn('reason', ['Wastage', 'Expired', 'Breakage', 'Theft', 'Manual Adjustment', 'Correction', 'Components Stored', 'Assembled from Storage'])
            ->orderBy('created_at', 'desc');

        if ($reason && $reason !== 'all') $query->where('reason', $reason);
        if ($startDate) $query->whereDate('created_at', '>=', $startDate);
        if ($endDate) $query->whereDate('created_at', '<=', $endDate);

        $results = $query->get();

        $data = $results->map(function ($t) {
            return [
                'id' => $t->id,
                'item_name' => $t->item?->name ?? 'Unknown',
                'sku' => $t->item?->sku ?? '-',
                'qty' => (float) abs($t->quantity),
                'uom' => $t->item?->issueUom?->short_name ?? '-',
                'unit_cost' => (float) $t->unit_cost,
                'total_loss' => (float) $t->total_cost,
                'reason' => $t->reason,
                'location_name' => $t->location?->name ?? 'N/A',
                'user_name' => $t->user?->name ?? 'System',
                'created_at' => $t->created_at->toIso8601String(),
            ];
        });

        $summary = [
            'total_loss_value' => $data->sum('total_loss'),
            'total_incidents' => $data->count(),
            'by_reason' => $data->groupBy('reason')->map(fn ($group) => [
                'count' => $group->count(),
                'value' => $group->sum('total_loss'),
            ]),
        ];

        return response()->json([
            'data' => $data,
            'summary' => $summary,
        ]);
    }

    /**
     * Purchase History & Price Trending
     */
    public function purchaseHistory(Request $request)
    {
        $vendorId = $request->query('vendor_id');
        $itemId = $request->query('item_id');
        $startDate = $request->query('from') ?? $request->query('start_date');
        $endDate = $request->query('to') ?? $request->query('end_date');

        $query = PurchaseOrder::with(['vendor', 'items.inventoryItem.issueUom'])
            ->where('status', 'Received')
            ->orderBy('received_at', 'desc');

        if ($vendorId && $vendorId !== 'all') $query->where('vendor_id', $vendorId);
        if ($startDate) $query->whereDate('received_at', '>=', $startDate);
        if ($endDate) $query->whereDate('received_at', '<=', $endDate);

        $pos = $query->get();

        $flatItems = [];
        foreach ($pos as $po) {
            foreach ($po->items as $pi) {
                if ($itemId && $pi->inventory_item_id != $itemId) continue;

                $flatItems[] = [
                    'po_number' => $po->po_number,
                    'received_at' => $po->received_at,
                    'vendor' => $po->vendor?->name ?? '—',
                    'item_id' => $pi->inventory_item_id,
                    'item_name' => $pi->inventoryItem?->name ?? '—',
                    'uom' => $pi->inventoryItem?->issueUom?->short_name ?? '—',
                    'quantity' => (float) $pi->received_quantity,
                    'unit_price' => (float) $pi->unit_price,
                    'total_price' => (float) $pi->total_price,
                ];
            }
        }

        return response()->json([
            'data' => $flatItems,
            'summary' => [
                'total_spend' => collect($flatItems)->sum('total_price'),
                'avg_unit_price' => count($flatItems) > 0 ? collect($flatItems)->avg('unit_price') : 0
            ]
        ]);
    }

    /**
     * Reorder Planning Report
     */
    public function reorderReport(Request $request)
    {
        $items = InventoryItem::with(['category', 'issueUom', 'vendor'])
            ->whereColumn('current_stock', '<=', 'reorder_level')
            ->get();

        $report = $items->map(function ($item) {
            $suggestedOrder = max(0, ($item->reorder_level * 2) - $item->current_stock);
            return [
                'id' => $item->id,
                'item_name' => $item->name,
                'sku' => $item->sku,
                'category' => $item->category?->name ?? 'Uncategorized',
                'uom' => $item->issueUom?->short_name ?? 'unit',
                'current_stock' => (float) $item->current_stock,
                'reorder_level' => (float) $item->reorder_level,
                'suggested_order' => round($suggestedOrder, 2),
                'vendor_name' => $item->vendor?->name ?? 'Not Assigned',
                'estimated_cost' => round($suggestedOrder * ($item->cost_price / ($item->conversion_factor ?: 1)), 2)
            ];
        });

        return response()->json([
            'data' => $report,
            'summary' => [
                'items_to_reorder' => $report->count(),
                'total_reorder_cost' => $report->sum('estimated_cost'),
                'critical_shortfall' => $report->where('current_stock', 0)->count()
            ]
        ]);
    }

    /**
     * Slow Moving Stock Analysis
     */
    public function slowMovingReport(Request $request)
    {
        $days = (int) $request->query('days', 30);
        $cutoff = now()->subDays($days);

        $recentlyUsedIds = InventoryTransaction::where('type', 'out')
            ->where('created_at', '>=', $cutoff)
            ->pluck('inventory_item_id')
            ->unique();

        $items = InventoryItem::with(['category', 'issueUom'])
            ->whereNotIn('id', $recentlyUsedIds)
            ->get();

        $report = $items->map(function ($item) {
            $lastTx = InventoryTransaction::where('inventory_item_id', $item->id)
                ->where('type', 'out')
                ->latest()
                ->first();

            $unitCost = (float) ($item->cost_price ?? 0) / (float) ($item->conversion_factor ?: 1);
            
            return [
                'id' => $item->id,
                'item_name' => $item->name,
                'sku' => $item->sku,
                'category' => $item->category?->name ?? 'Uncategorized',
                'uom' => $item->issueUom?->short_name ?? 'unit',
                'current_stock' => (float) $item->current_stock,
                'valuation' => round($item->current_stock * $unitCost, 2),
                'last_movement' => $lastTx ? ($lastTx->created_at ? $lastTx->created_at->toIso8601String() : null) : null,
                'days_inactive' => $lastTx && $lastTx->created_at ? now()->diffInDays($lastTx->created_at) : 999
            ];
        })->sortByDesc('valuation')->values();

        return response()->json([
            'data' => $report,
            'summary' => [
                'stagnant_items' => $report->count(),
                'stagnant_valuation' => (float) $report->sum('valuation'),
                'avg_inactivity_days' => $report->where('days_inactive', '<', 999)->avg('days_inactive') ?: 0
            ]
        ]);
    }

    /**
     * Overstock Analysis Report
     */
    public function overstockReport(Request $request)
    {
        // Items where current stock is more than 150% of reorder level (and reorder level > 0)
        // Or if reorder level is 0, where stock is unusually high (e.g. > 100)
        $items = InventoryItem::with(['category', 'issueUom', 'vendor'])
            ->where(function($q) {
                $q->whereColumn('current_stock', '>', DB::raw('reorder_level * 1.5'))
                  ->where('reorder_level', '>', 0);
            })
            ->orWhere(function($q) {
                $q->where('reorder_level', 0)
                  ->where('current_stock', '>', 100);
            })
            ->get();

        $report = $items->map(function ($item) {
            $targetStock = $item->reorder_level > 0 ? $item->reorder_level * 1.2 : 50;
            $excessQty = max(0, $item->current_stock - $targetStock);
            $unitCost = (float) ($item->cost_price ?? 0) / (float) ($item->conversion_factor ?: 1);
            
            return [
                'id' => $item->id,
                'item_name' => $item->name,
                'sku' => $item->sku,
                'category' => $item->category?->name ?? 'Uncategorized',
                'uom' => $item->issueUom?->short_name ?? 'unit',
                'current_stock' => (float) $item->current_stock,
                'reorder_level' => (float) $item->reorder_level,
                'excess_qty' => round($excessQty, 2),
                'excess_valuation' => round($excessQty * $unitCost, 2),
                'vendor_name' => $item->vendor?->name ?? 'Not Assigned',
            ];
        })->sortByDesc('excess_valuation')->values();

        return response()->json([
            'data' => $report,
            'summary' => [
                'overstocked_items' => $report->count(),
                'total_excess_valuation' => $report->sum('excess_valuation'),
                'avg_overstock_pct' => $report->avg(function($item) {
                    return $item['reorder_level'] > 0 ? ($item['current_stock'] / $item['reorder_level']) * 100 : 200;
                })
            ]
        ]);
    }

    /**
     * Dashboard Summary for Reports Page
     */
    public function dashboardSummary(Request $request)
    {
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
