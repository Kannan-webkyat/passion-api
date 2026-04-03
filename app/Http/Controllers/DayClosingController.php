<?php

namespace App\Http\Controllers;

use App\Models\PosDayClosing;
use App\Models\PosOrder;
use App\Models\PosPayment;
use App\Models\RestaurantMaster;
use App\Models\StoreRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DayClosingController extends Controller
{
    private function checkPermission(string $permission)
    {
        $user = auth()->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }
        if (! $user->hasRole('Admin') && ! $user->hasRole('Super Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    /**
     * Preview day closing summary for a restaurant and date.
     * GET /pos/day-closing/preview?restaurant_id=&date=YYYY-MM-DD
     */
    public function preview(Request $request)
    {
        $this->checkPermission('pos-day-closing');
        $validated = $request->validate([
            'restaurant_id' => 'required|exists:restaurant_masters,id',
            'date' => 'required|date',
        ]);

        $restaurantId = (int) $validated['restaurant_id'];
        $closedDate = $validated['date'];

        $existing = PosDayClosing::where('restaurant_id', $restaurantId)
            ->where('closed_date', $closedDate)
            ->first();

        $summary = $this->computeSummary($restaurantId, $closedDate);
        $inventoryPrecheck = $this->computeInventoryPrecheck($restaurantId, $closedDate);

        return response()->json([
            'already_closed' => (bool) $existing,
            'closing' => $existing?->load('closedByUser'),
            'summary' => $summary,
            'inventory_precheck' => $inventoryPrecheck,
        ]);
    }

    /**
     * Perform day closing.
     * POST /pos/day-closing
     */
    public function close(Request $request)
    {
        $this->checkPermission('pos-day-closing');
        $validated = $request->validate([
            'restaurant_id' => 'required|exists:restaurant_masters,id',
            'date' => 'required|date',
            'opening_balance' => 'nullable|numeric',
            'closing_balance' => 'nullable|numeric',
            'notes' => 'nullable|string',
        ]);

        $restaurantId = (int) $validated['restaurant_id'];
        $closedDate = $validated['date'];

        $closing = DB::transaction(function () use ($restaurantId, $closedDate, $validated) {
            $summary = $this->computeSummary($restaurantId, $closedDate);
            if (($summary['open_billed_count'] ?? 0) > 0) {
                abort(422, 'Cannot close day: there are still open or billed orders for this business date.');
            }

            $inv = $this->computeInventoryPrecheck($restaurantId, $closedDate);
            if (! ($inv['can_close'] ?? true)) {
                $parts = [];
                if (($inv['negative_stock']['count'] ?? 0) > 0) {
                    $n = (int) $inv['negative_stock']['count'];
                    $parts[] = "{$n} inventory item(s) have negative stock at this outlet's stores — record transfers, GRN, or adjustments.";
                }
                if (($inv['pending_requisitions']['count'] ?? 0) > 0) {
                    $p = (int) $inv['pending_requisitions']['count'];
                    $parts[] = "{$p} store requisition(s) or transfers are still pending approval, issue, or acceptance.";
                }
                abort(422, 'Cannot close day: '.implode(' ', $parts));
            }

            $existing = PosDayClosing::where('restaurant_id', $restaurantId)
                ->where('closed_date', $closedDate)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $recloseNote = 'Re-closed at '.now()->format('d M Y H:i').' by '.(auth()->user()?->name ?? 'System');
                $notes = $existing->notes ? $existing->notes."\n\n".$recloseNote : $recloseNote;
                if (! empty($validated['notes'])) {
                    $notes = trim($validated['notes'])."\n\n".$notes;
                }

                $existing->update([
                    'closed_at' => now(),
                    'closed_by' => auth()->id(),
                    'opening_balance' => $validated['opening_balance'] ?? $existing->opening_balance,
                    'closing_balance' => $validated['closing_balance'] ?? $existing->closing_balance,
                    'total_sales' => $summary['total_sales'],
                    'total_discount' => $summary['total_discount'],
                    'total_tax' => $summary['total_tax'],
                    'total_service_charge' => $summary['total_service_charge'],
                    'total_tip' => $summary['total_tip'],
                    'total_paid' => $summary['total_paid'],
                    'cash_total' => $summary['cash_total'],
                    'card_total' => $summary['card_total'],
                    'upi_total' => $summary['upi_total'],
                    'room_charge_total' => $summary['room_charge_total'],
                    'order_count' => $summary['order_count'],
                    'void_count' => $summary['void_count'],
                    'notes' => $notes ?: null,
                ]);

                return $existing;
            }

            return PosDayClosing::create([
                'restaurant_id' => $restaurantId,
                'closed_date' => $closedDate,
                'closed_at' => now(),
                'closed_by' => auth()->id(),
                'opening_balance' => $validated['opening_balance'] ?? null,
                'closing_balance' => $validated['closing_balance'] ?? null,
                'total_sales' => $summary['total_sales'],
                'total_discount' => $summary['total_discount'],
                'total_tax' => $summary['total_tax'],
                'total_service_charge' => $summary['total_service_charge'],
                'total_tip' => $summary['total_tip'],
                'total_paid' => $summary['total_paid'],
                'cash_total' => $summary['cash_total'],
                'card_total' => $summary['card_total'],
                'upi_total' => $summary['upi_total'],
                'room_charge_total' => $summary['room_charge_total'],
                'order_count' => $summary['order_count'],
                'void_count' => $summary['void_count'],
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        return response()->json([
            'message' => 'Day closed successfully.',
            'closing' => $closing->load('closedByUser'),
        ], $closing->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * List past day closings.
     * GET /pos/day-closings?restaurant_id=&from=&to=
     */
    public function index(Request $request)
    {
        $this->checkPermission('report-day-closings');
        $validated = $request->validate([
            'restaurant_id' => 'nullable|exists:restaurant_masters,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $query = PosDayClosing::with('restaurant', 'closedByUser')
            ->orderByDesc('closed_date');

        if (! empty($validated['restaurant_id'])) {
            $query->where('restaurant_id', $validated['restaurant_id']);
        }
        if (! empty($validated['from'])) {
            $query->where('closed_date', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $query->where('closed_date', '<=', $validated['to']);
        }

        $closings = $query->paginate($request->get('per_page', 20));

        return response()->json($closings);
    }

    /**
     * Export closing history (CSV / PDF) for the same filters as the list (no pagination — full range).
     */
    public function export(Request $request)
    {
        $this->checkPermission('report-day-closings');

        $validated = $request->validate([
            'restaurant_id' => 'nullable|exists:restaurant_masters,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'type' => 'nullable|in:csv,pdf',
        ]);

        $type = $validated['type'] ?? 'csv';

        if (! empty($validated['restaurant_id'])) {
            $this->authorizeRestaurantId((int) $validated['restaurant_id']);
        }

        $query = PosDayClosing::with('restaurant', 'closedByUser')
            ->orderByDesc('closed_date');

        if (! empty($validated['restaurant_id'])) {
            $query->where('restaurant_id', $validated['restaurant_id']);
        }
        if (! empty($validated['from'])) {
            $query->where('closed_date', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $query->where('closed_date', '<=', $validated['to']);
        }

        $closings = $query->get();

        $fromSlug = $validated['from'] ?? 'all';
        $toSlug = $validated['to'] ?? 'all';

        if ($type === 'pdf') {
            $pdf = Pdf::loadView('reports.day_closings', [
                'closings' => $closings,
                'from' => $validated['from'] ?? null,
                'to' => $validated['to'] ?? null,
            ]);

            return $pdf->download("closing_history_{$fromSlug}_to_{$toSlug}.pdf");
        }

        $fileName = "closing_history_{$fromSlug}_to_{$toSlug}.csv";
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$fileName}",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $columns = [
            'Business date',
            'Outlet',
            'Orders',
            'Total sales',
            'Discount',
            'Tax',
            'Service chg',
            'Tip',
            'Total paid',
            'Cash',
            'Card',
            'UPI',
            'Room charge',
            'Voids',
            'Opening bal',
            'Closing bal',
            'Closed by',
            'Closed at',
        ];

        $callback = function () use ($closings, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            foreach ($closings as $c) {
                fputcsv($file, [
                    $c->closed_date?->format('Y-m-d') ?? '',
                    $c->restaurant?->name ?? '—',
                    $c->order_count,
                    $c->total_sales,
                    $c->total_discount,
                    $c->total_tax,
                    $c->total_service_charge,
                    $c->total_tip,
                    $c->total_paid,
                    $c->cash_total,
                    $c->card_total,
                    $c->upi_total,
                    $c->room_charge_total,
                    $c->void_count,
                    $c->opening_balance,
                    $c->closing_balance,
                    $c->closedByUser?->name ?? '—',
                    $c->closed_at?->format('Y-m-d H:i') ?? '',
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function userCanAccessRestaurant(int $restaurantId): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        if ($user->hasRole('Admin') || $user->hasRole('Super Admin')) {
            return true;
        }

        $assigned = $user->restaurants()->pluck('restaurant_masters.id')->map(fn ($id) => (int) $id)->all();
        if (count($assigned) > 0) {
            return in_array($restaurantId, $assigned, true);
        }

        $deptIds = $user->departments()->pluck('departments.id')->map(fn ($id) => (int) $id)->all();
        if (count($deptIds) > 0) {
            return RestaurantMaster::where('id', $restaurantId)
                ->where('is_active', true)
                ->where(function ($q) use ($deptIds) {
                    $q->whereIn('department_id', $deptIds)->orWhereNull('department_id');
                })
                ->exists();
        }

        return false;
    }

    private function authorizeRestaurantId(int $restaurantId): void
    {
        if (! $this->userCanAccessRestaurant($restaurantId)) {
            abort(403, 'You do not have access to this outlet.');
        }
    }

    /**
     * Compute summary for a restaurant and date.
     * Uses orders where closed_at date = closed_date (paid or void).
     */
    private function computeSummary(int $restaurantId, string $closedDate): array
    {
        $businessDateMatch = function ($query) use ($closedDate) {
            $query->whereDate('business_date', $closedDate)
                ->orWhere(function ($legacy) use ($closedDate) {
                    $legacy->whereNull('business_date')
                        ->whereDate('closed_at', $closedDate);
                });
        };

        $paidOrders = PosOrder::where('restaurant_id', $restaurantId)
            ->whereIn('status', ['paid', 'refunded'])
            ->where($businessDateMatch);

        // Void orders never get closed_at; legacy rows without business_date must match voided_at (not closed_at).
        $voidOrders = PosOrder::where('restaurant_id', $restaurantId)
            ->where('status', 'void')
            ->where(function ($q) use ($closedDate) {
                $q->whereDate('business_date', $closedDate)
                    ->orWhere(function ($legacy) use ($closedDate) {
                        $legacy->whereNull('business_date')
                            ->where(function ($q2) use ($closedDate) {
                                $q2->whereDate('closed_at', $closedDate)
                                    ->orWhereDate('voided_at', $closedDate);
                            });
                    });
            });

        $openBilledBase = PosOrder::where('restaurant_id', $restaurantId)
            ->whereIn('status', ['open', 'billed'])
            ->where(function ($q) use ($closedDate) {
                $q->whereDate('business_date', $closedDate)
                    ->orWhere(function ($legacy) use ($closedDate) {
                        $legacy->whereNull('business_date')
                            ->whereDate('opened_at', $closedDate);
                    });
            });

        $openBilledCount = (clone $openBilledBase)->count();

        $openBilledOrders = (clone $openBilledBase)
            ->with(['table:id,table_number'])
            ->orderBy('id')
            ->get(['id', 'status', 'order_type', 'table_id', 'customer_name', 'total_amount', 'opened_at']);

        $voidOrdersList = (clone $voidOrders)
            ->with(['table:id,table_number', 'voidedBy:id,name'])
            ->orderByDesc('voided_at')
            ->get(['id', 'order_type', 'table_id', 'customer_name', 'total_amount', 'voided_at', 'void_reason', 'void_notes', 'voided_by']);

        $orderIds = (clone $paidOrders)->pluck('id');

        $totals = (clone $paidOrders)->selectRaw(
            'COALESCE(SUM(subtotal), 0) as total_sales,
             COALESCE(SUM(discount_amount), 0) as total_discount,
             COALESCE(SUM(tax_amount), 0) as total_tax,
             COALESCE(SUM(service_charge_amount), 0) as total_service_charge,
             COALESCE(SUM(tip_amount), 0) as total_tip,
             COALESCE(SUM(total_amount), 0) as total_paid'
        )->first();

        // Refunds are attributed to the date they were PERFORMED, not the order date.
        // This is critical for balancing the cash drawer today vs yesterday.
        $refundData = \App\Models\PosOrderRefund::whereHas('order', function ($q) use ($restaurantId) {
            $q->where('restaurant_id', $restaurantId);
        })
            ->where(function ($q) use ($closedDate) {
                $q->whereDate('business_date', $closedDate)
                    ->orWhere(function ($legacy) use ($closedDate) {
                        $legacy->whereNull('business_date')
                            ->whereDate('refunded_at', $closedDate);
                    });
            });

        $totalRefunded = (clone $refundData)->sum('amount');

        $paymentsByMethod = PosPayment::whereIn('order_id', $orderIds)
            ->selectRaw('method, COALESCE(SUM(amount), 0) as total')
            ->groupBy('method')
            ->pluck('total', 'method');

        $refundsByMethod = (clone $refundData)
            ->selectRaw('method, COALESCE(SUM(amount), 0) as total')
            ->groupBy('method')
            ->pluck('total', 'method');

        $orderCount = (clone $paidOrders)->count();
        $voidCount = (clone $voidOrders)->count();

        $cashTotal = (float) (($paymentsByMethod->get('cash', 0) ?? 0) - ($refundsByMethod->get('cash', 0) ?? 0));
        $cardTotal = (float) (($paymentsByMethod->get('card', 0) ?? 0) - ($refundsByMethod->get('card', 0) ?? 0));
        $upiTotal = (float) (($paymentsByMethod->get('upi', 0) ?? 0) - ($refundsByMethod->get('upi', 0) ?? 0));
        $roomChargeTotal = (float) (($paymentsByMethod->get('room_charge', 0) ?? 0) - ($refundsByMethod->get('room_charge', 0) ?? 0));

        return [
            'total_sales' => (float) ($totals->total_sales ?? 0),
            'total_discount' => (float) ($totals->total_discount ?? 0),
            'total_tax' => (float) ($totals->total_tax ?? 0),
            'total_service_charge' => (float) ($totals->total_service_charge ?? 0),
            'total_tip' => (float) ($totals->total_tip ?? 0),
            'total_refunded' => (float) $totalRefunded,
            'total_paid' => (float) (($totals->total_paid ?? 0) - $totalRefunded),
            'cash_total' => $cashTotal,
            'card_total' => $cardTotal,
            'upi_total' => $upiTotal,
            'room_charge_total' => $roomChargeTotal,
            'order_count' => $orderCount,
            'void_count' => $voidCount,
            'open_billed_count' => $openBilledCount,
            'open_billed_orders' => $openBilledOrders->map(fn ($o) => [
                'id' => $o->id,
                'status' => $o->status,
                'order_type' => $o->order_type,
                'table_label' => $o->table?->table_number !== null ? 'T-'.$o->table->table_number : null,
                'customer_name' => $o->customer_name,
                'total_amount' => (float) $o->total_amount,
                'opened_at' => $o->opened_at?->toIso8601String(),
            ])->values()->all(),
            'void_orders' => $voidOrdersList->map(fn ($o) => [
                'id' => $o->id,
                'order_type' => $o->order_type,
                'table_label' => $o->table?->table_number !== null ? 'T-'.$o->table->table_number : null,
                'customer_name' => $o->customer_name,
                'total_amount' => (float) $o->total_amount,
                'voided_at' => $o->voided_at?->toIso8601String(),
                'void_reason' => $o->void_reason,
                'void_notes' => $o->void_notes,
                'voided_by_name' => $o->voidedBy?->name,
            ])->values()->all(),
        ];
    }

    /**
     * Inventory intelligence before day close: negative stock at outlet stores,
     * incomplete inter-store requisitions, optional production vs sales snapshot.
     */
    private function computeInventoryPrecheck(int $restaurantId, string $closedDate): array
    {
        $restaurant = RestaurantMaster::query()
            ->select(['id', 'department_id', 'kitchen_location_id', 'bar_location_id'])
            ->find($restaurantId);

        $locIds = array_values(array_filter([
            $restaurant?->kitchen_location_id,
            $restaurant?->bar_location_id,
        ], fn ($v) => $v !== null && $v !== ''));

        $negativeItems = collect();
        if (! empty($locIds)) {
            $negativeItems = DB::table('inventory_item_locations')
                ->join('inventory_items', 'inventory_items.id', '=', 'inventory_item_locations.inventory_item_id')
                ->join('inventory_locations', 'inventory_locations.id', '=', 'inventory_item_locations.inventory_location_id')
                ->whereIn('inventory_item_locations.inventory_location_id', $locIds)
                ->where('inventory_item_locations.quantity', '<', 0)
                ->orderBy('inventory_item_locations.quantity')
                ->limit(40)
                ->get([
                    'inventory_items.name',
                    'inventory_item_locations.quantity',
                    'inventory_locations.name as location_name',
                ]);
        }

        $incompleteStatuses = ['pending', 'approved', 'partially_issued', 'awaiting_acceptance'];

        $pendingReqQuery = StoreRequest::query()
            ->with(['fromLocation:id,name', 'toLocation:id,name'])
            ->whereIn('status', $incompleteStatuses);

        if (! empty($locIds)) {
            $pendingReqQuery->where(function ($q) use ($locIds, $restaurant) {
                $q->whereIn('from_location_id', $locIds)
                    ->orWhereIn('to_location_id', $locIds);
                if ($restaurant?->department_id) {
                    $q->orWhere('department_id', $restaurant->department_id);
                }
            });
        } elseif ($restaurant?->department_id) {
            $pendingReqQuery->where('department_id', $restaurant->department_id);
        } else {
            $pendingReqQuery->whereRaw('1 = 0');
        }

        $pendingRequests = $pendingReqQuery
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'request_number', 'status', 'from_location_id', 'to_location_id', 'requested_at']);

        $pendingList = $pendingRequests->map(fn ($r) => [
            'id' => $r->id,
            'request_number' => $r->request_number,
            'status' => $r->status,
            'from' => $r->fromLocation?->name ?? '#'.$r->from_location_id,
            'to' => $r->toLocation?->name ?? '#'.$r->to_location_id,
            'requested_at' => $r->requested_at?->toIso8601String(),
        ])->values()->all();

        $kitchenId = $restaurant?->kitchen_location_id;

        $soldByItem = DB::table('pos_order_items')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_order_items.order_id')
            ->where('pos_orders.restaurant_id', $restaurantId)
            ->whereIn('pos_orders.status', ['paid', 'refunded'])
            ->where(function ($q) use ($closedDate) {
                $q->whereDate('pos_orders.business_date', $closedDate)
                    ->orWhere(function ($legacy) use ($closedDate) {
                        $legacy->whereNull('pos_orders.business_date')
                            ->whereDate('pos_orders.closed_at', $closedDate);
                    });
            })
            ->whereNotNull('pos_order_items.menu_item_id')
            ->groupBy('pos_order_items.menu_item_id')
            ->selectRaw('pos_order_items.menu_item_id, SUM(pos_order_items.quantity) as qty')
            ->pluck('qty', 'menu_item_id');

        $voidedQtyByItem = DB::table('pos_order_items')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_order_items.order_id')
            ->where('pos_orders.restaurant_id', $restaurantId)
            ->where('pos_orders.status', 'void')
            ->where(function ($q) use ($closedDate) {
                $q->whereDate('pos_orders.business_date', $closedDate)
                    ->orWhere(function ($legacy) use ($closedDate) {
                        $legacy->whereNull('pos_orders.business_date')
                            ->where(function ($q2) use ($closedDate) {
                                $q2->whereDate('pos_orders.closed_at', $closedDate)
                                    ->orWhereDate('pos_orders.voided_at', $closedDate);
                            });
                    });
            })
            ->whereNotNull('pos_order_items.menu_item_id')
            ->groupBy('pos_order_items.menu_item_id')
            ->selectRaw('pos_order_items.menu_item_id, SUM(pos_order_items.quantity) as qty')
            ->pluck('qty', 'menu_item_id');

        $producedQuery = DB::table('production_logs')
            ->join('recipes', 'recipes.id', '=', 'production_logs.recipe_id')
            ->join('menu_items', 'menu_items.id', '=', 'recipes.menu_item_id')
            ->whereDate('production_logs.production_date', $closedDate);

        if ($kitchenId) {
            $producedQuery->where(function ($q) use ($kitchenId) {
                $q->where('production_logs.inventory_location_id', $kitchenId)
                    ->orWhereNull('production_logs.inventory_location_id');
            });
        }

        $producedByItem = $producedQuery
            ->groupBy('recipes.menu_item_id', 'menu_items.name')
            ->selectRaw('recipes.menu_item_id, menu_items.name, SUM(production_logs.quantity_produced) as qty')
            ->get()
            ->keyBy(fn ($r) => (int) $r->menu_item_id);

        /** Menu items that use batch production logging (Kitchen Production) — excludes MTO / direct-sale recipes. */
        $batchProductionMenuIds = DB::table('recipes')
            ->where('is_active', true)
            ->where('requires_production', true)
            ->pluck('menu_item_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->all();
        $batchProductionMenuSet = array_fill_keys($batchProductionMenuIds, true);

        $allMenuIds = $soldByItem->keys()
            ->merge($voidedQtyByItem->keys())
            ->merge($producedByItem->keys())
            ->unique()
            ->filter();

        $productionRows = [];
        foreach ($allMenuIds as $mid) {
            $mid = (int) $mid;
            $pRow = $producedByItem->get($mid);
            $name = $pRow->name ?? \App\Models\MenuItem::where('id', $mid)->value('name') ?? 'Item #'.$mid;
            $produced = (float) ($pRow->qty ?? 0);
            $sold = (float) ($soldByItem->get($mid) ?? $soldByItem->get((string) $mid) ?? 0);
            $voided = (float) ($voidedQtyByItem->get($mid) ?? $voidedQtyByItem->get((string) $mid) ?? 0);
            if ($produced <= 0 && $sold <= 0 && $voided <= 0) {
                continue;
            }
            // Omit made-to-order / direct-sale items with no batch production log (avoids meaningless negative implied).
            if ($produced <= 0 && empty($batchProductionMenuSet[$mid])) {
                continue;
            }
            $implied = $produced - $sold - $voided;
            $productionRows[] = [
                'menu_item_id' => $mid,
                'name' => $name,
                'produced' => round($produced, 3),
                'sold' => round($sold, 3),
                'voided' => round($voided, 3),
                'implied_remaining' => round($implied, 3),
            ];
        }

        usort($productionRows, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $productionRows = array_slice($productionRows, 0, 40);

        $negCount = $negativeItems->count();
        $pendingCount = $pendingRequests->count();

        return [
            'negative_stock' => [
                'count' => $negCount,
                'items' => $negativeItems->map(fn ($row) => [
                    'name' => $row->name,
                    'quantity' => (float) $row->quantity,
                    'location_name' => $row->location_name,
                ])->values()->all(),
            ],
            'pending_requisitions' => [
                'count' => $pendingCount,
                'requests' => $pendingList,
            ],
            'production_snapshot' => [
                'rows' => $productionRows,
                'note' => 'Only menu items with batch production (active recipe with “requires production”) are listed. Produced = kitchen logs for this calendar date; sold/void = POS for this business date. Implied = produced − sold − void — for review vs the physical pot (wastage/adjustments). Items sold only as made-to-order or direct sale without batch logs are omitted.',
            ],
            'can_close' => $negCount === 0 && $pendingCount === 0,
        ];
    }
}
