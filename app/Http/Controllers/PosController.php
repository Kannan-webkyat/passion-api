<?php

namespace App\Http\Controllers;

use App\Events\PosRestaurantUpdated;
use App\Models\Booking;
use App\Models\Combo;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTransaction;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PosDayClosing;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosOrderRefund;
use App\Models\PosPayment;
use App\Models\Recipe;
use App\Models\RestaurantCombo;
use App\Models\RestaurantMaster;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantMenuItemVariant;
use App\Models\RestaurantTable;
use App\Models\Setting;
use App\Models\User;
use App\Services\BusinessDateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PosController extends Controller
{
    /**
     * Recompute stored tax split columns (maintenance / backfill). Does not change permission checks.
     */
    public function maintenanceRecalculateOrderTotals(PosOrder $order): void
    {
        $this->recalculate($order);
    }

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

    private function authorizeOrderAccess(PosOrder $order): void
    {
        if (! $this->userCanAccessRestaurant((int) $order->restaurant_id)) {
            abort(403, 'You do not have access to this outlet.');
        }
    }

    private function authorizeRestaurantId(int $restaurantId): void
    {
        if (! $this->userCanAccessRestaurant($restaurantId)) {
            abort(403, 'You do not have access to this outlet.');
        }
    }

    /**
     * Y-m-d business date for locks: prefers stored business_date; for legacy rows uses opened_at with outlet cutoff.
     */
    private function businessDateStringForOrder(PosOrder $order): string
    {
        $order->loadMissing('restaurant');
        if ($order->business_date) {
            return $order->business_date->toDateString();
        }

        $at = $order->opened_at ?? $order->created_at;

        return BusinessDateService::resolve($order->restaurant, $at);
    }

    private function isBusinessDateClosedForOrder(PosOrder $order): bool
    {
        return PosDayClosing::where('restaurant_id', $order->restaurant_id)
            ->where('closed_date', $this->businessDateStringForOrder($order))
            ->exists();
    }

    /** Kitchen KOT lines (bulk send, hold, fire). */
    private function orderItemRequiresKot(PosOrderItem $item): bool
    {
        if ($item->status !== 'active') {
            return false;
        }
        if ($item->combo_id) {
            return true;
        }
        if ($item->menu_item_id) {
            $item->loadMissing('menuItem');

            return (bool) ($item->menuItem?->requires_production ?? true);
        }

        return true;
    }

    /** Batches where every active KOT line has the given timestamp column set. */
    private function kotBatchNumbersWhereAllLinesHave(PosOrder $order, string $column): array
    {
        $order->loadMissing('items');
        $kotItems = $order->items->filter(fn ($i) => $i->status === 'active' && $i->kot_sent);
        if ($kotItems->isEmpty()) {
            return [];
        }
        $byBatch = $kotItems->groupBy(fn ($i) => (int) ($i->kot_batch ?? 1));
        $out = [];
        foreach ($byBatch as $batch => $items) {
            if ($items->every(fn ($i) => $i->{$column} !== null)) {
                $out[] = (int) $batch;
            }
        }
        sort($out);

        return $out;
    }

    /** @param  \Illuminate\Support\Collection<int, PosOrderItem>  $kotItems */
    private function kotBatchesFromItems($kotItems, string $column): array
    {
        if ($kotItems->isEmpty()) {
            return [];
        }
        $byBatch = $kotItems->groupBy(fn ($i) => (int) ($i->kot_batch ?? 1));
        $out = [];
        foreach ($byBatch as $batch => $items) {
            if ($items->every(fn ($i) => $i->{$column} !== null)) {
                $out[] = (int) $batch;
            }
        }
        sort($out);

        return $out;
    }

    /** Active KOT lines (kitchen) — total / ready / served counts for waiter-facing summaries. */
    private function kotLineCounts(PosOrder $order): array
    {
        $order->loadMissing('items.menuItem');
        $kotLines = $order->items
            ->where('status', 'active')
            ->where('kot_sent', true)
            ->filter(fn ($i) => $this->orderItemRequiresKot($i))
            ->values();
        $total = $kotLines->count();
        $ready = $kotLines->filter(fn ($i) => $i->kitchen_ready_at)->count();
        $served = $kotLines->filter(fn ($i) => $i->kitchen_served_at)->count();

        return ['total' => $total, 'ready' => $ready, 'served' => $served];
    }

    /** Notify POS / kitchen UIs (Reverb) that outlet state changed. */
    private function broadcastPosOutletUpdate(int $restaurantId, ?int $orderId = null): void
    {
        if (config('broadcasting.default') === 'null') {
            return;
        }
        event(new PosRestaurantUpdated($restaurantId, $orderId));
    }

    // ── Restaurants ──────────────────────────────────────────────────────────

    // ── Waiters (for Change Waiter dropdown) ──────────────────────────────────

    public function waiters(Request $request)
    {
        $this->checkPermission('pos-order');
        $users = User::role(['Waiter', 'Senior Waiter'])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->keyBy('id');

        $currentId = $request->integer('current_waiter_id');
        if ($currentId && ! $users->has($currentId)) {
            $current = User::find($currentId, ['id', 'name']);
            if ($current) {
                $users->put($current->id, ['id' => $current->id, 'name' => $current->name]);
            }
        }

        return response()->json($users->values()->all());
    }

    // ── Checked-in rooms for Room Service ────────────────────────────────────

    public function rooms()
    {
        $this->checkPermission('pos-order');
        $rooms = DB::table('bookings')
            ->join('rooms', 'bookings.room_id', '=', 'rooms.id')
            ->where('bookings.status', 'checked_in')
            ->select(
                'rooms.id as room_id',
                'rooms.room_number',
                'bookings.id as booking_id',
                'bookings.first_name',
                'bookings.last_name',
                'bookings.phone'
            )
            ->orderBy('rooms.room_number')
            ->get();

        return response()->json($rooms);
    }

    // ── Open takeaway & room service orders ──────────────────────────────────

    public function activeOrders(Request $request)
    {
        $this->checkPermission('pos-order');
        $request->validate(['restaurant_id' => 'required|exists:restaurant_masters,id']);
        $this->authorizeRestaurantId((int) $request->restaurant_id);

        $orders = PosOrder::query()->with(['room'])
            ->where('restaurant_id', '=', $request->restaurant_id)
            ->whereIn('order_type', ['takeaway', 'room_service', 'delivery', 'walk_in'])
            ->whereIn('status', ['open', 'billed'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) {
                $activeItems = $order->items()->where('status', 'active')->get();
                $kotItems = $activeItems->where('kot_sent', true);
                $itemCount = $activeItems->sum('quantity');
                $total = $order->total_amount;
                $readyBatches = $this->kotBatchesFromItems($kotItems, 'kitchen_ready_at');
                $servedBatches = $this->kotBatchesFromItems($kotItems, 'kitchen_served_at');
                $kotCounts = $this->kotLineCounts($order);

                return [
                    'id' => $order->id,
                    'order_type' => $order->order_type,
                    'status' => $order->status,
                    'kitchen_status' => $order->kitchen_status ?? 'pending',
                    'ready_batches' => $readyBatches,
                    'served_batches' => $servedBatches,
                    'kot_lines_total' => $kotCounts['total'],
                    'kot_lines_ready' => $kotCounts['ready'],
                    'kot_lines_served' => $kotCounts['served'],
                    'room_number' => $order->room?->room_number,
                    'customer_name' => $order->customer_name,
                    'customer_phone' => $order->customer_phone,
                    'delivery_address' => $order->delivery_address,
                    'delivery_channel' => $order->delivery_channel,
                    'item_count' => (int) $itemCount,
                    'total' => (float) $total,
                    'opened_at' => $order->created_at,
                ];
            });

        return response()->json($orders);
    }

    public function restaurants()
    {
        $user = auth()->user();
        $query = RestaurantMaster::where('is_active', true)
            ->with(['department'])
            ->withCount('tables');

        // Non-admins filtering
        if ($user && ! $user->hasRole('Admin') && ! $user->hasRole('Super Admin')) {
            $assignedOutletIds = $user->restaurants()->pluck('restaurant_masters.id')->toArray();

            if (count($assignedOutletIds) > 0) {
                // 1. If user has direct assignments, strictly use those.
                $query->whereIn('id', $assignedOutletIds);
            } else {
                // 2. Fallback to department-based access if no direct mapping exists.
                $deptIds = $user->departments()->pluck('departments.id')->toArray();
                if (count($deptIds) > 0) {
                    $query->where(function ($q) use ($deptIds) {
                        $q->whereIn('department_id', $deptIds)->orWhereNull('department_id');
                    });
                }
            }
        }

        return response()->json($query->get());
    }

    public function receiptConfig(RestaurantMaster $restaurant)
    {
        $this->checkPermission('pos-order');
        $this->authorizeRestaurantId((int) $restaurant->id);
        $defaults = Setting::getReceiptDefaults();
        $config = [
            'restaurant_name' => $restaurant->name,
            'address' => $restaurant->address ?: ($defaults['address'] ?? ''),
            'email' => $restaurant->email ?: ($defaults['email'] ?? ''),
            'phone' => $restaurant->phone ?: ($defaults['phone'] ?? ''),
            'gstin' => $restaurant->gstin ?: '',
            'fssai' => $restaurant->fssai ?: '',
            'sac_code' => $restaurant->sac_code ?: '',
            'logo_url' => $restaurant->logo_path
                ? asset('storage/'.$restaurant->logo_path)
                : ($defaults['logo_url'] ?? null),
        ];

        return response()->json($config);
    }

    // ── POS Reports ──────────────────────────────────────────────────────────

    /**
     * Sales summary report (paid orders) with payment breakdown.
     *
     * Query params:
     * - from (Y-m-d) required
     * - to (Y-m-d) required
     * - restaurant_id optional (filters to one outlet)
     */
    public function salesReport(Request $request)
    {
        $this->checkPermission('report-sales');

        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'restaurant_id' => 'nullable|integer|exists:restaurant_masters,id',
        ]);

        $user = auth()->user();
        $from = $validated['from'];
        $to = $validated['to'];
        $restaurantId = $validated['restaurant_id'] ?? null;

        if ($restaurantId) {
            $this->authorizeRestaurantId((int) $restaurantId);
        }

        // Limit outlets for non-admin users when restaurant_id is not provided.
        $allowedOutletIds = null;
        if (! $restaurantId && $user && ! $user->hasRole('Admin') && ! $user->hasRole('Super Admin')) {
            $assigned = $user->restaurants()->pluck('restaurant_masters.id')->map(fn ($id) => (int) $id)->all();
            if (count($assigned) > 0) {
                $allowedOutletIds = $assigned;
            } else {
                $deptIds = $user->departments()->pluck('departments.id')->map(fn ($id) => (int) $id)->all();
                if (count($deptIds) > 0) {
                    $allowedOutletIds = RestaurantMaster::where('is_active', true)
                        ->where(function ($q) use ($deptIds) {
                            $q->whereIn('department_id', $deptIds)->orWhereNull('department_id');
                        })
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                } else {
                    $allowedOutletIds = [];
                }
            }
        }

        // Force a specific outlet if none provided and user has access to some
        if (!$restaurantId) {
            if ($user && ($user->hasRole('Admin') || $user->hasRole('Super Admin'))) {
                $restaurantId = RestaurantMaster::where('is_active', true)->first()?->id;
            } elseif (is_array($allowedOutletIds) && count($allowedOutletIds) > 0) {
                $restaurantId = $allowedOutletIds[0];
            }
        }

        if (!$restaurantId) {
            return response()->json(['data' => [], 'summary' => null, 'payments' => []]);
        }

        // ── 1. Calculate Consolidated Totals for Dashboard ──
        $baseOrdersQ = DB::table('pos_orders')
            ->whereIn('status', ['paid', 'refunded'])
            ->where('restaurant_id', (int)$restaurantId)
            ->whereDate('business_date', '>=', $from)
            ->whereDate('business_date', '<=', $to);

        $agg = (clone $baseOrdersQ)->select(
            DB::raw('COUNT(*) as orders_count'),
            DB::raw('SUM(total_amount) as total_amount'),
            DB::raw('SUM(subtotal) as subtotal'),
            DB::raw('SUM(tax_amount) as tax_amount'),
            DB::raw('SUM(COALESCE(cgst_amount, 0)) as cgst_amount'),
            DB::raw('SUM(COALESCE(sgst_amount, 0)) as sgst_amount'),
            DB::raw('SUM(COALESCE(igst_amount, 0)) as igst_amount'),
            DB::raw('SUM(COALESCE(vat_tax_amount, 0)) as vat_tax_amount'),
            DB::raw('SUM(COALESCE(gst_net_taxable, 0)) as gst_net_taxable'),
            DB::raw('SUM(COALESCE(vat_net_taxable, 0)) as vat_net_taxable'),
            DB::raw('SUM(discount_amount) as discount_amount'),
            DB::raw('SUM(service_charge_amount) as service_charge_amount'),
            DB::raw('SUM(tip_amount) as tip_amount'),
            DB::raw('SUM(delivery_charge) as delivery_charge')
        )->first();

        $voidedQ = DB::table('pos_orders')
            ->where('status', 'void')
            ->where('restaurant_id', (int)$restaurantId)
            ->whereDate('voided_at', '>=', $from)
            ->whereDate('voided_at', '<=', $to);

        $voidedCount = (int)$voidedQ->count();
        $voidedAmount = (float)$voidedQ->sum('total_amount');

        $refundsQ = DB::table('pos_order_refunds')
            ->join('pos_orders', 'pos_order_refunds.order_id', '=', 'pos_orders.id')
            ->where('pos_orders.restaurant_id', (int)$restaurantId)
            ->whereDate('pos_order_refunds.business_date', '>=', $from)
            ->whereDate('pos_order_refunds.business_date', '<=', $to);
        
        $totalRefundedAmount = (float)$refundsQ->sum('pos_order_refunds.amount');
        $refundsByMethod = $refundsQ->select('pos_order_refunds.method', DB::raw('SUM(pos_order_refunds.amount) as amount'))
            ->groupBy('pos_order_refunds.method')
            ->get()
            ->pluck('amount', 'method');

        $aggData = $agg ?? (object)[
            'orders_count' => 0, 'total_amount' => 0, 'subtotal' => 0,
            'tax_amount' => 0, 'cgst_amount' => 0, 'sgst_amount' => 0, 'igst_amount' => 0,
            'vat_tax_amount' => 0, 'gst_net_taxable' => 0, 'vat_net_taxable' => 0,
            'discount_amount' => 0,
            'service_charge_amount' => 0, 'tip_amount' => 0, 'delivery_charge' => 0,
        ];
        $aggTotal = (float)$aggData->total_amount;
        $summary = [
            'orders_count' => (int)$aggData->orders_count,
            'subtotal' => (float)$aggData->subtotal,
            'tax_amount' => (float)$aggData->tax_amount,
            'cgst_amount' => (float)($aggData->cgst_amount ?? 0),
            'sgst_amount' => (float)($aggData->sgst_amount ?? 0),
            'igst_amount' => (float)($aggData->igst_amount ?? 0),
            'vat_tax_amount' => (float)($aggData->vat_tax_amount ?? 0),
            'gst_net_taxable' => (float)($aggData->gst_net_taxable ?? 0),
            'vat_net_taxable' => (float)($aggData->vat_net_taxable ?? 0),
            'discount_amount' => (float)$aggData->discount_amount,
            'service_charge_amount' => (float)$aggData->service_charge_amount,
            'tip_amount' => (float)$aggData->tip_amount,
            'delivery_charge' => (float)$aggData->delivery_charge,
            'total_amount' => $aggTotal,
            'voided_count' => $voidedCount,
            'voided_amount' => $voidedAmount,
            'total_refunded' => $totalRefundedAmount,
            'net_realized' => $aggTotal - $totalRefundedAmount
        ];

        // ── 2. Payment Breakdown for Dashboard ──
        $orderIds = $baseOrdersQ->pluck('id')->all();
        $payByMethod = [];
        if (!empty($orderIds)) {
            $payByMethod = DB::table('pos_payments')
                ->whereIn('order_id', $orderIds)
                ->select('method', DB::raw('SUM(amount) as amount'), DB::raw('COUNT(*) as count'))
                ->groupBy('method')
                ->get()
                ->map(function($p) use ($refundsByMethod) {
                    $refAmt = (float)($refundsByMethod[$p->method] ?? 0);
                    return [
                        'method' => $p->method,
                        'gross_amount' => (float)$p->amount,
                        'refund_amount' => $refAmt,
                        'net_amount' => (float)$p->amount - $refAmt,
                        'count' => $p->count
                    ];
                });
        }

        // ── 3. Paginated Bills for Table ──
        $ordersQ = PosOrder::whereIn('status', ['paid', 'refunded', 'void'])
            ->where('restaurant_id', (int)$restaurantId)
            ->where(function($q) use ($from, $to) {
                $q->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)
                  ->orWhere(function($sq) use ($from, $to) {
                      $sq->where('status', 'void')->whereDate('voided_at', '>=', $from)->whereDate('voided_at', '<=', $to);
                  });
            })
            ->with(['waiter:id,name', 'refunds', 'restaurant:id,name']);

        $paginated = $ordersQ->orderBy('id', 'desc')->paginate(50);
        
        $data = collect($paginated->items())->map(function ($o) {
            return [
                'id' => $o->id,
                'business_date' => $o->business_date,
                'waiter' => $o->waiter?->name ?? '—',
                'restaurant' => $o->restaurant?->name ?? '—',
                'order_type' => $o->order_type ?? 'dine_in',
                'status' => $o->status,
                'total_amount' => (float) $o->total_amount,
                'refunded_amount' => (float) $o->refunds->sum('amount'),
                'closed_at' => $o->closed_at?->toDateTimeString(),
                'voided_at' => $o->voided_at?->toDateTimeString(),
                'cgst_amount' => (float) ($o->cgst_amount ?? 0),
                'sgst_amount' => (float) ($o->sgst_amount ?? 0),
                'igst_amount' => (float) ($o->igst_amount ?? 0),
                'vat_tax_amount' => (float) ($o->vat_tax_amount ?? 0),
                'gst_net_taxable' => (float) ($o->gst_net_taxable ?? 0),
                'vat_net_taxable' => (float) ($o->vat_net_taxable ?? 0),
            ];
        });

        return response()->json([
            'summary' => $summary,
            'payments' => $payByMethod,
            'data' => $data,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'total' => $paginated->total()
            ]
        ]);
    }

    /**
     * Liquor (state VAT) line register: same structure as food/GST report, filtered to liquor VAT lines only.
     */
    public function liquorSalesReport(Request $request)
    {
        $this->checkPermission('report-sales');

        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'restaurant_id' => 'nullable|integer|exists:restaurant_masters,id',
            'page' => 'nullable|integer|min:1',
            'group_by' => 'nullable|string|in:line,item,invoice',
        ]);

        $user = auth()->user();
        $from = $validated['from'];
        $to = $validated['to'];
        $page = (int) ($validated['page'] ?? 1);
        $restaurantId = $validated['restaurant_id'] ?? null;
        $groupBy = (string) ($validated['group_by'] ?? 'line');
        if (! in_array($groupBy, ['line', 'item', 'invoice'], true)) {
            $groupBy = 'line';
        }

        if ($restaurantId) {
            $this->authorizeRestaurantId((int) $restaurantId);
        }

        $allowedOutletIds = null;
        if (! $restaurantId && $user && ! $user->hasRole('Admin') && ! $user->hasRole('Super Admin')) {
            $assigned = $user->restaurants()->pluck('restaurant_masters.id')->map(fn ($id) => (int) $id)->all();
            if (count($assigned) > 0) {
                $allowedOutletIds = $assigned;
            } else {
                $deptIds = $user->departments()->pluck('departments.id')->map(fn ($id) => (int) $id)->all();
                if (count($deptIds) > 0) {
                    $allowedOutletIds = RestaurantMaster::where('is_active', true)
                        ->where(function ($q) use ($deptIds) {
                            $q->whereIn('department_id', $deptIds)->orWhereNull('department_id');
                        })
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                } else {
                    $allowedOutletIds = [];
                }
            }
        }

        if (! $restaurantId) {
            if ($user && ($user->hasRole('Admin') || $user->hasRole('Super Admin'))) {
                $restaurantId = RestaurantMaster::where('is_active', true)->first()?->id;
            } elseif (is_array($allowedOutletIds) && count($allowedOutletIds) > 0) {
                $restaurantId = $allowedOutletIds[0];
            }
        }

        if (! $restaurantId) {
            $emptyFiling = [
                'lines_count' => 0,
                'qty_total' => 0.0,
                'revenue_total' => 0.0,
                'bills_count' => 0,
                'note' => 'Active lines on paid or refunded orders only; void bills and cancelled lines excluded. Not a government return file; refunds and adjustments per CA.',
            ];

            return response()->json([
                'summary' => [
                    'lines_count' => 0,
                    'active_lines_count' => 0,
                    'cancelled_lines_count' => 0,
                    'qty_total' => 0.0,
                    'revenue_total' => 0.0,
                    'bills_count' => 0,
                ],
                'vat_filing' => $emptyFiling,
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'total' => 0,
                    'group_by' => $groupBy,
                ],
            ]);
        }

        $rid = (int) $restaurantId;
        $base = $this->liquorSalesLinesBaseQuery($rid, $from, $to);

        $agg = (clone $base)->selectRaw(
            'COUNT(pos_order_items.id) as lines_count,
            SUM(CASE WHEN pos_order_items.status = \'active\' THEN 1 ELSE 0 END) as active_lines_count,
            SUM(CASE WHEN pos_order_items.status = \'cancelled\' THEN 1 ELSE 0 END) as cancelled_lines_count,
            COALESCE(SUM(CASE WHEN pos_order_items.status = \'active\' THEN pos_order_items.quantity ELSE 0 END), 0) as qty_total,
            COALESCE(SUM(CASE WHEN pos_order_items.status = \'active\' THEN pos_order_items.line_total ELSE 0 END), 0) as revenue_total,
            COUNT(DISTINCT pos_orders.id) as bills_count'
        )->first();

        $vatFiling = $this->registerStatutoryFilingSummaryFromBase($base);

        if ($groupBy === 'item' || $groupBy === 'invoice') {
            $perPage = 50;
            $allRows = $this->liquorSalesBuildAggregatedRows($rid, $from, $to, $groupBy);
            $total = count($allRows);
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = min(max(1, $page), $lastPage);
            $slice = array_slice($allRows, ($page - 1) * $perPage, $perPage);

            return response()->json([
                'summary' => [
                    'lines_count' => (int) ($agg->lines_count ?? 0),
                    'active_lines_count' => (int) ($agg->active_lines_count ?? 0),
                    'cancelled_lines_count' => (int) ($agg->cancelled_lines_count ?? 0),
                    'qty_total' => (float) ($agg->qty_total ?? 0),
                    'revenue_total' => (float) ($agg->revenue_total ?? 0),
                    'bills_count' => (int) ($agg->bills_count ?? 0),
                ],
                'vat_filing' => $vatFiling,
                'data' => $slice,
                'meta' => [
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'total' => $total,
                    'group_by' => $groupBy,
                ],
            ]);
        }

        $paginated = (clone $base)
            ->select([
                'pos_order_items.id as line_id',
                'pos_order_items.order_id',
                'pos_order_items.quantity',
                'pos_order_items.unit_price',
                'pos_order_items.tax_rate',
                'pos_order_items.line_total',
                'pos_order_items.combo_id',
                'pos_order_items.status as line_status',
                'menu_items.name as menu_name',
                'menu_item_variants.size_label',
                'combos.name as combo_name',
                'pos_orders.business_date',
                'pos_orders.closed_at',
                'pos_orders.voided_at',
                'pos_orders.status as order_status',
                'pos_orders.customer_name',
                'pos_orders.customer_gstin',
                'users.name as waiter_name',
                DB::raw('(SELECT u.name FROM pos_payments pp INNER JOIN users u ON u.id = pp.received_by WHERE pp.order_id = pos_orders.id AND pp.received_by IS NOT NULL ORDER BY COALESCE(pp.paid_at, pp.created_at) DESC, pp.id DESC LIMIT 1) as cashier_name'),
            ])
            ->orderByDesc('pos_order_items.id')
            ->paginate(50, ['*'], 'page', $page);

        $lineIds = collect($paginated->items())->pluck('line_id')->map(fn ($id) => (int) $id)->all();
        $itemsById = PosOrderItem::whereIn('id', $lineIds)
            ->with([
                'menuItem.tax',
                'combo.menuItems.tax',
                'order.refunds',
                'order.items' => fn ($q) => $q->where('status', 'active'),
                'order.items.menuItem.tax',
                'order.items.combo.menuItems.tax',
            ])
            ->get()
            ->keyBy('id');

        $orderIdsForPayment = collect($paginated->items())->pluck('order_id')->unique()->map(fn ($id) => (int) $id)->values()->all();
        $paymentByOrder = $this->foodSalesPaymentMethodsForOrderIds($orderIdsForPayment);

        $data = collect($paginated->items())->map(function ($row) use ($itemsById, $paymentByOrder) {
            $comboId = $row->combo_id ?? null;
            if ($comboId) {
                $display = 'Combo: '.((string) ($row->combo_name ?? '') !== '' ? $row->combo_name : '—');
            } else {
                $name = (string) ($row->menu_name ?? '—');
                $variant = trim((string) ($row->size_label ?? ''));
                $display = $variant !== '' ? $name.' — '.$variant : $name;
            }

            $item = $itemsById->get((int) $row->line_id);
            $extras = ($item && $item->order)
                ? $this->computeFoodLineRegisterFields($item, $item->order)
                : [
                    'line_gross' => 0.0,
                    'line_discount' => 0.0,
                    'line_after_discount' => 0.0,
                    'net_taxable' => 0.0,
                    'tax_amount' => 0.0,
                    'cgst' => 0.0,
                    'sgst' => 0.0,
                    'igst' => 0.0,
                    'refund_alloc' => 0.0,
                    'service_charge_alloc' => 0.0,
                    'tip_alloc' => 0.0,
                    'delivery_alloc' => 0.0,
                    'rounding_alloc' => 0.0,
                    'sheet_adjustments' => 0.0,
                    'tax_inclusive' => false,
                    'tax_pricing' => 'Exclusive',
                    'line_status' => (string) ($row->line_status ?? 'active'),
                ];

            return [
                'row_kind' => 'line',
                'row_id' => 'line:'.(int) $row->line_id,
                'lines_count' => 1,
                'item_key' => null,
                'line_id' => (int) $row->line_id,
                'order_id' => (int) $row->order_id,
                'line_status' => (string) ($extras['line_status'] ?? $row->line_status ?? 'active'),
                'customer_name' => $row->customer_name !== null && trim((string) $row->customer_name) !== ''
                    ? trim((string) $row->customer_name)
                    : null,
                'customer_gstin' => $row->customer_gstin !== null && trim((string) $row->customer_gstin) !== ''
                    ? trim((string) $row->customer_gstin)
                    : null,
                'display_name' => $display,
                'quantity' => (float) $row->quantity,
                'unit_price' => (float) $row->unit_price,
                'tax_rate' => (float) $row->tax_rate,
                'tax_rate_mixed' => false,
                'line_total' => (float) $row->line_total,
                'line_gross' => $extras['line_gross'],
                'line_discount' => $extras['line_discount'],
                'line_after_discount' => $extras['line_after_discount'],
                'net_taxable' => $extras['net_taxable'],
                'tax_amount' => $extras['tax_amount'],
                'vat_amount' => $extras['tax_amount'],
                'cgst' => $extras['cgst'],
                'sgst' => $extras['sgst'],
                'igst' => $extras['igst'],
                'refund_alloc' => $extras['refund_alloc'],
                'service_charge_alloc' => $extras['service_charge_alloc'],
                'tip_alloc' => $extras['tip_alloc'],
                'delivery_alloc' => $extras['delivery_alloc'],
                'rounding_alloc' => $extras['rounding_alloc'],
                'sheet_adjustments' => $extras['sheet_adjustments'],
                'tax_inclusive' => (bool) ($extras['tax_inclusive'] ?? false),
                'tax_pricing' => (string) ($extras['tax_pricing'] ?? 'Exclusive'),
                'business_date' => $row->business_date,
                'closed_at' => $row->closed_at,
                'voided_at' => $row->voided_at,
                'order_status' => (string) $row->order_status,
                'payment_methods' => $paymentByOrder[(int) $row->order_id] ?? '—',
                'waiter' => $row->waiter_name ?? '—',
                'cashier' => $row->cashier_name !== null && trim((string) $row->cashier_name) !== ''
                    ? trim((string) $row->cashier_name)
                    : '—',
            ];
        })->values();

        return response()->json([
            'summary' => [
                'lines_count' => (int) ($agg->lines_count ?? 0),
                'active_lines_count' => (int) ($agg->active_lines_count ?? 0),
                'cancelled_lines_count' => (int) ($agg->cancelled_lines_count ?? 0),
                'qty_total' => (float) ($agg->qty_total ?? 0),
                'revenue_total' => (float) ($agg->revenue_total ?? 0),
                'bills_count' => (int) ($agg->bills_count ?? 0),
            ],
            'vat_filing' => $vatFiling,
            'data' => $data,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'total' => $paginated->total(),
                'group_by' => $groupBy,
            ],
        ]);
    }

    /**
     * Export liquor (state VAT) line register as CSV, Excel (.xlsx), or PDF.
     *
     * @queryParam format csv|xlsx|pdf (default csv)
     * @queryParam group_by line|item|invoice (default line)
     */
    public function liquorSalesExport(Request $request)
    {
        $this->checkPermission('report-sales');

        $restaurantId = $request->query('restaurant_id');
        $from = $request->query('from') ?? now()->toDateString();
        $to = $request->query('to') ?? now()->toDateString();
        $format = strtolower((string) $request->query('format', 'csv'));
        if (! in_array($format, ['csv', 'xlsx', 'pdf'], true)) {
            $format = 'csv';
        }

        if (! $restaurantId || ! $this->userCanAccessRestaurant((int) $restaurantId)) {
            abort(403, 'Unauthorized access to this outlet.');
        }

        $this->authorizeRestaurantId((int) $restaurantId);

        $groupBy = strtolower((string) $request->query('group_by', 'line'));
        if (! in_array($groupBy, ['line', 'item', 'invoice'], true)) {
            $groupBy = 'line';
        }

        $export = $this->buildLiquorSalesExportData((int) $restaurantId, $from, $to, $groupBy);

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('reports.liquor_vat_sales', [
                'restaurant' => $export['restaurant'],
                'from' => $from,
                'to' => $to,
                'headers' => $export['headers'],
                'rows' => $export['rows'],
            ])->setPaper('a4', 'landscape');

            return $pdf->download("liquor_vat_sales_{$groupBy}_{$from}_to_{$to}.pdf");
        }

        if ($format === 'xlsx') {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray(array_merge([$export['headers']], $export['rows']), null, 'A1');
            $fileName = "liquor_vat_sales_{$groupBy}_{$from}_to_{$to}.xlsx";

            return response()->streamDownload(function () use ($spreadsheet) {
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
            }, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'must-revalidate',
                'Pragma' => 'public',
            ]);
        }

        $fileName = "liquor_vat_sales_{$groupBy}_{$from}_to_{$to}.csv";
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$fileName}",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($export) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $export['headers']);
            foreach ($export['rows'] as $row) {
                fputcsv($file, $row);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Base query: liquor VAT lines on settled/void bills in date window.
     *
     * Includes lines with tax_regime = vat_liquor, and lines where regime was never
     * backfilled but the menu item (or a combo component) uses inventory_taxes.type = vat.
     * Active and cancelled (voided) lines are included for a full audit trail.
     */
    private function liquorSalesLinesBaseQuery(int $restaurantId, string $from, string $to)
    {
        return DB::table('pos_order_items')
            ->join('pos_orders', 'pos_order_items.order_id', '=', 'pos_orders.id')
            ->leftJoin('menu_items', 'pos_order_items.menu_item_id', '=', 'menu_items.id')
            ->leftJoin('menu_item_variants', 'pos_order_items.menu_item_variant_id', '=', 'menu_item_variants.id')
            ->leftJoin('combos', 'pos_order_items.combo_id', '=', 'combos.id')
            ->leftJoin('users', 'pos_orders.waiter_id', '=', 'users.id')
            ->whereIn('pos_order_items.status', ['active', 'cancelled'])
            ->where('pos_orders.restaurant_id', $restaurantId)
            ->whereIn('pos_orders.status', ['paid', 'refunded', 'void'])
            ->where(function ($w) {
                $w->where('pos_order_items.tax_regime', 'vat_liquor')
                    ->orWhere(function ($w2) {
                        $w2->where(function ($w3) {
                            $w3->whereNull('pos_order_items.tax_regime')
                                ->orWhere('pos_order_items.tax_regime', '');
                        })
                            ->where(function ($w4) {
                                $w4->whereExists(function ($q) {
                                    $q->select(DB::raw(1))
                                        ->from('menu_items as mi')
                                        ->join('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                                        ->whereColumn('mi.id', 'pos_order_items.menu_item_id')
                                        ->whereNotNull('pos_order_items.menu_item_id')
                                        ->whereRaw('LOWER(it.type) = ?', ['vat']);
                                })
                                    ->orWhereExists(function ($q) {
                                        $q->select(DB::raw(1))
                                            ->from('combo_items as ci')
                                            ->join('menu_items as mi', 'ci.menu_item_id', '=', 'mi.id')
                                            ->join('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                                            ->whereColumn('ci.combo_id', 'pos_order_items.combo_id')
                                            ->whereNotNull('pos_order_items.combo_id')
                                            ->whereRaw('LOWER(it.type) = ?', ['vat']);
                                    });
                            });
                    });
            })
            ->where(function ($q) use ($from, $to) {
                $q->where(function ($q2) use ($from, $to) {
                    $q2->whereDate('pos_orders.business_date', '>=', $from)
                        ->whereDate('pos_orders.business_date', '<=', $to);
                })->orWhere(function ($q3) use ($from, $to) {
                    $q3->where('pos_orders.status', 'void')
                        ->whereDate('pos_orders.voided_at', '>=', $from)
                        ->whereDate('pos_orders.voided_at', '<=', $to);
                });
            });
    }

    /**
     * Food / F&B (GST) line register: lines taxed as GST, excluding liquor VAT lines.
     */
    public function foodSalesReport(Request $request)
    {
        $this->checkPermission('report-sales');

        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'restaurant_id' => 'nullable|integer|exists:restaurant_masters,id',
            'page' => 'nullable|integer|min:1',
            'group_by' => 'nullable|string|in:line,item,invoice',
        ]);

        $user = auth()->user();
        $from = $validated['from'];
        $to = $validated['to'];
        $page = (int) ($validated['page'] ?? 1);
        $restaurantId = $validated['restaurant_id'] ?? null;
        $groupBy = (string) ($validated['group_by'] ?? 'line');
        if (! in_array($groupBy, ['line', 'item', 'invoice'], true)) {
            $groupBy = 'line';
        }

        if ($restaurantId) {
            $this->authorizeRestaurantId((int) $restaurantId);
        }

        $allowedOutletIds = null;
        if (! $restaurantId && $user && ! $user->hasRole('Admin') && ! $user->hasRole('Super Admin')) {
            $assigned = $user->restaurants()->pluck('restaurant_masters.id')->map(fn ($id) => (int) $id)->all();
            if (count($assigned) > 0) {
                $allowedOutletIds = $assigned;
            } else {
                $deptIds = $user->departments()->pluck('departments.id')->map(fn ($id) => (int) $id)->all();
                if (count($deptIds) > 0) {
                    $allowedOutletIds = RestaurantMaster::where('is_active', true)
                        ->where(function ($q) use ($deptIds) {
                            $q->whereIn('department_id', $deptIds)->orWhereNull('department_id');
                        })
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                } else {
                    $allowedOutletIds = [];
                }
            }
        }

        if (! $restaurantId) {
            if ($user && ($user->hasRole('Admin') || $user->hasRole('Super Admin'))) {
                $restaurantId = RestaurantMaster::where('is_active', true)->first()?->id;
            } elseif (is_array($allowedOutletIds) && count($allowedOutletIds) > 0) {
                $restaurantId = $allowedOutletIds[0];
            }
        }

        if (! $restaurantId) {
            $emptyFiling = [
                'lines_count' => 0,
                'qty_total' => 0.0,
                'revenue_total' => 0.0,
                'bills_count' => 0,
                'note' => 'Active lines on paid or refunded orders only; void bills and cancelled lines excluded. Not a government return file; refunds and adjustments per CA.',
            ];

            return response()->json([
                'summary' => [
                    'lines_count' => 0,
                    'active_lines_count' => 0,
                    'cancelled_lines_count' => 0,
                    'qty_total' => 0.0,
                    'revenue_total' => 0.0,
                    'bills_count' => 0,
                ],
                'gst_filing' => $emptyFiling,
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'total' => 0,
                    'group_by' => $groupBy,
                ],
            ]);
        }

        $rid = (int) $restaurantId;
        $base = $this->foodSalesLinesBaseQuery($rid, $from, $to);

        $agg = (clone $base)->selectRaw(
            'COUNT(pos_order_items.id) as lines_count,
            SUM(CASE WHEN pos_order_items.status = \'active\' THEN 1 ELSE 0 END) as active_lines_count,
            SUM(CASE WHEN pos_order_items.status = \'cancelled\' THEN 1 ELSE 0 END) as cancelled_lines_count,
            COALESCE(SUM(CASE WHEN pos_order_items.status = \'active\' THEN pos_order_items.quantity ELSE 0 END), 0) as qty_total,
            COALESCE(SUM(CASE WHEN pos_order_items.status = \'active\' THEN pos_order_items.line_total ELSE 0 END), 0) as revenue_total,
            COUNT(DISTINCT pos_orders.id) as bills_count'
        )->first();

        $gstFiling = $this->registerStatutoryFilingSummaryFromBase($base);

        if ($groupBy === 'item' || $groupBy === 'invoice') {
            $perPage = 50;
            $allRows = $this->foodSalesBuildAggregatedRows($rid, $from, $to, $groupBy);
            $total = count($allRows);
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = min(max(1, $page), $lastPage);
            $slice = array_slice($allRows, ($page - 1) * $perPage, $perPage);

            return response()->json([
                'summary' => [
                    'lines_count' => (int) ($agg->lines_count ?? 0),
                    'active_lines_count' => (int) ($agg->active_lines_count ?? 0),
                    'cancelled_lines_count' => (int) ($agg->cancelled_lines_count ?? 0),
                    'qty_total' => (float) ($agg->qty_total ?? 0),
                    'revenue_total' => (float) ($agg->revenue_total ?? 0),
                    'bills_count' => (int) ($agg->bills_count ?? 0),
                ],
                'gst_filing' => $gstFiling,
                'data' => $slice,
                'meta' => [
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'total' => $total,
                    'group_by' => $groupBy,
                ],
            ]);
        }

        $paginated = (clone $base)
            ->select([
                'pos_order_items.id as line_id',
                'pos_order_items.order_id',
                'pos_order_items.quantity',
                'pos_order_items.unit_price',
                'pos_order_items.tax_rate',
                'pos_order_items.line_total',
                'pos_order_items.combo_id',
                'pos_order_items.status as line_status',
                'menu_items.name as menu_name',
                'menu_item_variants.size_label',
                'combos.name as combo_name',
                'pos_orders.business_date',
                'pos_orders.closed_at',
                'pos_orders.voided_at',
                'pos_orders.status as order_status',
                'pos_orders.customer_name',
                'pos_orders.customer_gstin',
                'users.name as waiter_name',
                DB::raw('(SELECT u.name FROM pos_payments pp INNER JOIN users u ON u.id = pp.received_by WHERE pp.order_id = pos_orders.id AND pp.received_by IS NOT NULL ORDER BY COALESCE(pp.paid_at, pp.created_at) DESC, pp.id DESC LIMIT 1) as cashier_name'),
            ])
            ->orderByDesc('pos_order_items.id')
            ->paginate(50, ['*'], 'page', $page);

        $lineIds = collect($paginated->items())->pluck('line_id')->map(fn ($id) => (int) $id)->all();
        $itemsById = PosOrderItem::whereIn('id', $lineIds)
            ->with([
                'menuItem.tax',
                'combo.menuItems.tax',
                'order.refunds',
                'order.items' => fn ($q) => $q->where('status', 'active'),
                'order.items.menuItem.tax',
                'order.items.combo.menuItems.tax',
            ])
            ->get()
            ->keyBy('id');

        $orderIdsForPayment = collect($paginated->items())->pluck('order_id')->unique()->map(fn ($id) => (int) $id)->values()->all();
        $paymentByOrder = $this->foodSalesPaymentMethodsForOrderIds($orderIdsForPayment);

        $data = collect($paginated->items())->map(function ($row) use ($itemsById, $paymentByOrder) {
            $comboId = $row->combo_id ?? null;
            if ($comboId) {
                $display = 'Combo: '.((string) ($row->combo_name ?? '') !== '' ? $row->combo_name : '—');
            } else {
                $name = (string) ($row->menu_name ?? '—');
                $variant = trim((string) ($row->size_label ?? ''));
                $display = $variant !== '' ? $name.' — '.$variant : $name;
            }

            $item = $itemsById->get((int) $row->line_id);
            $extras = ($item && $item->order)
                ? $this->computeFoodLineRegisterFields($item, $item->order)
                : [
                    'line_gross' => 0.0,
                    'line_discount' => 0.0,
                    'line_after_discount' => 0.0,
                    'net_taxable' => 0.0,
                    'tax_amount' => 0.0,
                    'cgst' => 0.0,
                    'sgst' => 0.0,
                    'igst' => 0.0,
                    'refund_alloc' => 0.0,
                    'service_charge_alloc' => 0.0,
                    'tip_alloc' => 0.0,
                    'delivery_alloc' => 0.0,
                    'rounding_alloc' => 0.0,
                    'sheet_adjustments' => 0.0,
                    'tax_inclusive' => false,
                    'tax_pricing' => 'Exclusive',
                    'line_status' => (string) ($row->line_status ?? 'active'),
                ];

            return [
                'row_kind' => 'line',
                'row_id' => 'line:'.(int) $row->line_id,
                'lines_count' => 1,
                'item_key' => null,
                'line_id' => (int) $row->line_id,
                'order_id' => (int) $row->order_id,
                'line_status' => (string) ($extras['line_status'] ?? $row->line_status ?? 'active'),
                'customer_name' => $row->customer_name !== null && trim((string) $row->customer_name) !== ''
                    ? trim((string) $row->customer_name)
                    : null,
                'customer_gstin' => $row->customer_gstin !== null && trim((string) $row->customer_gstin) !== ''
                    ? trim((string) $row->customer_gstin)
                    : null,
                'display_name' => $display,
                'quantity' => (float) $row->quantity,
                'unit_price' => (float) $row->unit_price,
                'tax_rate' => (float) $row->tax_rate,
                'tax_rate_mixed' => false,
                'line_total' => (float) $row->line_total,
                'line_gross' => $extras['line_gross'],
                'line_discount' => $extras['line_discount'],
                'line_after_discount' => $extras['line_after_discount'],
                'net_taxable' => $extras['net_taxable'],
                'tax_amount' => $extras['tax_amount'],
                'cgst' => $extras['cgst'],
                'sgst' => $extras['sgst'],
                'igst' => $extras['igst'],
                'refund_alloc' => $extras['refund_alloc'],
                'service_charge_alloc' => $extras['service_charge_alloc'],
                'tip_alloc' => $extras['tip_alloc'],
                'delivery_alloc' => $extras['delivery_alloc'],
                'rounding_alloc' => $extras['rounding_alloc'],
                'sheet_adjustments' => $extras['sheet_adjustments'],
                'tax_inclusive' => (bool) ($extras['tax_inclusive'] ?? false),
                'tax_pricing' => (string) ($extras['tax_pricing'] ?? 'Exclusive'),
                'business_date' => $row->business_date,
                'closed_at' => $row->closed_at,
                'voided_at' => $row->voided_at,
                'order_status' => (string) $row->order_status,
                'payment_methods' => $paymentByOrder[(int) $row->order_id] ?? '—',
                'waiter' => $row->waiter_name ?? '—',
                'cashier' => $row->cashier_name !== null && trim((string) $row->cashier_name) !== ''
                    ? trim((string) $row->cashier_name)
                    : '—',
            ];
        })->values();

        return response()->json([
            'summary' => [
                'lines_count' => (int) ($agg->lines_count ?? 0),
                'active_lines_count' => (int) ($agg->active_lines_count ?? 0),
                'cancelled_lines_count' => (int) ($agg->cancelled_lines_count ?? 0),
                'qty_total' => (float) ($agg->qty_total ?? 0),
                'revenue_total' => (float) ($agg->revenue_total ?? 0),
                'bills_count' => (int) ($agg->bills_count ?? 0),
            ],
            'gst_filing' => $gstFiling,
            'data' => $data,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'total' => $paginated->total(),
                'group_by' => $groupBy,
            ],
        ]);
    }

    /**
     * Export food / GST line register as CSV, Excel (.xlsx), or PDF.
     *
     * @queryParam format csv|xlsx|pdf (default csv)
     */
    public function foodSalesExport(Request $request)
    {
        $this->checkPermission('report-sales');

        $restaurantId = $request->query('restaurant_id');
        $from = $request->query('from') ?? now()->toDateString();
        $to = $request->query('to') ?? now()->toDateString();
        $format = strtolower((string) $request->query('format', 'csv'));
        if (! in_array($format, ['csv', 'xlsx', 'pdf'], true)) {
            $format = 'csv';
        }

        if (! $restaurantId || ! $this->userCanAccessRestaurant((int) $restaurantId)) {
            abort(403, 'Unauthorized access to this outlet.');
        }

        $this->authorizeRestaurantId((int) $restaurantId);

        $groupBy = strtolower((string) $request->query('group_by', 'line'));
        if (! in_array($groupBy, ['line', 'item', 'invoice'], true)) {
            $groupBy = 'line';
        }

        $export = $this->buildFoodSalesExportData((int) $restaurantId, $from, $to, $groupBy);

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('reports.food_gst_sales', [
                'restaurant' => $export['restaurant'],
                'from' => $from,
                'to' => $to,
                'headers' => $export['headers'],
                'rows' => $export['rows'],
            ])->setPaper('a4', 'landscape');

            return $pdf->download("food_gst_sales_{$from}_to_{$to}.pdf");
        }

        if ($format === 'xlsx') {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray(array_merge([$export['headers']], $export['rows']), null, 'A1');
            $fileName = "food_gst_sales_{$from}_to_{$to}.xlsx";

            return response()->streamDownload(function () use ($spreadsheet) {
                $writer = new Xlsx($spreadsheet);
                $writer->save('php://output');
            }, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'must-revalidate',
                'Pragma' => 'public',
            ]);
        }

        $fileName = "food_gst_sales_{$from}_to_{$to}.csv";
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$fileName}",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($export) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $export['headers']);
            foreach ($export['rows'] as $row) {
                fputcsv($file, $row);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<int, mixed>>, restaurant: RestaurantMaster|null}
     */
    private function buildFoodSalesExportData(int $restaurantId, string $from, string $to, string $groupBy = 'line'): array
    {
        if ($groupBy === 'item') {
            $agg = $this->foodSalesBuildAggregatedRows($restaurantId, $from, $to, 'item');
            $headers = [
                'Item', 'GST lines', 'Qty', 'Avg unit', 'Tax %', 'Tax type',
                'Gross (Before Bill Discount)',
                'Line discount', 'After discount', 'Net taxable', 'Tax', 'CGST', 'SGST', 'IGST',
                'Refund (alloc)', 'Svc chg', 'Tip', 'Delivery', 'Rounding', 'POS sheet adj', 'Payment',
            ];
            $outRows = [];
            foreach ($agg as $r) {
                $taxPct = ! empty($r['tax_rate_mixed']) ? 'Mixed' : (float) $r['tax_rate'];
                $outRows[] = [
                    $r['display_name'],
                    $r['lines_count'],
                    $r['quantity'],
                    $r['unit_price'],
                    $taxPct,
                    $r['tax_pricing'],
                    $r['line_gross'],
                    $r['line_discount'],
                    $r['line_after_discount'],
                    $r['net_taxable'],
                    $r['tax_amount'],
                    $r['cgst'],
                    $r['sgst'],
                    $r['igst'],
                    $r['refund_alloc'],
                    $r['service_charge_alloc'],
                    $r['tip_alloc'],
                    $r['delivery_alloc'],
                    $r['rounding_alloc'],
                    $r['sheet_adjustments'],
                    '—',
                ];
            }

            return [
                'headers' => $headers,
                'rows' => $outRows,
                'restaurant' => RestaurantMaster::find($restaurantId),
            ];
        }

        if ($groupBy === 'invoice') {
            $agg = $this->foodSalesBuildAggregatedRows($restaurantId, $from, $to, 'invoice');
            $headers = [
                'Bill #', 'Customer', 'GSTIN', 'Business date', 'GST lines', 'Qty', 'Avg unit', 'Tax %', 'Tax type',
                'Gross (Before Bill Discount)',
                'Line discount', 'After discount', 'Net taxable', 'Tax', 'CGST', 'SGST', 'IGST',
                'Refund (alloc)', 'Svc chg', 'Tip', 'Delivery', 'Rounding', 'POS sheet adj',
                'Payment', 'Order status', 'Closed / voided',
            ];
            $outRows = [];
            foreach ($agg as $r) {
                $taxPct = ! empty($r['tax_rate_mixed']) ? 'Mixed' : (float) $r['tax_rate'];
                $ts = $r['order_status'] === 'void' ? $r['voided_at'] : $r['closed_at'];
                $outRows[] = [
                    $r['order_id'],
                    $r['customer_name'] ?? '',
                    $r['customer_gstin'] ?? '',
                    $r['business_date'],
                    $r['lines_count'],
                    $r['quantity'],
                    $r['unit_price'],
                    $taxPct,
                    $r['tax_pricing'],
                    $r['line_gross'],
                    $r['line_discount'],
                    $r['line_after_discount'],
                    $r['net_taxable'],
                    $r['tax_amount'],
                    $r['cgst'],
                    $r['sgst'],
                    $r['igst'],
                    $r['refund_alloc'],
                    $r['service_charge_alloc'],
                    $r['tip_alloc'],
                    $r['delivery_alloc'],
                    $r['rounding_alloc'],
                    $r['sheet_adjustments'],
                    $r['payment_methods'] ?? '—',
                    $r['order_status'],
                    $ts,
                ];
            }

            return [
                'headers' => $headers,
                'rows' => $outRows,
                'restaurant' => RestaurantMaster::find($restaurantId),
            ];
        }

        $headers = [
            'Line ID', 'Line status', 'Bill #', 'Customer', 'GSTIN', 'Business date', 'Item', 'Qty', 'Unit price', 'Tax %', 'Tax type',
            'Gross (Before Bill Discount)',
            'Line discount', 'After discount', 'Net taxable', 'Tax', 'CGST', 'SGST', 'IGST',
            'Refund (alloc)', 'Svc chg', 'Tip', 'Delivery', 'Rounding', 'POS sheet adj',
            'Payment', 'Order status', 'Closed / voided',
        ];

        $base = $this->foodSalesLinesBaseQuery($restaurantId, $from, $to);
        $rows = (clone $base)
            ->select([
                'pos_order_items.id as line_id',
                'pos_order_items.status as line_status',
                'pos_order_items.order_id',
                'pos_order_items.quantity',
                'pos_order_items.unit_price',
                'pos_order_items.tax_rate',
                'pos_order_items.line_total',
                'pos_order_items.combo_id',
                'menu_items.name as menu_name',
                'menu_item_variants.size_label',
                'combos.name as combo_name',
                'pos_orders.business_date',
                'pos_orders.closed_at',
                'pos_orders.voided_at',
                'pos_orders.status as order_status',
                'pos_orders.customer_name',
                'pos_orders.customer_gstin',
            ])
            ->orderByDesc('pos_order_items.id')
            ->get();

        $lineIds = collect($rows)->pluck('line_id')->map(fn ($id) => (int) $id)->all();
        $itemsById = PosOrderItem::whereIn('id', $lineIds)
            ->with([
                'menuItem.tax',
                'combo.menuItems.tax',
                'order.refunds',
                'order.items' => fn ($q) => $q->where('status', 'active'),
                'order.items.menuItem.tax',
                'order.items.combo.menuItems.tax',
            ])
            ->get()
            ->keyBy('id');

        $paymentByOrder = $this->foodSalesPaymentMethodsForOrderIds(
            collect($rows)->pluck('order_id')->unique()->map(fn ($id) => (int) $id)->values()->all()
        );

        $outRows = [];
        foreach ($rows as $row) {
            $comboId = $row->combo_id ?? null;
            if ($comboId) {
                $display = 'Combo: '.((string) ($row->combo_name ?? '') !== '' ? $row->combo_name : '—');
            } else {
                $name = (string) ($row->menu_name ?? '—');
                $variant = trim((string) ($row->size_label ?? ''));
                $display = $variant !== '' ? $name.' — '.$variant : $name;
            }
            $ts = $row->order_status === 'void' ? $row->voided_at : $row->closed_at;
            $item = $itemsById->get((int) $row->line_id);
            $ex = ($item && $item->order)
                ? $this->computeFoodLineRegisterFields($item, $item->order)
                : [
                    'line_gross' => 0.0,
                    'line_discount' => 0.0,
                    'line_after_discount' => 0.0,
                    'net_taxable' => 0.0,
                    'tax_amount' => 0.0,
                    'cgst' => 0.0,
                    'sgst' => 0.0,
                    'igst' => 0.0,
                    'refund_alloc' => 0.0,
                    'service_charge_alloc' => 0.0,
                    'tip_alloc' => 0.0,
                    'delivery_alloc' => 0.0,
                    'rounding_alloc' => 0.0,
                    'sheet_adjustments' => 0.0,
                    'tax_inclusive' => false,
                    'tax_pricing' => 'Exclusive',
                    'line_status' => (string) ($row->line_status ?? 'active'),
                ];
            $cust = $row->customer_name !== null && trim((string) $row->customer_name) !== ''
                ? trim((string) $row->customer_name)
                : '';
            $gstin = $row->customer_gstin !== null && trim((string) $row->customer_gstin) !== ''
                ? trim((string) $row->customer_gstin)
                : '';

            $outRows[] = [
                $row->line_id,
                $ex['line_status'] ?? ($row->line_status ?? 'active'),
                $row->order_id,
                $cust,
                $gstin,
                $row->business_date,
                $display,
                $row->quantity,
                $row->unit_price,
                $row->tax_rate,
                $ex['tax_pricing'],
                $ex['line_gross'],
                $ex['line_discount'],
                $ex['line_after_discount'],
                $ex['net_taxable'],
                $ex['tax_amount'],
                $ex['cgst'],
                $ex['sgst'],
                $ex['igst'],
                $ex['refund_alloc'],
                $ex['service_charge_alloc'],
                $ex['tip_alloc'],
                $ex['delivery_alloc'],
                $ex['rounding_alloc'],
                $ex['sheet_adjustments'],
                $paymentByOrder[(int) $row->order_id] ?? '—',
                $row->order_status,
                $ts,
            ];
        }

        return [
            'headers' => $headers,
            'rows' => $outRows,
            'restaurant' => RestaurantMaster::find($restaurantId),
        ];
    }

    /**
     * Liquor (state VAT) export rows — same shape as food/GST export, VAT labeling.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, mixed>>, restaurant: RestaurantMaster|null}
     */
    private function buildLiquorSalesExportData(int $restaurantId, string $from, string $to, string $groupBy = 'line'): array
    {
        if ($groupBy === 'item') {
            $agg = $this->liquorSalesBuildAggregatedRows($restaurantId, $from, $to, 'item');
            $headers = [
                'Item', 'VAT lines', 'Qty', 'Avg unit', 'Tax %', 'Tax type',
                'Gross (Before Bill Discount)',
                'Line discount', 'After discount', 'Net taxable', 'VAT',
                'Refund (alloc)', 'Svc chg', 'Tip', 'Delivery', 'Rounding', 'POS sheet adj', 'Payment',
            ];
            $outRows = [];
            foreach ($agg as $r) {
                $taxPct = ! empty($r['tax_rate_mixed']) ? 'Mixed' : (float) $r['tax_rate'];
                $outRows[] = [
                    $r['display_name'],
                    $r['lines_count'],
                    $r['quantity'],
                    $r['unit_price'],
                    $taxPct,
                    $r['tax_pricing'],
                    $r['line_gross'],
                    $r['line_discount'],
                    $r['line_after_discount'],
                    $r['net_taxable'],
                    $r['tax_amount'],
                    $r['refund_alloc'],
                    $r['service_charge_alloc'],
                    $r['tip_alloc'],
                    $r['delivery_alloc'],
                    $r['rounding_alloc'],
                    $r['sheet_adjustments'],
                    '—',
                ];
            }

            return [
                'headers' => $headers,
                'rows' => $outRows,
                'restaurant' => RestaurantMaster::find($restaurantId),
            ];
        }

        if ($groupBy === 'invoice') {
            $agg = $this->liquorSalesBuildAggregatedRows($restaurantId, $from, $to, 'invoice');
            $headers = [
                'Bill #', 'Customer', 'Customer tax ID', 'Business date', 'VAT lines', 'Qty', 'Avg unit', 'Tax %', 'Tax type',
                'Gross (Before Bill Discount)',
                'Line discount', 'After discount', 'Net taxable', 'VAT',
                'Refund (alloc)', 'Svc chg', 'Tip', 'Delivery', 'Rounding', 'POS sheet adj',
                'Payment', 'Order status', 'Closed / voided',
            ];
            $outRows = [];
            foreach ($agg as $r) {
                $taxPct = ! empty($r['tax_rate_mixed']) ? 'Mixed' : (float) $r['tax_rate'];
                $ts = $r['order_status'] === 'void' ? $r['voided_at'] : $r['closed_at'];
                $outRows[] = [
                    $r['order_id'],
                    $r['customer_name'] ?? '',
                    $r['customer_gstin'] ?? '',
                    $r['business_date'],
                    $r['lines_count'],
                    $r['quantity'],
                    $r['unit_price'],
                    $taxPct,
                    $r['tax_pricing'],
                    $r['line_gross'],
                    $r['line_discount'],
                    $r['line_after_discount'],
                    $r['net_taxable'],
                    $r['tax_amount'],
                    $r['refund_alloc'],
                    $r['service_charge_alloc'],
                    $r['tip_alloc'],
                    $r['delivery_alloc'],
                    $r['rounding_alloc'],
                    $r['sheet_adjustments'],
                    $r['payment_methods'] ?? '—',
                    $r['order_status'],
                    $ts,
                ];
            }

            return [
                'headers' => $headers,
                'rows' => $outRows,
                'restaurant' => RestaurantMaster::find($restaurantId),
            ];
        }

        $headers = [
            'Line ID', 'Line status', 'Bill #', 'Customer', 'Customer tax ID', 'Business date', 'Item', 'Qty', 'Unit price', 'Tax %', 'Tax type',
            'Gross (Before Bill Discount)',
            'Line discount', 'After discount', 'Net taxable', 'VAT',
            'Refund (alloc)', 'Svc chg', 'Tip', 'Delivery', 'Rounding', 'POS sheet adj',
            'Payment', 'Order status', 'Closed / voided',
        ];

        $base = $this->liquorSalesLinesBaseQuery($restaurantId, $from, $to);
        $rows = (clone $base)
            ->select([
                'pos_order_items.id as line_id',
                'pos_order_items.status as line_status',
                'pos_order_items.order_id',
                'pos_order_items.quantity',
                'pos_order_items.unit_price',
                'pos_order_items.tax_rate',
                'pos_order_items.line_total',
                'pos_order_items.combo_id',
                'menu_items.name as menu_name',
                'menu_item_variants.size_label',
                'combos.name as combo_name',
                'pos_orders.business_date',
                'pos_orders.closed_at',
                'pos_orders.voided_at',
                'pos_orders.status as order_status',
                'pos_orders.customer_name',
                'pos_orders.customer_gstin',
            ])
            ->orderByDesc('pos_order_items.id')
            ->get();

        $lineIds = collect($rows)->pluck('line_id')->map(fn ($id) => (int) $id)->all();
        $itemsById = PosOrderItem::whereIn('id', $lineIds)
            ->with([
                'menuItem.tax',
                'combo.menuItems.tax',
                'order.refunds',
                'order.items' => fn ($q) => $q->where('status', 'active'),
                'order.items.menuItem.tax',
                'order.items.combo.menuItems.tax',
            ])
            ->get()
            ->keyBy('id');

        $paymentByOrder = $this->foodSalesPaymentMethodsForOrderIds(
            collect($rows)->pluck('order_id')->unique()->map(fn ($id) => (int) $id)->values()->all()
        );

        $outRows = [];
        foreach ($rows as $row) {
            $comboId = $row->combo_id ?? null;
            if ($comboId) {
                $display = 'Combo: '.((string) ($row->combo_name ?? '') !== '' ? $row->combo_name : '—');
            } else {
                $name = (string) ($row->menu_name ?? '—');
                $variant = trim((string) ($row->size_label ?? ''));
                $display = $variant !== '' ? $name.' — '.$variant : $name;
            }
            $ts = $row->order_status === 'void' ? $row->voided_at : $row->closed_at;
            $item = $itemsById->get((int) $row->line_id);
            $ex = ($item && $item->order)
                ? $this->computeFoodLineRegisterFields($item, $item->order)
                : [
                    'line_gross' => 0.0,
                    'line_discount' => 0.0,
                    'line_after_discount' => 0.0,
                    'net_taxable' => 0.0,
                    'tax_amount' => 0.0,
                    'cgst' => 0.0,
                    'sgst' => 0.0,
                    'igst' => 0.0,
                    'refund_alloc' => 0.0,
                    'service_charge_alloc' => 0.0,
                    'tip_alloc' => 0.0,
                    'delivery_alloc' => 0.0,
                    'rounding_alloc' => 0.0,
                    'sheet_adjustments' => 0.0,
                    'tax_inclusive' => false,
                    'tax_pricing' => 'Exclusive',
                    'line_status' => (string) ($row->line_status ?? 'active'),
                ];
            $cust = $row->customer_name !== null && trim((string) $row->customer_name) !== ''
                ? trim((string) $row->customer_name)
                : '';
            $gstin = $row->customer_gstin !== null && trim((string) $row->customer_gstin) !== ''
                ? trim((string) $row->customer_gstin)
                : '';

            $outRows[] = [
                $row->line_id,
                $ex['line_status'] ?? ($row->line_status ?? 'active'),
                $row->order_id,
                $cust,
                $gstin,
                $row->business_date,
                $display,
                $row->quantity,
                $row->unit_price,
                $row->tax_rate,
                $ex['tax_pricing'],
                $ex['line_gross'],
                $ex['line_discount'],
                $ex['line_after_discount'],
                $ex['net_taxable'],
                $ex['tax_amount'],
                $ex['refund_alloc'],
                $ex['service_charge_alloc'],
                $ex['tip_alloc'],
                $ex['delivery_alloc'],
                $ex['rounding_alloc'],
                $ex['sheet_adjustments'],
                $paymentByOrder[(int) $row->order_id] ?? '—',
                $row->order_status,
                $ts,
            ];
        }

        return [
            'headers' => $headers,
            'rows' => $outRows,
            'restaurant' => RestaurantMaster::find($restaurantId),
        ];
    }

    /**
     * Base query: GST (food / F&B) lines — not liquor VAT.
     * Active and cancelled (voided) lines are included for a full audit trail.
     */
    private function foodSalesLinesBaseQuery(int $restaurantId, string $from, string $to)
    {
        return DB::table('pos_order_items')
            ->join('pos_orders', 'pos_order_items.order_id', '=', 'pos_orders.id')
            ->leftJoin('menu_items', 'pos_order_items.menu_item_id', '=', 'menu_items.id')
            ->leftJoin('menu_item_variants', 'pos_order_items.menu_item_variant_id', '=', 'menu_item_variants.id')
            ->leftJoin('combos', 'pos_order_items.combo_id', '=', 'combos.id')
            ->leftJoin('users', 'pos_orders.waiter_id', '=', 'users.id')
            ->whereIn('pos_order_items.status', ['active', 'cancelled'])
            ->where('pos_orders.restaurant_id', $restaurantId)
            ->whereIn('pos_orders.status', ['paid', 'refunded', 'void'])
            ->where(function ($w) {
                $w->where('pos_order_items.tax_regime', 'gst')
                    ->orWhere(function ($w2) {
                        $w2->where(function ($w3) {
                            $w3->whereNull('pos_order_items.tax_regime')
                                ->orWhere('pos_order_items.tax_regime', '');
                        })
                            ->where(function ($w4) {
                                $w4->whereExists(function ($q) {
                                    $q->select(DB::raw(1))
                                        ->from('menu_items as mi')
                                        ->leftJoin('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                                        ->whereColumn('mi.id', 'pos_order_items.menu_item_id')
                                        ->whereNotNull('pos_order_items.menu_item_id')
                                        ->where(function ($q2) {
                                            $q2->whereNull('mi.tax_id')
                                                ->orWhereRaw('LOWER(COALESCE(it.type, ?)) <> ?', ['', 'vat']);
                                        });
                                })
                                    ->orWhere(function ($w5) {
                                        $w5->whereNotNull('pos_order_items.combo_id')
                                            ->whereNotExists(function ($q) {
                                                $q->select(DB::raw(1))
                                                    ->from('combo_items as ci')
                                                    ->join('menu_items as mi', 'ci.menu_item_id', '=', 'mi.id')
                                                    ->join('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                                                    ->whereColumn('ci.combo_id', 'pos_order_items.combo_id')
                                                    ->whereRaw('LOWER(it.type) = ?', ['vat']);
                                            });
                                    });
                            });
                    });
            })
            ->where(function ($q) use ($from, $to) {
                $q->where(function ($q2) use ($from, $to) {
                    $q2->whereDate('pos_orders.business_date', '>=', $from)
                        ->whereDate('pos_orders.business_date', '<=', $to);
                })->orWhere(function ($q3) use ($from, $to) {
                    $q3->where('pos_orders.status', 'void')
                        ->whereDate('pos_orders.voided_at', '>=', $from)
                        ->whereDate('pos_orders.voided_at', '<=', $to);
                });
            });
    }

    /**
     * Subset aligned with typical return/filing roll-ups: active lines only, on paid or refunded orders (void bills out).
     * The full register may still list void/cancelled rows for audit; those do not contribute here.
     * Not a GSTR-1 / statutory export; refunds and credit notes follow CA treatment.
     *
     * @param  \Illuminate\Database\Query\Builder  $base  foodSalesLinesBaseQuery or liquorSalesLinesBaseQuery
     * @return array{lines_count: int, qty_total: float, revenue_total: float, bills_count: int, note: string}
     */
    private function registerStatutoryFilingSummaryFromBase($base): array
    {
        $filing = (clone $base)
            ->whereIn('pos_orders.status', ['paid', 'refunded'])
            ->where('pos_order_items.status', 'active')
            ->selectRaw(
                'COUNT(pos_order_items.id) as lines_count,
                COALESCE(SUM(pos_order_items.quantity), 0) as qty_total,
                COALESCE(SUM(pos_order_items.line_total), 0) as revenue_total,
                COUNT(DISTINCT pos_orders.id) as bills_count'
            )->first();

        return [
            'lines_count' => (int) ($filing->lines_count ?? 0),
            'qty_total' => (float) ($filing->qty_total ?? 0),
            'revenue_total' => (float) ($filing->revenue_total ?? 0),
            'bills_count' => (int) ($filing->bills_count ?? 0),
            'note' => 'Active lines on paid or refunded orders only; void bills and cancelled lines excluded. Not a government return file; refunds and adjustments per CA.',
        ];
    }

    private function foodSalesItemKey(PosOrderItem $item): string
    {
        if ($item->combo_id) {
            return 'c:'.(int) $item->combo_id;
        }

        return 'm:'.(int) ($item->menu_item_id ?? 0).':'.(int) ($item->menu_item_variant_id ?? 0);
    }

    private function foodSalesItemDisplayName(PosOrderItem $item): string
    {
        if ($item->combo_id) {
            $item->loadMissing('combo');
            $cname = trim((string) ($item->combo?->name ?? ''));

            return 'Combo: '.($cname !== '' ? $cname : '—');
        }
        $item->loadMissing('menuItem', 'variant');
        $name = (string) ($item->menuItem?->name ?? '—');
        $variant = trim((string) ($item->variant?->size_label ?? ''));

        return $variant !== '' ? $name.' — '.$variant : $name;
    }

    /**
     * Latest payment receiver name per order (same rule as food sales line query).
     *
     * @param  array<int>  $orderIds
     * @return array<int, string>
     */
    private function foodSalesCashierNamesForOrderIds(array $orderIds): array
    {
        if (count($orderIds) === 0) {
            return [];
        }
        $rows = DB::table('pos_payments as pp')
            ->join('users as u', 'pp.received_by', '=', 'u.id')
            ->whereIn('pp.order_id', $orderIds)
            ->whereNotNull('pp.received_by')
            ->orderByDesc('pp.paid_at')
            ->orderByDesc('pp.id')
            ->select('pp.order_id', 'u.name as cashier_name')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            if (! isset($out[$r->order_id])) {
                $out[$r->order_id] = (string) $r->cashier_name;
            }
        }

        return $out;
    }

    /**
     * Distinct POS payment methods per order (order of first occurrence by payment id).
     *
     * @param  array<int>  $orderIds
     * @return array<int, string>
     */
    private function foodSalesPaymentMethodsForOrderIds(array $orderIds): array
    {
        if (count($orderIds) === 0) {
            return [];
        }
        $rows = DB::table('pos_payments')
            ->whereIn('order_id', $orderIds)
            ->orderBy('id')
            ->select('order_id', 'method')
            ->get();
        $labelsByOrder = [];
        foreach ($rows as $r) {
            $oid = (int) $r->order_id;
            $label = match ((string) $r->method) {
                'cash' => 'Cash',
                'card' => 'Card',
                'upi' => 'UPI',
                'room_charge' => 'Room',
                default => ucfirst(str_replace('_', ' ', (string) $r->method)),
            };
            if (! isset($labelsByOrder[$oid])) {
                $labelsByOrder[$oid] = [];
            }
            if (! in_array($label, $labelsByOrder[$oid], true)) {
                $labelsByOrder[$oid][] = $label;
            }
        }
        $out = [];
        foreach ($labelsByOrder as $oid => $labels) {
            $out[$oid] = implode(', ', $labels);
        }

        return $out;
    }

    private function foodSalesBuildAggregatedRows(int $restaurantId, string $from, string $to, string $groupBy): array
    {
        return $this->registerBuildAggregatedRowsFromBase(
            $this->foodSalesLinesBaseQuery($restaurantId, $from, $to),
            $groupBy
        );
    }

    /**
     * Item- or invoice-wise aggregates for liquor (state VAT) lines — same per-line math as the line register.
     */
    private function liquorSalesBuildAggregatedRows(int $restaurantId, string $from, string $to, string $groupBy): array
    {
        return $this->registerBuildAggregatedRowsFromBase(
            $this->liquorSalesLinesBaseQuery($restaurantId, $from, $to),
            $groupBy
        );
    }

    /**
     * Shared aggregation for food (GST) and liquor (state VAT) registers.
     *
     * @param  \Illuminate\Database\Query\Builder  $base
     * @return array<int, array<string, mixed>>
     */
    private function registerBuildAggregatedRowsFromBase($base, string $groupBy): array
    {
        $lineIds = (clone $base)->select('pos_order_items.id')->orderBy('pos_order_items.id')->pluck('pos_order_items.id')
            ->map(fn ($id) => (int) $id)
            ->values();
        if ($lineIds->isEmpty()) {
            return [];
        }

        $buckets = [];

        foreach ($lineIds->chunk(400) as $chunk) {
            $posItems = PosOrderItem::whereIn('id', $chunk->all())
                ->with([
                    'menuItem.tax',
                    'variant',
                    'combo.menuItems.tax',
                    'order.refunds',
                    'order.items' => fn ($q) => $q->where('status', 'active'),
                    'order.items.menuItem.tax',
                    'order.items.combo.menuItems.tax',
                    'order.waiter',
                ])
                ->get()
                ->keyBy('id');

            $orderIds = $posItems->pluck('order_id')->unique()->values()->all();
            $cashierByOrder = $this->foodSalesCashierNamesForOrderIds($orderIds);
            $paymentByOrder = $this->foodSalesPaymentMethodsForOrderIds($orderIds);

            foreach ($chunk as $lid) {
                $lid = (int) $lid;
                $poi = $posItems->get($lid);
                if (! $poi || ! $poi->order) {
                    continue;
                }
                $order = $poi->order;
                $ex = $this->computeFoodLineRegisterFields($poi, $order);
                $k = $groupBy === 'item' ? $this->foodSalesItemKey($poi) : 'o:'.$poi->order_id;

                if (! isset($buckets[$k])) {
                    if ($groupBy === 'item') {
                        $buckets[$k] = [
                            '_key' => $k,
                            'lines_count' => 0,
                            'quantity' => 0.0,
                            'line_gross' => 0.0,
                            'line_discount' => 0.0,
                            'line_after_discount' => 0.0,
                            'net_taxable' => 0.0,
                            'tax_amount' => 0.0,
                            'cgst' => 0.0,
                            'sgst' => 0.0,
                            'igst' => 0.0,
                            'refund_alloc' => 0.0,
                            'service_charge_alloc' => 0.0,
                            'tip_alloc' => 0.0,
                            'delivery_alloc' => 0.0,
                            'rounding_alloc' => 0.0,
                            'sheet_adjustments' => 0.0,
                            'tax_rates' => [],
                            'tax_inclusives' => [],
                            'tax_pricings' => [],
                            'display_name' => $this->foodSalesItemDisplayName($poi),
                        ];
                    } else {
                        $buckets[$k] = [
                            '_key' => $k,
                            'order_id' => (int) $order->id,
                            'lines_count' => 0,
                            'quantity' => 0.0,
                            'line_gross' => 0.0,
                            'line_discount' => 0.0,
                            'line_after_discount' => 0.0,
                            'net_taxable' => 0.0,
                            'tax_amount' => 0.0,
                            'cgst' => 0.0,
                            'sgst' => 0.0,
                            'igst' => 0.0,
                            'refund_alloc' => 0.0,
                            'service_charge_alloc' => 0.0,
                            'tip_alloc' => 0.0,
                            'delivery_alloc' => 0.0,
                            'rounding_alloc' => 0.0,
                            'sheet_adjustments' => 0.0,
                            'tax_rates' => [],
                            'tax_inclusives' => [],
                            'tax_pricings' => [],
                            'customer_name' => $order->customer_name !== null && trim((string) $order->customer_name) !== ''
                                ? trim((string) $order->customer_name)
                                : null,
                            'customer_gstin' => $order->customer_gstin !== null && trim((string) $order->customer_gstin) !== ''
                                ? trim((string) $order->customer_gstin)
                                : null,
                            'business_date' => $order->business_date?->format('Y-m-d'),
                            'closed_at' => $order->closed_at?->toDateTimeString(),
                            'voided_at' => $order->voided_at?->toDateTimeString(),
                            'order_status' => (string) $order->status,
                            'waiter' => $order->waiter?->name ?? '—',
                            'cashier' => $cashierByOrder[(int) $order->id] ?? '—',
                            'payment_methods' => $paymentByOrder[(int) $order->id] ?? '—',
                        ];
                    }
                }

                $b = & $buckets[$k];
                $b['lines_count']++;
                $b['quantity'] += (float) $poi->quantity;
                $b['line_gross'] += $ex['line_gross'];
                $b['line_discount'] += $ex['line_discount'];
                $b['line_after_discount'] += $ex['line_after_discount'];
                $b['net_taxable'] += $ex['net_taxable'];
                $b['tax_amount'] += $ex['tax_amount'];
                $b['cgst'] += $ex['cgst'];
                $b['sgst'] += $ex['sgst'];
                $b['igst'] += $ex['igst'];
                $b['refund_alloc'] += $ex['refund_alloc'];
                $b['service_charge_alloc'] += $ex['service_charge_alloc'];
                $b['tip_alloc'] += $ex['tip_alloc'];
                $b['delivery_alloc'] += $ex['delivery_alloc'];
                $b['rounding_alloc'] += $ex['rounding_alloc'];
                $b['sheet_adjustments'] += $ex['sheet_adjustments'];
                $b['tax_rates'][] = (float) $poi->tax_rate;
                $b['tax_inclusives'][] = (bool) ($ex['tax_inclusive'] ?? false);
                $b['tax_pricings'][] = (string) ($ex['tax_pricing'] ?? 'Exclusive');
                unset($b);
            }
        }

        $rows = [];
        foreach ($buckets as $b) {
            $qty = (float) $b['quantity'];
            $rates = array_unique(array_map(static fn ($x) => round((float) $x, 4), $b['tax_rates']));
            $tax_rate = count($rates) === 1 ? (float) reset($rates) : null;
            $tpU = array_unique($b['tax_pricings']);
            $tax_pricing = count($tpU) === 1 ? reset($tpU) : 'Mixed';
            $incU = array_unique(array_map(static fn ($x) => $x ? '1' : '0', $b['tax_inclusives']));
            $tax_inclusive = count($incU) === 1 && ($b['tax_inclusives'][0] ?? false) === true;

            $lineTotal = round($b['line_gross'], 2);
            $unit_price = $qty > 0 ? round($lineTotal / $qty, 2) : 0.0;

            if ($groupBy === 'item') {
                $key = (string) $b['_key'];
                $rows[] = [
                    'row_kind' => 'item',
                    'row_id' => 'item:'.$key,
                    'line_id' => 0,
                    'order_id' => 0,
                    'item_key' => $key,
                    'lines_count' => (int) $b['lines_count'],
                    'customer_name' => null,
                    'customer_gstin' => null,
                    'display_name' => (string) $b['display_name'],
                    'quantity' => round($qty, 4),
                    'unit_price' => $unit_price,
                    'tax_rate' => $tax_rate ?? 0.0,
                    'tax_rate_mixed' => $tax_rate === null,
                    'line_total' => $lineTotal,
                    'line_gross' => round($b['line_gross'], 2),
                    'line_discount' => round($b['line_discount'], 2),
                    'line_after_discount' => round($b['line_after_discount'], 2),
                    'net_taxable' => round($b['net_taxable'], 2),
                    'tax_amount' => round($b['tax_amount'], 2),
                    'vat_amount' => round($b['tax_amount'], 2),
                    'cgst' => round($b['cgst'], 2),
                    'sgst' => round($b['sgst'], 2),
                    'igst' => round($b['igst'], 2),
                    'refund_alloc' => round($b['refund_alloc'], 2),
                    'service_charge_alloc' => round($b['service_charge_alloc'], 2),
                    'tip_alloc' => round($b['tip_alloc'], 2),
                    'delivery_alloc' => round($b['delivery_alloc'], 2),
                    'rounding_alloc' => round($b['rounding_alloc'], 2),
                    'sheet_adjustments' => round($b['sheet_adjustments'], 2),
                    'tax_inclusive' => $tax_inclusive,
                    'tax_pricing' => $tax_pricing,
                    'business_date' => null,
                    'closed_at' => null,
                    'voided_at' => null,
                    'order_status' => '—',
                    'waiter' => '—',
                    'cashier' => '—',
                    'payment_methods' => '—',
                ];
            } else {
                $oid = (int) $b['order_id'];
                $lc = (int) $b['lines_count'];
                $rows[] = [
                    'row_kind' => 'invoice',
                    'row_id' => 'invoice:'.$oid,
                    'line_id' => 0,
                    'order_id' => $oid,
                    'item_key' => null,
                    'lines_count' => $lc,
                    'customer_name' => $b['customer_name'],
                    'customer_gstin' => $b['customer_gstin'],
                    'display_name' => '('.$lc.' lines)',
                    'quantity' => round($qty, 4),
                    'unit_price' => $unit_price,
                    'tax_rate' => $tax_rate ?? 0.0,
                    'tax_rate_mixed' => $tax_rate === null,
                    'line_total' => $lineTotal,
                    'line_gross' => round($b['line_gross'], 2),
                    'line_discount' => round($b['line_discount'], 2),
                    'line_after_discount' => round($b['line_after_discount'], 2),
                    'net_taxable' => round($b['net_taxable'], 2),
                    'tax_amount' => round($b['tax_amount'], 2),
                    'vat_amount' => round($b['tax_amount'], 2),
                    'cgst' => round($b['cgst'], 2),
                    'sgst' => round($b['sgst'], 2),
                    'igst' => round($b['igst'], 2),
                    'refund_alloc' => round($b['refund_alloc'], 2),
                    'service_charge_alloc' => round($b['service_charge_alloc'], 2),
                    'tip_alloc' => round($b['tip_alloc'], 2),
                    'delivery_alloc' => round($b['delivery_alloc'], 2),
                    'rounding_alloc' => round($b['rounding_alloc'], 2),
                    'sheet_adjustments' => round($b['sheet_adjustments'], 2),
                    'tax_inclusive' => $tax_inclusive,
                    'tax_pricing' => $tax_pricing,
                    'business_date' => $b['business_date'],
                    'closed_at' => $b['closed_at'],
                    'voided_at' => $b['voided_at'],
                    'order_status' => (string) $b['order_status'],
                    'waiter' => (string) $b['waiter'],
                    'cashier' => (string) $b['cashier'],
                    'payment_methods' => (string) ($b['payment_methods'] ?? '—'),
                ];
            }
        }

        if ($groupBy === 'item') {
            usort($rows, static function ($a, $b) {
                return strcmp((string) $a['display_name'], (string) $b['display_name']);
            });
        } else {
            usort($rows, static function ($a, $b) {
                $db = strcmp((string) $b['business_date'], (string) $a['business_date']);
                if ($db !== 0) {
                    return $db;
                }

                return (int) $b['order_id'] <=> (int) $a['order_id'];
            });
        }

        return $rows;
    }

    /**
     * Per-line GST breakdown and order-level allocations for food / GST line register.
     * Matches POS recalculate() / receipt math (discount ratio, CGST+SGST vs IGST).
     *
     * Line gross uses stored pos_order_items.line_total (final line before bill discount; modifiers in combo/line are already included).
     * Refunds: order-level refund total is allocated by line share — not item-level refund attribution.
     * POS sheet_adjustments: internal reconciliation only (svc+tip+delivery+rounding shares), not a statutory tax line.
     * Refunds: order-level only until item-linked refunds exist in pos_order_refunds (future: map cancels to lines).
     *
     * @return array<string, float|bool|string>
     */
    private function computeFoodLineRegisterFields(PosOrderItem $item, PosOrder $order): array
    {
        if (($item->status ?? '') === 'cancelled') {
            return $this->computeCancelledLineRegisterFields($item, $order);
        }

        $order->loadMissing([
            'refunds',
            'items' => fn ($q) => $q->where('status', 'active'),
            'items.menuItem.tax',
            'items.combo.menuItems.tax',
        ]);
        $item->loadMissing(['menuItem.tax', 'combo.menuItems.tax']);

        $activeItems = $order->items->where('status', 'active');
        $grossSubtotal = (float) $activeItems->sum(fn ($i) => floatval($i->line_total));

        $discountAmount = 0.0;
        if ($order->discount_type === 'percent') {
            $discountAmount = $grossSubtotal * (floatval($order->discount_value ?? 0) / 100);
        } elseif ($order->discount_type === 'flat') {
            $discountAmount = min((float) ($order->discount_value ?? 0), $grossSubtotal);
        }
        $discountRatio = $grossSubtotal > 0 ? ($discountAmount / $grossSubtotal) : 0.0;

        $lineGross = (float) $item->line_total;
        $lineDiscountShare = round($lineGross * $discountRatio, 2);
        $lineAfterDiscount = round($lineGross * (1 - $discountRatio), 2);

        $refundTotal = (float) $order->refunds->sum('amount');
        $share = $grossSubtotal > 0 ? ($lineGross / $grossSubtotal) : 0.0;
        $lineRefundAlloc = round($refundTotal * $share, 2);

        [$lineTax, $lineNet] = $this->posLineTaxAndNetTaxable($item, $order, $discountRatio);
        $kind = $this->posLineTaxSupplyKind($item, $order);

        $cgst = 0.0;
        $sgst = 0.0;
        $igst = 0.0;
        if ($order->tax_exempt || $order->is_complimentary) {
            $lineTax = 0.0;
            $lineNet = $lineAfterDiscount;
        }
        if (! $order->tax_exempt && ! $order->is_complimentary) {
            if ($kind === 'local_gst') {
                $cgst = round($lineTax / 2, 2);
                $sgst = round($lineTax - $cgst, 2);
            } elseif ($kind === 'igst') {
                $igst = round($lineTax, 2);
            }
        }

        $svc = round((float) ($order->service_charge_amount ?? 0) * $share, 2);
        $tip = round((float) ($order->tip_amount ?? 0) * $share, 2);
        $del = ($order->order_type === 'delivery')
            ? round((float) ($order->delivery_charge ?? 0) * $share, 2)
            : 0.0;
        $rnd = round((float) ($order->rounding_amount ?? 0) * $share, 2);
        $sheetAdjustments = round($svc + $tip + $del + $rnd, 2);

        $taxInclusive = $this->linePriceTaxInclusive($item, $order);

        return [
            'line_gross' => round($lineGross, 2),
            'line_discount' => $lineDiscountShare,
            'line_after_discount' => $lineAfterDiscount,
            'net_taxable' => round($lineNet, 2),
            'tax_amount' => round($lineTax, 2),
            'cgst' => $cgst,
            'sgst' => $sgst,
            'igst' => $igst,
            'refund_alloc' => $lineRefundAlloc,
            'service_charge_alloc' => $svc,
            'tip_alloc' => $tip,
            'delivery_alloc' => $del,
            'rounding_alloc' => $rnd,
            'sheet_adjustments' => $sheetAdjustments,
            'tax_inclusive' => $taxInclusive,
            'tax_pricing' => $taxInclusive ? 'Inclusive' : 'Exclusive',
            'line_status' => 'active',
        ];
    }

    /**
     * Register display for a cancelled (voided) line: no bill-discount share, no
     * refund/charge allocation — tax is derived from stored line_total and rate only.
     *
     * @return array<string, float|bool|string>
     */
    private function computeCancelledLineRegisterFields(PosOrderItem $item, PosOrder $order): array
    {
        $item->loadMissing(['menuItem.tax', 'combo.menuItems.tax']);

        $discountRatio = 0.0;
        $lineGross = (float) $item->line_total;
        $lineAfterDiscount = $lineGross;

        [$lineTax, $lineNet] = $this->posLineTaxAndNetTaxable($item, $order, $discountRatio);
        $kind = $this->posLineTaxSupplyKind($item, $order);

        $cgst = 0.0;
        $sgst = 0.0;
        $igst = 0.0;
        if ($order->tax_exempt || $order->is_complimentary) {
            $lineTax = 0.0;
            $lineNet = $lineAfterDiscount;
        }
        if (! $order->tax_exempt && ! $order->is_complimentary) {
            if ($kind === 'local_gst') {
                $cgst = round($lineTax / 2, 2);
                $sgst = round($lineTax - $cgst, 2);
            } elseif ($kind === 'igst') {
                $igst = round($lineTax, 2);
            }
        }

        $taxInclusive = $this->linePriceTaxInclusive($item, $order);

        return [
            'line_gross' => round($lineGross, 2),
            'line_discount' => 0.0,
            'line_after_discount' => round($lineAfterDiscount, 2),
            'net_taxable' => round($lineNet, 2),
            'tax_amount' => round($lineTax, 2),
            'cgst' => $cgst,
            'sgst' => $sgst,
            'igst' => $igst,
            'refund_alloc' => 0.0,
            'service_charge_alloc' => 0.0,
            'tip_alloc' => 0.0,
            'delivery_alloc' => 0.0,
            'rounding_alloc' => 0.0,
            'sheet_adjustments' => 0.0,
            'tax_inclusive' => $taxInclusive,
            'tax_pricing' => $taxInclusive ? 'Inclusive' : 'Exclusive',
            'line_status' => 'cancelled',
        ];
    }

    /**
     * POS refund register (audit / reconciliation): one row per refund.
     *
     * Query: from, to, restaurant_id (optional), page
     */
    public function refundsAdjustmentsReport(Request $request)
    {
        $this->checkPermission('report-refunds-adjustments');

        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'restaurant_id' => 'nullable|integer|exists:restaurant_masters,id',
            'page' => 'nullable|integer|min:1',
            'category' => 'nullable|string|in:all,kitchen,bar',
        ]);

        $user = auth()->user();
        $from = $validated['from'];
        $to = $validated['to'];
        $restaurantId = $validated['restaurant_id'] ?? null;
        $category = $validated['category'] ?? 'all';

        if ($restaurantId) {
            $this->authorizeRestaurantId((int) $restaurantId);
        }

        $allowedOutletIds = null;
        if (! $restaurantId && $user && ! $user->hasRole('Admin') && ! $user->hasRole('Super Admin')) {
            $assigned = $user->restaurants()->pluck('restaurant_masters.id')->map(fn ($id) => (int) $id)->all();
            if (count($assigned) > 0) {
                $allowedOutletIds = $assigned;
            } else {
                $deptIds = $user->departments()->pluck('departments.id')->map(fn ($id) => (int) $id)->all();
                if (count($deptIds) > 0) {
                    $allowedOutletIds = RestaurantMaster::where('is_active', true)
                        ->where(function ($q) use ($deptIds) {
                            $q->whereIn('department_id', $deptIds)->orWhereNull('department_id');
                        })
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                } else {
                    $allowedOutletIds = [];
                }
            }
        }

        if (! $restaurantId) {
            if ($user && ($user->hasRole('Admin') || $user->hasRole('Super Admin'))) {
                $restaurantId = RestaurantMaster::where('is_active', true)->first()?->id;
            } elseif (is_array($allowedOutletIds) && count($allowedOutletIds) > 0) {
                $restaurantId = $allowedOutletIds[0];
            }
        }

        if (! $restaurantId) {
            return response()->json([
                'summary' => ['entry_count' => 0, 'amount_total' => 0],
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0],
            ]);
        }

        $perPage = 50;

        $base = PosOrderRefund::query()
            ->whereHas('order', fn ($q) => $q->where('restaurant_id', (int) $restaurantId))
            ->where(function ($q) use ($from, $to) {
                $q->where(function ($q2) use ($from, $to) {
                    $q2->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to);
                })->orWhere(function ($legacy) use ($from, $to) {
                    $legacy->whereNull('business_date')
                        ->whereDate('refunded_at', '>=', $from)
                        ->whereDate('refunded_at', '<=', $to);
                });
            });

        if ($category !== 'all') {
            $isLiquor = $category === 'bar';
            $base->whereExists(function ($q) use ($isLiquor) {
                $q->select(DB::raw(1))
                    ->from('pos_order_items as poi')
                    ->leftJoin('menu_items as mi', 'poi.menu_item_id', '=', 'mi.id')
                    ->leftJoin('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                    ->whereColumn('poi.order_id', 'pos_order_refunds.order_id')
                    ->where(function ($sq) use ($isLiquor) {
                        $taxFilter = "(poi.tax_regime = 'vat_liquor' OR (it.type IS NOT NULL AND LOWER(it.type) = 'vat') OR EXISTS (
                            SELECT 1 FROM combo_items ci
                            JOIN menu_items mi2 ON ci.menu_item_id = mi2.id
                            JOIN inventory_taxes it2 ON mi2.tax_id = it2.id
                            WHERE ci.combo_id = poi.combo_id AND LOWER(it2.type) = 'vat'
                        ))";
                        if ($isLiquor) $sq->whereRaw($taxFilter);
                        else $sq->whereRaw("NOT $taxFilter");
                    });
            });
        }

        if ($category === 'all') {
            $amountTotal = (float) (clone $base)->sum('amount');
        } else {
            $allMatching = (clone $base)->with(['order.items' => function($q) {
                $q->leftJoin('menu_items as mi', 'pos_order_items.menu_item_id', '=', 'mi.id')
                  ->leftJoin('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                  ->select('pos_order_items.*', 'it.type as tax_type');
            }])->get();

            $amountTotal = 0;
            $isLiquor = $category === 'bar';
            foreach ($allMatching as $r) {
                $orderSubtotal = (float) $r->order->total_amount;
                if ($orderSubtotal <= 0) continue;
                $deptSubtotal = 0;
                foreach ($r->order->items as $item) {
                     $taxFilter = ($item->tax_regime === 'vat_liquor' || (isset($item->tax_type) && strtolower($item->tax_type) === 'vat'));
                     if (!$taxFilter && $item->combo_id) {
                         $taxFilter = DB::table('combo_items as ci')
                            ->join('menu_items as mi2', 'ci.menu_item_id', '=', 'mi2.id')
                            ->join('inventory_taxes as it2', 'mi2.tax_id', '=', 'it2.id')
                            ->where('ci.combo_id', $item->combo_id)
                            ->whereRaw('LOWER(it2.type) = ?', ['vat'])
                            ->exists();
                     }
                     if ($isLiquor === (bool)$taxFilter) $deptSubtotal += (float) $item->line_total;
                }
                $amountTotal += $r->amount * ($deptSubtotal / $orderSubtotal);
            }
        }
        $entryCount = (clone $base)->count();

        $paginated = (clone $base)
            ->with([
                'order.restaurant:id,name',
                'order.waiter:id,name',
                'refundedBy:id,name',
                'order.items' => function($q) {
                    $q->leftJoin('menu_items as mi', 'pos_order_items.menu_item_id', '=', 'mi.id')
                      ->leftJoin('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                      ->select('pos_order_items.*', 'it.type as tax_type');
                }
             ])
            ->orderByDesc('refunded_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $data = collect($paginated->items())->map(function ($r) use ($category) {
            $o = $r->order;
            $displayAmount = (float) $r->amount;
            if ($category !== 'all') {
                $isLiquor = $category === 'bar';
                $orderSubtotal = (float) ($o->total_amount ?? 0);
                if ($orderSubtotal > 0) {
                    $deptSubtotal = 0;
                    foreach ($o->items as $item) {
                        $taxFilter = ($item->tax_regime === 'vat_liquor' || (isset($item->tax_type) && strtolower($item->tax_type) === 'vat'));
                        if (!$taxFilter && $item->combo_id) {
                             $taxFilter = DB::table('combo_items as ci')
                                ->join('menu_items as mi2', 'ci.menu_item_id', '=', 'mi2.id')
                                ->join('inventory_taxes as it2', 'mi2.tax_id', '=', 'it2.id')
                                ->where('ci.combo_id', $item->combo_id)
                                ->whereRaw('LOWER(it2.type) = ?', ['vat'])
                                ->exists();
                        }
                        if ($isLiquor === (bool)$taxFilter) $deptSubtotal += (float) $item->line_total;
                    }
                    $displayAmount = $r->amount * ($deptSubtotal / $orderSubtotal);
                }
            }

            return [
                'id' => $r->id,
                'order_id' => $r->order_id,
                'refund_business_date' => $r->business_date?->format('Y-m-d'),
                'refunded_at' => $r->refunded_at?->toDateTimeString(),
                'amount' => $displayAmount,
                'method' => $r->method,
                'reference_no' => $r->reference_no,
                'reason' => $r->reason,
                'order_business_date' => $o?->business_date?->format('Y-m-d'),
                'restaurant' => $o?->restaurant?->name ?? '—',
                'customer_name' => $o?->customer_name ?? null,
                'customer_gstin' => $o?->customer_gstin ?? null,
                'waiter' => $o?->waiter?->name ?? '—',
                'refunded_by' => $r->refundedBy?->name ?? '—',
            ];
        });

        return response()->json([
            'summary' => ['entry_count' => $entryCount, 'amount_total' => $amountTotal],
            'data' => $data,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Export refund register (CSV / PDF).
     */
    public function refundsAdjustmentsExport(Request $request)
    {
        $this->checkPermission('report-refunds-adjustments');

        $restaurantId = $request->query('restaurant_id');
        $from = $request->query('from') ?? now()->toDateString();
        $to = $request->query('to') ?? now()->toDateString();
        $type = $request->query('type', 'csv');
        $category = $request->query('category', 'all');

        if (! $restaurantId || ! $this->userCanAccessRestaurant((int) $restaurantId)) {
            abort(403, 'Unauthorized access to this outlet.');
        }

        $restaurant = RestaurantMaster::findOrFail((int) $restaurantId);

        $rowsQuery = PosOrderRefund::query()
            ->whereHas('order', fn ($q) => $q->where('restaurant_id', (int) $restaurantId))
            ->where(function ($q) use ($from, $to) {
                $q->where(function ($q2) use ($from, $to) {
                    $q2->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to);
                })->orWhere(function ($legacy) use ($from, $to) {
                    $legacy->whereNull('business_date')
                        ->whereDate('refunded_at', '>=', $from)
                        ->whereDate('refunded_at', '<=', $to);
                });
            })
            ->with(['order.waiter:id,name', 'refundedBy:id,name', 'order.items' => function($q) {
                $q->leftJoin('menu_items as mi', 'pos_order_items.menu_item_id', '=', 'mi.id')
                  ->leftJoin('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                  ->select('pos_order_items.*', 'it.type as tax_type');
            }]);

        if ($category !== 'all') {
            $isLiquor = $category === 'bar';
            $rowsQuery->whereExists(function ($q) use ($isLiquor) {
                $q->select(DB::raw(1))
                    ->from('pos_order_items as poi')
                    ->leftJoin('menu_items as mi', 'poi.menu_item_id', '=', 'mi.id')
                    ->leftJoin('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                    ->whereColumn('poi.order_id', 'pos_order_refunds.order_id')
                    ->where(function ($sq) use ($isLiquor) {
                        $taxFilter = "(poi.tax_regime = 'vat_liquor' OR (it.type IS NOT NULL AND LOWER(it.type) = 'vat') OR EXISTS (
                            SELECT 1 FROM combo_items ci
                            JOIN menu_items mi2 ON ci.menu_item_id = mi2.id
                            JOIN inventory_taxes it2 ON mi2.tax_id = it2.id
                            WHERE ci.combo_id = poi.combo_id AND LOWER(it2.type) = 'vat'
                        ))";
                        if ($isLiquor) $sq->whereRaw($taxFilter);
                        else $sq->whereRaw("NOT $taxFilter");
                    });
            });
        }

        $rows = $rowsQuery->orderByDesc('refunded_at')
            ->orderByDesc('id')
            ->get();

        // Pro-rate if filtered
        if ($category !== 'all') {
            $isLiquor = $category === 'bar';
            foreach ($rows as $r) {
                $orderSubtotal = (float) ($r->order->total_amount ?? 0);
                if ($orderSubtotal > 0) {
                    $deptSubtotal = 0;
                    foreach ($r->order->items as $item) {
                        $taxFilter = ($item->tax_regime === 'vat_liquor' || (isset($item->tax_type) && strtolower($item->tax_type) === 'vat'));
                        if (!$taxFilter && $item->combo_id) {
                             $taxFilter = DB::table('combo_items as ci')
                                ->join('menu_items as mi2', 'ci.menu_item_id', '=', 'mi2.id')
                                ->join('inventory_taxes as it2', 'mi2.tax_id', '=', 'it2.id')
                                ->where('ci.combo_id', $item->combo_id)
                                ->whereRaw('LOWER(it2.type) = ?', ['vat'])
                                ->exists();
                        }
                        if ($isLiquor === (bool)$taxFilter) $deptSubtotal += (float) $item->line_total;
                    }
                    $r->amount = $r->amount * ($deptSubtotal / $orderSubtotal);
                }
            }
        }

        if ($type === 'pdf') {
            $pdf = Pdf::loadView('reports.refunds_adjustments', [
                'rows' => $rows,
                'restaurant' => $restaurant,
                'from' => $from,
                'to' => $to,
                'category' => $category,
            ]);

            return $pdf->download("refund_register_{$from}_to_{$to}.pdf");
        }

        $fileName = "refund_register_{$from}_to_{$to}.csv";
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$fileName}",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $columns = ['Refund ID', 'Order #', 'Customer Name', 'GSTIN', 'Refund date', 'Refunded at', 'Amount', 'Method', 'Reference', 'Reason', 'Order date', 'Staff', 'Refunded by'];
        $callback = function () use ($rows, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);
            foreach ($rows as $r) {
                $o = $r->order;
                fputcsv($file, [
                    $r->id,
                    $r->order_id,
                    $o?->customer_name ?? '—',
                    $o?->customer_gstin ?? '—',
                    $r->business_date?->format('Y-m-d') ?? '',
                    $r->refunded_at?->format('Y-m-d H:i') ?? '',
                    $r->amount,
                    $r->method,
                    $r->reference_no,
                    $r->reason,
                    $o?->business_date?->format('Y-m-d') ?? '',
                    $o?->waiter?->name ?? '—',
                    $r->refundedBy?->name ?? '—',
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Voids & discounts: void bills, void lines, or bill-level discounts (manager / LP review).
     *
     * Query: from, to, restaurant_id (optional), section=void_bills|void_items|discounts, page
     */
    public function voidsDiscountsReport(Request $request)
    {
        $this->checkPermission('report-voids-discounts');

        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'restaurant_id' => 'nullable|integer|exists:restaurant_masters,id',
            'section' => 'required|in:void_bills,void_items,discounts',
            'page' => 'nullable|integer|min:1',
            'category' => 'nullable|string|in:all,kitchen,bar',
        ]);

        $user = auth()->user();
        $from = $validated['from'];
        $to = $validated['to'];
        $section = $validated['section'];
        $restaurantId = $validated['restaurant_id'] ?? null;
        $category = $validated['category'] ?? 'all';

        if ($restaurantId) {
            $this->authorizeRestaurantId((int) $restaurantId);
        }

        $allowedOutletIds = null;
        if (! $restaurantId && $user && ! $user->hasRole('Admin') && ! $user->hasRole('Super Admin')) {
            $assigned = $user->restaurants()->pluck('restaurant_masters.id')->map(fn ($id) => (int) $id)->all();
            if (count($assigned) > 0) {
                $allowedOutletIds = $assigned;
            } else {
                $deptIds = $user->departments()->pluck('departments.id')->map(fn ($id) => (int) $id)->all();
                if (count($deptIds) > 0) {
                    $allowedOutletIds = RestaurantMaster::where('is_active', true)
                        ->where(function ($q) use ($deptIds) {
                            $q->whereIn('department_id', $deptIds)->orWhereNull('department_id');
                        })
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                } else {
                    $allowedOutletIds = [];
                }
            }
        }

        if (! $restaurantId) {
            if ($user && ($user->hasRole('Admin') || $user->hasRole('Super Admin'))) {
                $restaurantId = RestaurantMaster::where('is_active', true)->first()?->id;
            } elseif (is_array($allowedOutletIds) && count($allowedOutletIds) > 0) {
                $restaurantId = $allowedOutletIds[0];
            }
        }

        if (! $restaurantId) {
            return response()->json([
                'section' => $section,
                'summary' => ['entry_count' => 0, 'amount_total' => 0],
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0],
            ]);
        }

        $perPage = 50;
        $rid = (int) $restaurantId;

        if ($section === 'void_bills') {
            $base = PosOrder::query()
                ->where('status', 'void')
                ->where('restaurant_id', $rid)
                ->where(function ($q) use ($from, $to) {
                    $q->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)
                        ->orWhere(function ($sq) use ($from, $to) {
                            $sq->whereDate('voided_at', '>=', $from)->whereDate('voided_at', '<=', $to);
                        });
                });

            if ($category !== 'all') {
                $isLiquor = $category === 'bar';
                $base->whereExists(function ($q) use ($isLiquor) {
                    $q->select(DB::raw(1))
                        ->from('pos_order_items as poi')
                        ->leftJoin('menu_items as mi', 'poi.menu_item_id', '=', 'mi.id')
                        ->leftJoin('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                        ->whereColumn('poi.order_id', 'pos_orders.id')
                        ->where(function($sq) use ($isLiquor) {
                            $taxFilter = "(poi.tax_regime = 'vat_liquor' OR (it.type IS NOT NULL AND LOWER(it.type) = 'vat') OR EXISTS (
                                SELECT 1 FROM combo_items ci
                                JOIN menu_items mi2 ON ci.menu_item_id = mi2.id
                                JOIN inventory_taxes it2 ON mi2.tax_id = it2.id
                                WHERE ci.combo_id = poi.combo_id AND LOWER(it2.type) = 'vat'
                            ))";
                            if ($isLiquor) $sq->whereRaw($taxFilter);
                            else $sq->whereRaw("NOT $taxFilter");
                        });
                });
            }

            if ($category === 'all') {
                $amountTotal = (float) (clone $base)->sum('total_amount');
            } else {
                $allMatching = (clone $base)->with(['items' => function($q) {
                    $q->leftJoin('menu_items as mi', 'pos_order_items.menu_item_id', '=', 'mi.id')
                      ->leftJoin('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                      ->select('pos_order_items.*', 'it.type as tax_type');
                }])->get();
                $amountTotal = 0;
                $isLiquor = $category === 'bar';
                foreach ($allMatching as $o) {
                    $orderSubtotal = (float) ($o->total_amount ?? 0);
                    if ($orderSubtotal <= 0) continue;
                    $deptSubtotal = 0;
                    foreach ($o->items as $item) {
                        $taxFilter = ($item->tax_regime === 'vat_liquor' || (isset($item->tax_type) && strtolower($item->tax_type) === 'vat'));
                        if (!$taxFilter && $item->combo_id) {
                             $taxFilter = DB::table('combo_items as ci')
                                ->join('menu_items as mi2', 'ci.menu_item_id', '=', 'mi2.id')
                                ->join('inventory_taxes as it2', 'mi2.tax_id', '=', 'it2.id')
                                ->where('ci.combo_id', $item->combo_id)
                                ->whereRaw('LOWER(it2.type) = ?', ['vat'])
                                ->exists();
                        }
                        if ($isLiquor === (bool)$taxFilter) $deptSubtotal += (float) $item->line_total;
                    }
                    $amountTotal += (float) $o->total_amount * ($deptSubtotal / $orderSubtotal);
                }
            }
            $entryCount = (clone $base)->count();

            $paginated = (clone $base)
                ->with(['restaurant:id,name', 'waiter:id,name', 'voidedBy:id,name'])
                ->orderByDesc('voided_at')
                ->orderByDesc('id')
                ->paginate($perPage);

            $dates = collect($paginated->items())->map(fn ($o) => $o->business_date?->format('Y-m-d'))->filter()->unique()->values()->all();
            $closingMap = $this->posDayClosingMapForDates($rid, $dates);

            $data = collect($paginated->items())->map(function ($o) use ($closingMap, $category) {
                $bd = $o->business_date?->format('Y-m-d');
                $close = $bd ? ($closingMap[$bd] ?? null) : null;
                $displayAmount = (float) $o->total_amount;

                if ($category !== 'all') {
                    $isLiquor = $category === 'bar';
                    $orderSubtotal = (float) ($o->total_amount ?? 0);
                    if ($orderSubtotal > 0) {
                        $deptSubtotal = 0;
                        foreach ($o->items as $item) {
                            $taxFilter = ($item->tax_regime === 'vat_liquor' || (isset($item->tax_type) && strtolower($item->tax_type) === 'vat'));
                            if (!$taxFilter && $item->combo_id) {
                                 $taxFilter = DB::table('combo_items as ci')
                                    ->join('menu_items as mi2', 'ci.menu_item_id', '=', 'mi2.id')
                                    ->join('inventory_taxes as it2', 'mi2.tax_id', '=', 'it2.id')
                                    ->where('ci.combo_id', $item->combo_id)
                                    ->whereRaw('LOWER(it2.type) = ?', ['vat'])
                                    ->exists();
                            }
                            if ($isLiquor === (bool)$taxFilter) $deptSubtotal += (float) $item->line_total;
                        }
                        $displayAmount = (float) $o->total_amount * ($deptSubtotal / $orderSubtotal);
                    }
                }

                return [
                    'id' => $o->id,
                    'business_date' => $bd,
                    'voided_at' => $o->voided_at?->toDateTimeString(),
                    'total_amount' => $displayAmount,
                    'void_reason' => $o->void_reason,
                    'void_notes' => $o->void_notes,
                    'order_type' => $o->order_type ?? 'dine_in',
                    'restaurant' => $o->restaurant?->name ?? '—',
                    'waiter' => $o->waiter?->name ?? '—',
                    'voided_by' => $o->voidedBy?->name ?? '—',
                    'day_close_at' => $close['day_closed_at'] ?? null,
                    'day_close_by' => $close['day_closed_by'] ?? null,
                ];
            });

            return response()->json([
                'section' => $section,
                'summary' => ['entry_count' => $entryCount, 'amount_total' => $amountTotal],
                'data' => $data,
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'total' => $paginated->total(),
                ],
            ]);
        }

        if ($section === 'void_items') {
            $base = PosOrderItem::query()
                ->where('status', 'cancelled')
                ->whereNotNull('cancelled_at')
                ->whereHas('order', fn ($q) => $q->where('restaurant_id', $rid))
                ->whereDate('cancelled_at', '>=', $from)
                ->whereDate('cancelled_at', '<=', $to);

            if ($category !== 'all') {
                $isLiquor = $category === 'bar';
                $base->where(function($q) use ($isLiquor) {
                    $q->whereExists(function ($sq) use ($isLiquor) {
                        $sq->select(DB::raw(1))
                            ->from('menu_items as mi')
                            ->leftJoin('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                            ->whereColumn('mi.id', 'pos_order_items.menu_item_id')
                            ->whereRaw('LOWER(it.type) ' . ($isLiquor ? '=' : '!=') . ' ?', ['vat']);
                    })
                    ->orWhere(function($sq) use ($isLiquor) {
                        if ($isLiquor) $sq->where('pos_order_items.tax_regime', 'vat_liquor');
                        else $sq->where('pos_order_items.tax_regime', '!=', 'vat_liquor');
                    })
                    ->orWhereExists(function ($sq) use ($isLiquor) {
                        $sq->select(DB::raw(1))
                            ->from('combo_items as ci')
                            ->join('menu_items as mi2', 'ci.menu_item_id', '=', 'mi2.id')
                            ->join('inventory_taxes as it2', 'mi2.tax_id', '=', 'it2.id')
                            ->whereColumn('ci.combo_id', 'pos_order_items.combo_id')
                            ->whereRaw('LOWER(it2.type) ' . ($isLiquor ? '=' : '!=') . ' ?', ['vat']);
                    });
                });
            }

            $amountTotal = (float) (clone $base)->sum('line_total');
            $entryCount = (clone $base)->count();

            $paginated = (clone $base)
                ->with(['order', 'order.restaurant:id,name', 'order.waiter:id,name', 'menuItem:id,name', 'combo:id,name', 'cancelledBy:id,name'])
                ->orderByDesc('cancelled_at')
                ->orderByDesc('id')
                ->paginate($perPage);

            $orderDates = [];
            foreach ($paginated->items() as $item) {
                $bd = $item->order?->business_date?->format('Y-m-d');
                if ($bd) {
                    $orderDates[$bd] = true;
                }
            }
            $closingMap = $this->posDayClosingMapForDates($rid, array_keys($orderDates));

            $data = collect($paginated->items())->map(function ($item) use ($closingMap) {
                $o = $item->order;
                $lineName = $item->menuItem?->name ?? ($item->combo?->name ? 'Combo: '.$item->combo->name : '—');
                $bd = $o?->business_date?->format('Y-m-d');
                $close = $bd ? ($closingMap[$bd] ?? null) : null;

                return [
                    'id' => $item->id,
                    'order_id' => $item->order_id,
                    'cancelled_at' => $item->cancelled_at?->toDateTimeString(),
                    'line_total' => (float) $item->line_total,
                    'quantity' => (float) $item->quantity,
                    'cancel_reason' => $item->cancel_reason,
                    'cancel_notes' => $item->cancel_notes,
                    'item_name' => $lineName,
                    'business_date' => $bd,
                    'restaurant' => $o?->restaurant?->name ?? '—',
                    'waiter' => $o?->waiter?->name ?? '—',
                    'cancelled_by' => $item->cancelledBy?->name ?? '—',
                    'day_close_at' => $close['day_closed_at'] ?? null,
                    'day_close_by' => $close['day_closed_by'] ?? null,
                ];
            });

            return response()->json([
                'section' => $section,
                'summary' => ['entry_count' => $entryCount, 'amount_total' => $amountTotal],
                'data' => $data,
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'total' => $paginated->total(),
                ],
            ]);
        }

        // discounts
        $base = PosOrder::query()
            ->whereIn('status', ['paid', 'refunded'])
            ->where('restaurant_id', $rid)
            ->where(function ($q) {
                $q->where('discount_amount', '>', 0.005)
                    ->orWhere('is_complimentary', true);
            })
            ->where(function ($q) use ($from, $to) {
                $q->where(function ($q2) use ($from, $to) {
                    $q2->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to);
                })->orWhere(function ($sq) use ($from, $to) {
                    $sq->whereDate('closed_at', '>=', $from)->whereDate('closed_at', '<=', $to);
                });
            });

        if ($category !== 'all') {
            $isLiquor = $category === 'bar';
            $base->whereExists(function ($q) use ($isLiquor) {
                $q->select(DB::raw(1))
                    ->from('pos_order_items as poi')
                    ->leftJoin('menu_items as mi', 'poi.menu_item_id', '=', 'mi.id')
                    ->leftJoin('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                    ->whereColumn('poi.order_id', 'pos_orders.id')
                    ->where(function ($sq) use ($isLiquor) {
                        $taxFilter = "(poi.tax_regime = 'vat_liquor' OR (it.type IS NOT NULL AND LOWER(it.type) = 'vat') OR EXISTS (
                            SELECT 1 FROM combo_items ci
                            JOIN menu_items mi2 ON ci.menu_item_id = mi2.id
                            JOIN inventory_taxes it2 ON mi2.tax_id = it2.id
                            WHERE ci.combo_id = poi.combo_id AND LOWER(it2.type) = 'vat'
                        ))";
                        if ($isLiquor) $sq->whereRaw($taxFilter);
                        else $sq->whereRaw("NOT $taxFilter");
                    });
            });
        }

        if ($category === 'all') {
            $amountTotal = (float) (clone $base)->sum('discount_amount');
        } else {
            $allMatching = (clone $base)->with(['items' => function($q) {
                $q->leftJoin('menu_items as mi', 'pos_order_items.menu_item_id', '=', 'mi.id')
                  ->leftJoin('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                  ->select('pos_order_items.*', 'it.type as tax_type');
            }])->get();
            $amountTotal = 0;
            $isLiquor = $category === 'bar';
            foreach ($allMatching as $o) {
                $orderSubtotal = (float) ($o->total_amount + $o->discount_amount);
                if ($orderSubtotal <= 0) continue;
                $deptSubtotal = 0;
                foreach ($o->items as $item) {
                    $taxFilter = ($item->tax_regime === 'vat_liquor' || (isset($item->tax_type) && strtolower($item->tax_type) === 'vat'));
                    if (!$taxFilter && $item->combo_id) {
                         $taxFilter = DB::table('combo_items as ci')
                            ->join('menu_items as mi2', 'ci.menu_item_id', '=', 'mi2.id')
                            ->join('inventory_taxes as it2', 'mi2.tax_id', '=', 'it2.id')
                            ->where('ci.combo_id', $item->combo_id)
                            ->whereRaw('LOWER(it2.type) = ?', ['vat'])
                            ->exists();
                    }
                    if ($isLiquor === (bool)$taxFilter) $deptSubtotal += (float) $item->line_total;
                }
                $amountTotal += (float) $o->discount_amount * ($deptSubtotal / $orderSubtotal);
            }
        }
        $entryCount = (clone $base)->count();

        $paginated = (clone $base)
            ->with([
                'restaurant:id,name',
                'waiter:id,name',
                'discountApprovedBy:id,name',
                'items' => function($q) {
                    $q->leftJoin('menu_items as mi', 'pos_order_items.menu_item_id', '=', 'mi.id')
                      ->leftJoin('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                      ->select('pos_order_items.*', 'it.type as tax_type');
                }
            ])
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $dates = collect($paginated->items())->map(fn ($o) => $o->business_date?->format('Y-m-d'))->filter()->unique()->values()->all();
        $closingMap = $this->posDayClosingMapForDates($rid, $dates);

        $data = collect($paginated->items())->map(function ($o) use ($closingMap, $category) {
            $bd = $o->business_date?->format('Y-m-d');
            $close = $bd ? ($closingMap[$bd] ?? null) : null;
            $displayAmount = (float) $o->discount_amount;

            if ($category !== 'all') {
                $isLiquor = $category === 'bar';
                $orderSubtotal = (float) ($o->total_amount + $o->discount_amount);
                if ($orderSubtotal > 0) {
                    $deptSubtotal = 0;
                    foreach ($o->items as $item) {
                        $taxFilter = ($item->tax_regime === 'vat_liquor' || (isset($item->tax_type) && strtolower($item->tax_type) === 'vat'));
                        if (!$taxFilter && $item->combo_id) {
                             $taxFilter = DB::table('combo_items as ci')
                                ->join('menu_items as mi2', 'ci.menu_item_id', '=', 'mi2.id')
                                ->join('inventory_taxes as it2', 'mi2.tax_id', '=', 'it2.id')
                                ->where('ci.combo_id', $item->combo_id)
                                ->whereRaw('LOWER(it2.type) = ?', ['vat'])
                                ->exists();
                        }
                        if ($isLiquor === (bool)$taxFilter) $deptSubtotal += (float) $item->line_total;
                    }
                    $displayAmount = (float) $o->discount_amount * ($deptSubtotal / $orderSubtotal);
                }
            }

            return [
                'id' => $o->id,
                'business_date' => $bd,
                'closed_at' => $o->closed_at?->toDateTimeString(),
                'discount_amount' => $displayAmount,
                'discount_type' => $o->discount_type,
                'discount_value' => (float) ($o->discount_value ?? 0),
                'is_complimentary' => (bool) $o->is_complimentary,
                'bill_total' => (float) $o->total_amount,
                'order_type' => $o->order_type ?? 'dine_in',
                'restaurant' => $o->restaurant?->name ?? '—',
                'waiter' => $o->waiter?->name ?? '—',
                'approved_by' => $o->discountApprovedBy?->name ?? '—',
                'approved_at' => $o->discount_approved_at?->toDateTimeString(),
                'day_close_at' => $close['day_closed_at'] ?? null,
                'day_close_by' => $close['day_closed_by'] ?? null,
            ];
        });

        return response()->json([
            'section' => $section,
            'summary' => ['entry_count' => $entryCount, 'amount_total' => $amountTotal],
            'data' => $data,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Export voids & discounts (CSV / PDF).
     */
    public function voidsDiscountsExport(Request $request)
    {
        $this->checkPermission('report-voids-discounts');

        $restaurantId = $request->query('restaurant_id');
        $from = $request->query('from') ?? now()->toDateString();
        $to = $request->query('to') ?? now()->toDateString();
        $section = $request->query('section', 'void_bills');
        $type = $request->query('type', 'csv');
        $category = $request->query('category', 'all');

        if (! in_array($section, ['void_bills', 'void_items', 'discounts'], true)) {
            abort(422, 'Invalid section.');
        }

        if (! $restaurantId || ! $this->userCanAccessRestaurant((int) $restaurantId)) {
            abort(403, 'Unauthorized access to this outlet.');
        }

        $restaurant = RestaurantMaster::findOrFail((int) $restaurantId);
        $rid = (int) $restaurantId;

        if ($section === 'void_bills') {
            $rowsQuery = PosOrder::query()
                ->where('status', 'void')
                ->where('restaurant_id', $rid)
                ->where(function ($q) use ($from, $to) {
                    $q->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)
                        ->orWhere(function ($sq) use ($from, $to) {
                            $sq->whereDate('voided_at', '>=', $from)->whereDate('voided_at', '<=', $to);
                        });
                });

            if ($category !== 'all') {
                $isLiquor = $category === 'bar';
                $rowsQuery->whereExists(function ($q) use ($isLiquor) {
                    $q->select(DB::raw(1))
                        ->from('pos_order_items as poi')
                        ->leftJoin('menu_items as mi', 'poi.menu_item_id', '=', 'mi.id')
                        ->leftJoin('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                        ->whereColumn('poi.order_id', 'pos_orders.id')
                        ->where(function($sq) use ($isLiquor) {
                            $taxFilter = "(poi.tax_regime = 'vat_liquor' OR (it.type IS NOT NULL AND LOWER(it.type) = 'vat') OR EXISTS (
                                SELECT 1 FROM combo_items ci
                                JOIN menu_items mi2 ON ci.menu_item_id = mi2.id
                                JOIN inventory_taxes it2 ON mi2.tax_id = it2.id
                                WHERE ci.combo_id = poi.combo_id AND LOWER(it2.type) = 'vat'
                            ))";
                            if ($isLiquor) $sq->whereRaw($taxFilter);
                            else $sq->whereRaw("NOT $taxFilter");
                        });
                });
            }

            $rows = $rowsQuery->with(['waiter:id,name', 'voidedBy:id,name', 'items' => function($q) {
                $q->leftJoin('menu_items as mi', 'pos_order_items.menu_item_id', '=', 'mi.id')
                  ->leftJoin('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                  ->select('pos_order_items.*', 'it.type as tax_type');
            }])
                ->orderByDesc('voided_at')
                ->orderByDesc('id')
                ->get();

            if ($category !== 'all') {
                $isLiquor = $category === 'bar';
                foreach ($rows as $r) {
                    $orderSubtotal = (float) ($r->total_amount ?? 0);
                    if ($orderSubtotal > 0) {
                        $deptSubtotal = 0;
                        foreach ($r->items as $item) {
                            $taxFilter = ($item->tax_regime === 'vat_liquor' || (isset($item->tax_type) && strtolower($item->tax_type) === 'vat'));
                            if (!$taxFilter && $item->combo_id) {
                                 $taxFilter = DB::table('combo_items as ci')
                                    ->join('menu_items as mi2', 'ci.menu_item_id', '=', 'mi2.id')
                                    ->join('inventory_taxes as it2', 'mi2.tax_id', '=', 'it2.id')
                                    ->where('ci.combo_id', $item->combo_id)
                                    ->whereRaw('LOWER(it2.type) = ?', ['vat'])
                                    ->exists();
                            }
                            if ($isLiquor === (bool)$taxFilter) $deptSubtotal += (float) $item->line_total;
                        }
                        $r->total_amount = $r->total_amount * ($deptSubtotal / $orderSubtotal);
                    }
                }
            }
        } elseif ($section === 'void_items') {
            $rowsQuery = PosOrderItem::query()
                ->where('status', 'cancelled')
                ->whereNotNull('cancelled_at')
                ->whereHas('order', fn ($q) => $q->where('restaurant_id', $rid))
                ->whereDate('cancelled_at', '>=', $from)
                ->whereDate('cancelled_at', '<=', $to);

            if ($category !== 'all') {
                $isLiquor = $category === 'bar';
                $rowsQuery->where(function($q) use ($isLiquor) {
                    $q->whereExists(function ($sq) use ($isLiquor) {
                        $sq->select(DB::raw(1))
                            ->from('menu_items as mi')
                            ->leftJoin('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                            ->whereColumn('mi.id', 'pos_order_items.menu_item_id')
                            ->whereRaw('LOWER(it.type) ' . ($isLiquor ? '=' : '!=') . ' ?', ['vat']);
                    })
                    ->orWhere(function($sq) use ($isLiquor) {
                        if ($isLiquor) $sq->where('pos_order_items.tax_regime', 'vat_liquor');
                        else $sq->where('pos_order_items.tax_regime', '!=', 'vat_liquor');
                    })
                    ->orWhereExists(function ($sq) use ($isLiquor) {
                        $sq->select(DB::raw(1))
                            ->from('combo_items as ci')
                            ->join('menu_items as mi2', 'ci.menu_item_id', '=', 'mi2.id')
                            ->join('inventory_taxes as it2', 'mi2.tax_id', '=', 'it2.id')
                            ->whereColumn('ci.combo_id', 'pos_order_items.combo_id')
                            ->whereRaw('LOWER(it2.type) ' . ($isLiquor ? '=' : '!=') . ' ?', ['vat']);
                    });
                });
            }

            $rows = $rowsQuery->with(['order', 'order.waiter:id,name', 'menuItem:id,name', 'combo:id,name', 'cancelledBy:id,name'])
                ->orderByDesc('cancelled_at')
                ->orderByDesc('id')
                ->get();
        } else {
            $rowsQuery = PosOrder::query()
                ->whereIn('status', ['paid', 'refunded'])
                ->where('restaurant_id', $rid)
                ->where(function ($q) {
                    $q->where('discount_amount', '>', 0.005)
                        ->orWhere('is_complimentary', true);
                })
                ->where(function ($q) use ($from, $to) {
                    $q->where(function ($q2) use ($from, $to) {
                        $q2->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to);
                    })->orWhere(function ($sq) use ($from, $to) {
                        $sq->whereDate('closed_at', '>=', $from)->whereDate('closed_at', '<=', $to);
                    });
                });

            if ($category !== 'all') {
                $isLiquor = $category === 'bar';
                $rowsQuery->whereExists(function ($q) use ($isLiquor) {
                    $q->select(DB::raw(1))
                        ->from('pos_order_items as poi')
                        ->leftJoin('menu_items as mi', 'poi.menu_item_id', '=', 'mi.id')
                        ->leftJoin('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                        ->whereColumn('poi.order_id', 'pos_orders.id')
                        ->where(function ($sq) use ($isLiquor) {
                            $taxFilter = "(poi.tax_regime = 'vat_liquor' OR (it.type IS NOT NULL AND LOWER(it.type) = 'vat') OR EXISTS (
                                SELECT 1 FROM combo_items ci
                                JOIN menu_items mi2 ON ci.menu_item_id = mi2.id
                                JOIN inventory_taxes it2 ON mi2.tax_id = it2.id
                                WHERE ci.combo_id = poi.combo_id AND LOWER(it2.type) = 'vat'
                            ))";
                            if ($isLiquor) $sq->whereRaw($taxFilter);
                            else $sq->whereRaw("NOT $taxFilter");
                        });
                });
            }

            $rows = $rowsQuery->with(['waiter:id,name', 'discountApprovedBy:id,name', 'items' => function($q) {
                $q->leftJoin('menu_items as mi', 'pos_order_items.menu_item_id', '=', 'mi.id')
                  ->leftJoin('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                  ->select('pos_order_items.*', 'it.type as tax_type');
            }])
                ->orderByDesc('closed_at')
                ->orderByDesc('id')
                ->get();

            if ($category !== 'all') {
                $isLiquor = $category === 'bar';
                foreach ($rows as $r) {
                    $orderSubtotal = (float) ($r->total_amount + $r->discount_amount);
                    if ($orderSubtotal > 0) {
                        $deptSubtotal = 0;
                        foreach ($r->items as $item) {
                            $taxFilter = ($item->tax_regime === 'vat_liquor' || (isset($item->tax_type) && strtolower($item->tax_type) === 'vat'));
                            if (!$taxFilter && $item->combo_id) {
                                 $taxFilter = DB::table('combo_items as ci')
                                    ->join('menu_items as mi2', 'ci.menu_item_id', '=', 'mi2.id')
                                    ->join('inventory_taxes as it2', 'mi2.tax_id', '=', 'it2.id')
                                    ->where('ci.combo_id', $item->combo_id)
                                    ->whereRaw('LOWER(it2.type) = ?', ['vat'])
                                    ->exists();
                            }
                            if ($isLiquor === (bool)$taxFilter) $deptSubtotal += (float) $item->line_total;
                        }
                        $r->discount_amount = $r->discount_amount * ($deptSubtotal / $orderSubtotal);
                    }
                }
            }
        }

        if ($type === 'pdf') {
            $closingMap = [];
            if ($section === 'void_bills' || $section === 'discounts') {
                $dates = $rows->map(fn ($o) => $o->business_date?->format('Y-m-d'))->filter()->unique()->values()->all();
                $closingMap = $this->posDayClosingMapForDates($rid, $dates);
            } elseif ($section === 'void_items') {
                $orderDates = [];
                foreach ($rows as $item) {
                    $bd = $item->order?->business_date?->format('Y-m-d');
                    if ($bd) {
                        $orderDates[$bd] = true;
                    }
                }
                $closingMap = $this->posDayClosingMapForDates($rid, array_keys($orderDates));
            }

            $pdf = Pdf::loadView('reports.voids_discounts', [
                'rows' => $rows,
                'restaurant' => $restaurant,
                'from' => $from,
                'to' => $to,
                'section' => $section,
                'category' => $category,
                'closingMap' => $closingMap,
            ]);

            return $pdf->download("voids_discounts_{$section}_{$from}_to_{$to}.pdf");
        }

        $fileName = "voids_discounts_{$section}_{$from}_to_{$to}.csv";
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$fileName}",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        if ($section === 'void_bills') {
            $dates = $rows->map(fn ($o) => $o->business_date?->format('Y-m-d'))->filter()->unique()->values()->all();
            $closingMap = $this->posDayClosingMapForDates($rid, $dates);
            $columns = ['Bill #', 'Business date', 'Voided at', 'Amount', 'Type', 'Reason', 'Notes', 'Staff', 'Voided by', 'Day close at', 'Day close by'];
            $callback = function () use ($rows, $columns, $closingMap) {
                $file = fopen('php://output', 'w');
                fputcsv($file, $columns);
                foreach ($rows as $o) {
                    $bd = $o->business_date?->format('Y-m-d');
                    $close = $bd ? ($closingMap[$bd] ?? null) : null;
                    fputcsv($file, [
                        $o->id,
                        $o->business_date?->format('Y-m-d') ?? '',
                        $o->voided_at?->format('Y-m-d H:i') ?? '',
                        $o->total_amount,
                        $o->order_type ?? '',
                        $o->void_reason,
                        $o->void_notes,
                        $o->waiter?->name ?? '—',
                        $o->voidedBy?->name ?? '—',
                        $close['day_closed_at'] ?? '',
                        $close['day_closed_by'] ?? '',
                    ]);
                }
                fclose($file);
            };
        } elseif ($section === 'void_items') {
            $orderDates = [];
            foreach ($rows as $item) {
                $bd = $item->order?->business_date?->format('Y-m-d');
                if ($bd) {
                    $orderDates[$bd] = true;
                }
            }
            $closingMap = $this->posDayClosingMapForDates($rid, array_keys($orderDates));
            $columns = ['Item ID', 'Order #', 'Business date', 'Cancelled at', 'Item', 'Qty', 'Line total', 'Reason', 'Notes', 'Staff', 'Cancelled by', 'Day close at', 'Day close by'];
            $callback = function () use ($rows, $columns, $closingMap) {
                $file = fopen('php://output', 'w');
                fputcsv($file, $columns);
                foreach ($rows as $item) {
                    $o = $item->order;
                    $lineName = $item->menuItem?->name ?? ($item->combo?->name ? 'Combo: '.$item->combo->name : '—');
                    $bd = $o?->business_date?->format('Y-m-d');
                    $close = $bd ? ($closingMap[$bd] ?? null) : null;
                    fputcsv($file, [
                        $item->id,
                        $item->order_id,
                        $bd ?? '',
                        $item->cancelled_at?->format('Y-m-d H:i') ?? '',
                        $lineName,
                        $item->quantity,
                        $item->line_total,
                        $item->cancel_reason,
                        $item->cancel_notes,
                        $o?->waiter?->name ?? '—',
                        $item->cancelledBy?->name ?? '—',
                        $close['day_closed_at'] ?? '',
                        $close['day_closed_by'] ?? '',
                    ]);
                }
                fclose($file);
            };
        } else {
            $dates = $rows->map(fn ($o) => $o->business_date?->format('Y-m-d'))->filter()->unique()->values()->all();
            $closingMap = $this->posDayClosingMapForDates($rid, $dates);
            $columns = ['Bill #', 'Business date', 'Closed at', 'Discount', 'Type', 'Value', 'Complimentary', 'Bill total', 'Order type', 'Staff', 'Approved by', 'Approved at', 'Day close at', 'Day close by'];
            $callback = function () use ($rows, $columns, $closingMap) {
                $file = fopen('php://output', 'w');
                fputcsv($file, $columns);
                foreach ($rows as $o) {
                    $bd = $o->business_date?->format('Y-m-d');
                    $close = $bd ? ($closingMap[$bd] ?? null) : null;
                    fputcsv($file, [
                        $o->id,
                        $o->business_date?->format('Y-m-d') ?? '',
                        $o->closed_at?->format('Y-m-d H:i') ?? '',
                        $o->discount_amount,
                        $o->discount_type,
                        $o->discount_value,
                        $o->is_complimentary ? 'yes' : 'no',
                        $o->total_amount,
                        $o->order_type ?? '',
                        $o->waiter?->name ?? '—',
                        $o->discountApprovedBy?->name ?? '—',
                        $o->discount_approved_at?->format('Y-m-d H:i') ?? '',
                        $close['day_closed_at'] ?? '',
                        $close['day_closed_by'] ?? '',
                    ]);
                }
                fclose($file);
            };
        }

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Order-type mix: counts and revenue by dine-in / takeaway / delivery / room service / walk-in.
     *
     * Gross = paid + refunded bills on business_date in range (same basis as sales summary).
     * Refunds = pos_order_refunds in range (by refund business_date), attributed to the order's type.
     */
    public function orderTypeMixReport(Request $request)
    {
        $this->checkPermission('report-order-type-mix');

        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'restaurant_id' => 'nullable|integer|exists:restaurant_masters,id',
        ]);

        $user = auth()->user();
        $from = $validated['from'];
        $to = $validated['to'];
        $restaurantId = $validated['restaurant_id'] ?? null;

        if ($restaurantId) {
            $this->authorizeRestaurantId((int) $restaurantId);
        }

        $allowedOutletIds = null;
        if (! $restaurantId && $user && ! $user->hasRole('Admin') && ! $user->hasRole('Super Admin')) {
            $assigned = $user->restaurants()->pluck('restaurant_masters.id')->map(fn ($id) => (int) $id)->all();
            if (count($assigned) > 0) {
                $allowedOutletIds = $assigned;
            } else {
                $deptIds = $user->departments()->pluck('departments.id')->map(fn ($id) => (int) $id)->all();
                if (count($deptIds) > 0) {
                    $allowedOutletIds = RestaurantMaster::where('is_active', true)
                        ->where(function ($q) use ($deptIds) {
                            $q->whereIn('department_id', $deptIds)->orWhereNull('department_id');
                        })
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                } else {
                    $allowedOutletIds = [];
                }
            }
        }

        if (! $restaurantId) {
            if ($user && ($user->hasRole('Admin') || $user->hasRole('Super Admin'))) {
                $restaurantId = RestaurantMaster::where('is_active', true)->first()?->id;
            } elseif (is_array($allowedOutletIds) && count($allowedOutletIds) > 0) {
                $restaurantId = $allowedOutletIds[0];
            }
        }

        if (! $restaurantId) {
            return response()->json([
                'by_type' => [],
                'totals' => [
                    'orders_count' => 0,
                    'gross_revenue' => 0.0,
                    'refunded_amount' => 0.0,
                    'net_revenue' => 0.0,
                ],
            ]);
        }

        $payload = $this->buildOrderTypeMixData((int) $restaurantId, $from, $to);

        return response()->json($payload);
    }

    public function orderTypeMixExport(Request $request)
    {
        $this->checkPermission('report-order-type-mix');

        $restaurantId = $request->query('restaurant_id');
        $from = $request->query('from') ?? now()->toDateString();
        $to = $request->query('to') ?? now()->toDateString();
        $type = $request->query('type', 'csv');
        $category = $request->query('category', 'all');

        if (! $restaurantId || ! $this->userCanAccessRestaurant((int) $restaurantId)) {
            abort(403, 'Unauthorized access to this outlet.');
        }

        $restaurant = RestaurantMaster::findOrFail((int) $restaurantId);
        $payload = $this->handleOrderTypeMixBucket((int) $restaurantId, $from, $to, $category);

        if ($type === 'pdf') {
            $pdf = Pdf::loadView('reports.order_type_mix', [
                'restaurant' => $restaurant,
                'from' => $from,
                'to' => $to,
                'by_type' => $payload['by_type'],
                'totals' => $payload['totals'],
                'category' => $category,
            ]);

            return $pdf->download("order_type_mix_{$from}_to_{$to}.pdf");
        }

        $fileName = "order_type_mix_{$from}_to_{$to}.csv";
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$fileName}",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($payload) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Order type', 'Bills', 'Gross revenue', 'Refunds', 'Net revenue', 'Share of net %']);
            $netTotal = max(0.01, (float) ($payload['totals']['net_revenue'] ?? 0));
            foreach ($payload['by_type'] as $row) {
                $net = (float) ($row['net_revenue'] ?? 0);
                $pct = $netTotal > 0 ? round(100 * $net / $netTotal, 1) : 0.0;
                fputcsv($file, [
                    $row['order_type'],
                    $row['orders_count'],
                    $row['gross_revenue'],
                    $row['refunded_amount'],
                    $row['net_revenue'],
                    $pct,
                ]);
            }
            fputcsv($file, ['TOTAL', $payload['totals']['orders_count'], $payload['totals']['gross_revenue'], $payload['totals']['refunded_amount'], $payload['totals']['net_revenue'], '']);
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Menu / item performance: sold qty and revenue by menu item (incl. variant) and by combo.
     *
     * Uses paid & refunded orders on business_date; only active (non–void) lines.
     */
    public function menuPerformanceReport(Request $request)
    {
        $this->checkPermission('report-menu-performance');

        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'restaurant_id' => 'nullable|integer|exists:restaurant_masters,id',
            'page' => 'nullable|integer|min:1',
            'category' => 'nullable|string|in:all,kitchen,bar',
        ]);

        $user = auth()->user();
        $from = $validated['from'];
        $to = $validated['to'];
        $page = (int) ($validated['page'] ?? 1);
        $restaurantId = $validated['restaurant_id'] ?? null;
        $category = $validated['category'] ?? 'all';

        if ($restaurantId) {
            $this->authorizeRestaurantId((int) $restaurantId);
        }

        $allowedOutletIds = null;
        if (! $restaurantId && $user && ! $user->hasRole('Admin') && ! $user->hasRole('Super Admin')) {
            $assigned = $user->restaurants()->pluck('restaurant_masters.id')->map(fn ($id) => (int) $id)->all();
            if (count($assigned) > 0) {
                $allowedOutletIds = $assigned;
            } else {
                $deptIds = $user->departments()->pluck('departments.id')->map(fn ($id) => (int) $id)->all();
                if (count($deptIds) > 0) {
                    $allowedOutletIds = RestaurantMaster::where('is_active', true)
                        ->where(function ($q) use ($deptIds) {
                            $q->whereIn('department_id', $deptIds)->orWhereNull('department_id');
                        })
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                } else {
                    $allowedOutletIds = [];
                }
            }
        }

        if (! $restaurantId) {
            if ($user && ($user->hasRole('Admin') || $user->hasRole('Super Admin'))) {
                $restaurantId = RestaurantMaster::where('is_active', true)->first()?->id;
            } elseif (is_array($allowedOutletIds) && count($allowedOutletIds) > 0) {
                $restaurantId = $allowedOutletIds[0];
            }
        }

        if (! $restaurantId) {
            return response()->json([
                'summary' => [
                    'sku_rows' => 0,
                    'qty_sold' => 0,
                    'revenue' => 0.0,
                    'bills_with_sales' => 0,
                ],
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0],
            ]);
        }

        $rid = (int) $restaurantId;
        $allRows = $this->buildMenuPerformanceRows($rid, $from, $to);

        $rows = $allRows;
        if ($category === 'kitchen') {
            $rows = $allRows->filter(fn ($r) => ! $r->is_liquor)->values();
        } elseif ($category === 'bar') {
            $rows = $allRows->filter(fn ($r) => (bool) $r->is_liquor)->values();
        }

        $summary = $this->buildMenuPerformanceSummary($rid, $from, $to, $rows, $category);

        $perPage = 50;
        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $slice = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        $data = $slice->map(function ($r) {
            $name = (string) ($r->name ?? '');
            $variant = trim((string) ($r->variant_label ?? ''));
            $display = $variant !== '' ? $name.' — '.$variant : $name;
            if (($r->row_kind ?? '') === 'combo') {
                $display = 'Combo: '.$name;
            }

            return [
                'row_kind' => $r->row_kind,
                'menu_item_id' => $r->menu_item_id ? (int) $r->menu_item_id : null,
                'combo_id' => $r->combo_id ? (int) $r->combo_id : null,
                'variant_id' => isset($r->variant_id) && $r->variant_id !== null ? (int) $r->variant_id : null,
                'category' => (string) ($r->category_name ?? '—'),
                'name' => $name,
                'variant_label' => $variant !== '' ? $variant : null,
                'display_name' => $display,
                'qty_sold' => (float) $r->qty_sold,
                'revenue' => round((float) $r->revenue, 2),
                'lines_sold' => (int) $r->lines_sold,
                'bills_count' => (int) $r->bills_count,
            ];
        });

        return response()->json([
            'summary' => $summary,
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'total' => $total,
            ],
        ]);
    }

    public function menuPerformanceExport(Request $request)
    {
        $this->checkPermission('report-menu-performance');

        $restaurantId = $request->query('restaurant_id');
        $from = $request->query('from') ?? now()->toDateString();
        $to = $request->query('to') ?? now()->toDateString();
        $type = $request->query('type', 'csv');

        if (! $restaurantId || ! $this->userCanAccessRestaurant((int) $restaurantId)) {
            abort(403, 'Unauthorized access to this outlet.');
        }

        $restaurant = RestaurantMaster::findOrFail((int) $restaurantId);
        $category = $request->query('category', 'all');
        $allRows = $this->buildMenuPerformanceRows((int) $restaurantId, $from, $to);
        
        $rows = $allRows;
        if ($category === 'kitchen') {
            $rows = $allRows->filter(fn ($r) => ! $r->is_liquor)->values();
        } elseif ($category === 'bar') {
            $rows = $allRows->filter(fn ($r) => (bool) $r->is_liquor)->values();
        }

        $summary = $this->buildMenuPerformanceSummary((int) $restaurantId, $from, $to, $rows, $category);

        if ($type === 'pdf') {
            $pdf = Pdf::loadView('reports.menu_performance', [
                'restaurant' => $restaurant,
                'from' => $from,
                'to' => $to,
                'rows' => $rows,
                'summary' => $summary,
                'category' => $category,
            ]);

            return $pdf->download("menu_performance_{$from}_to_{$to}.pdf");
        }

        $fileName = "menu_performance_{$from}_to_{$to}.csv";
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$fileName}",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($rows, $summary) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Category', 'Item / combo', 'Qty sold', 'Revenue', 'POS lines', 'Bills']);
            foreach ($rows as $r) {
                $name = (string) ($r->name ?? '');
                $variant = trim((string) ($r->variant_label ?? ''));
                $itemCol = ($r->row_kind ?? '') === 'combo'
                    ? 'Combo: '.$name
                    : ($variant !== '' ? $name.' — '.$variant : $name);
                fputcsv($file, [
                    (string) ($r->category_name ?? '—'),
                    $itemCol,
                    $r->qty_sold,
                    round((float) $r->revenue, 2),
                    $r->lines_sold,
                    $r->bills_count,
                ]);
            }
            fputcsv($file, [
                'TOTAL',
                $summary['sku_rows'].' SKUs',
                $summary['qty_sold'],
                $summary['revenue'],
                '',
                $summary['bills_with_sales'].' distinct bills',
            ]);
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Tax / GST summary: taxable value and tax by slab (matches POS bill math incl. discount & inclusive/exclusive prices).
     * Excludes complimentary bills. Paid + refunded on business_date.
     */
    public function taxGstSummaryReport(Request $request)
    {
        $this->checkPermission('report-tax-gst-summary');

        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'restaurant_id' => 'nullable|integer|exists:restaurant_masters,id',
        ]);

        $user = auth()->user();
        $from = $validated['from'];
        $to = $validated['to'];
        $restaurantId = $validated['restaurant_id'] ?? null;

        if ($restaurantId) {
            $this->authorizeRestaurantId((int) $restaurantId);
        }

        $allowedOutletIds = null;
        if (! $restaurantId && $user && ! $user->hasRole('Admin') && ! $user->hasRole('Super Admin')) {
            $assigned = $user->restaurants()->pluck('restaurant_masters.id')->map(fn ($id) => (int) $id)->all();
            if (count($assigned) > 0) {
                $allowedOutletIds = $assigned;
            } else {
                $deptIds = $user->departments()->pluck('departments.id')->map(fn ($id) => (int) $id)->all();
                if (count($deptIds) > 0) {
                    $allowedOutletIds = RestaurantMaster::where('is_active', true)
                        ->where(function ($q) use ($deptIds) {
                            $q->whereIn('department_id', $deptIds)->orWhereNull('department_id');
                        })
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                } else {
                    $allowedOutletIds = [];
                }
            }
        }

        if (! $restaurantId) {
            if ($user && ($user->hasRole('Admin') || $user->hasRole('Super Admin'))) {
                $restaurantId = RestaurantMaster::where('is_active', true)->first()?->id;
            } elseif (is_array($allowedOutletIds) && count($allowedOutletIds) > 0) {
                $restaurantId = $allowedOutletIds[0];
            }
        }

        if (! $restaurantId) {
            return response()->json([
                'summary' => [
                    'taxable_value' => 0.0,
                    'tax_amount' => 0.0,
                    'bills_count' => 0,
                    'bucket_count' => 0,
                ],
                'data' => [],
            ]);
        }

        $payload = $this->buildTaxGstSummaryData((int) $restaurantId, $from, $to);

        return response()->json([
            'summary' => $payload['totals'],
            'data' => $payload['by_rate'],
        ]);
    }

    public function taxGstSummaryExport(Request $request)
    {
        $this->checkPermission('report-tax-gst-summary');

        $restaurantId = $request->query('restaurant_id');
        $from = $request->query('from') ?? now()->toDateString();
        $to = $request->query('to') ?? now()->toDateString();
        $type = $request->query('type', 'csv');

        if (! $restaurantId || ! $this->userCanAccessRestaurant((int) $restaurantId)) {
            abort(403, 'Unauthorized access to this outlet.');
        }

        $restaurant = RestaurantMaster::findOrFail((int) $restaurantId);
        $payload = $this->buildTaxGstSummaryData((int) $restaurantId, $from, $to);

        if ($type === 'pdf') {
            $pdf = Pdf::loadView('reports.tax_gst_summary', [
                'restaurant' => $restaurant,
                'from' => $from,
                'to' => $to,
                'rows' => $payload['by_rate'],
                'totals' => $payload['totals'],
            ]);

            return $pdf->download("tax_gst_summary_{$from}_to_{$to}.pdf");
        }

        $fileName = "tax_gst_summary_{$from}_to_{$to}.csv";
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename={$fileName}",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($payload) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Rate %', 'Tax label', 'Taxable value', 'Tax amount', 'POS lines']);
            foreach ($payload['by_rate'] as $row) {
                fputcsv($file, [
                    $row['rate'],
                    $row['tax_label'],
                    $row['taxable_value'],
                    $row['tax_amount'],
                    $row['line_count'],
                ]);
            }
            fputcsv($file, [
                '',
                'TOTAL',
                $payload['totals']['taxable_value'],
                $payload['totals']['tax_amount'],
                '',
            ]);
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export Sales Report (CSV/PDF)
     */
    public function salesReportExport(Request $request)
    {
        $this->checkPermission('report-sales');

        $restaurantId = $request->query('restaurant_id');
        $from = $request->query('from') ?? now()->toDateString();
        $to = $request->query('to') ?? now()->toDateString();
        $type = $request->query('type', 'csv');

        if (! $restaurantId || ! $this->userCanAccessRestaurant((int)$restaurantId)) {
            abort(403, 'Unauthorized access to this outlet.');
        }

        $restaurant = RestaurantMaster::findOrFail($restaurantId);

        $orders = PosOrder::whereIn('status', ['paid', 'refunded', 'void'])
            ->where('restaurant_id', (int)$restaurantId)
            ->where(function($q) use ($from, $to) {
                $q->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)
                  ->orWhere(function($sq) use ($from, $to) {
                      $sq->where('status', 'void')->whereDate('voided_at', '>=', $from)->whereDate('voided_at', '<=', $to);
                  });
            })
            ->with(['waiter:id,name', 'refunds', 'restaurant:id,name', 'payments'])
            ->orderBy('id', 'desc')
            ->get();

        if ($type === 'pdf') {
            $nonVoidOrders = $orders->where('status', '!=', 'void');
            $summary = [
                'count' => $orders->count(),
                'net' => $nonVoidOrders->sum('total_amount'),
                'refunds' => $orders->map(fn ($o) => $o->refunds->sum('amount'))->sum(),
                'gst_duty' => (float) $nonVoidOrders->sum(fn ($o) => (float) ($o->cgst_amount ?? 0) + (float) ($o->sgst_amount ?? 0) + (float) ($o->igst_amount ?? 0)),
                'vat_tax' => (float) $nonVoidOrders->sum(fn ($o) => (float) ($o->vat_tax_amount ?? 0)),
                'gst_net_taxable' => (float) $nonVoidOrders->sum(fn ($o) => (float) ($o->gst_net_taxable ?? 0)),
                'vat_net_taxable' => (float) $nonVoidOrders->sum(fn ($o) => (float) ($o->vat_net_taxable ?? 0)),
            ];
            $pdf = Pdf::loadView('reports.sales', [
                'orders' => $orders,
                'restaurant' => $restaurant,
                'from' => $from,
                'to' => $to,
                'summary' => $summary,
            ])->setPaper('a4', 'landscape');

            return $pdf->download("sales_report_{$from}_to_{$to}.pdf");
        }

        // Default: CSV (also serves as Excel)
        $fileName = "sales_report_{$from}_to_{$to}.csv";
        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$fileName",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $columns = [
            'Bill #', 'Date', 'Outlet', 'Customer Name', 'GSTIN', 'Status',
            'Gross / Subtotal', 'Discount', 'Tax', 'CGST', 'SGST', 'IGST', 'Liquor VAT',
            'GST Taxable', 'VAT Taxable', 'Srv Chg', 'Tips',
            'Net Total', 'Refunded', 'Payment Method', 'Staff', 'Order Type',
        ];

        $callback = function() use($orders, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            foreach ($orders as $o) {
                $paymentModes = $o->payments->pluck('method')->map(function($m) {
                    return ucfirst($m);
                })->implode(' + ');

                if (empty($paymentModes)) {
                    $paymentModes = $o->status === 'void' ? '—' : 'Missing';
                }

                fputcsv($file, [
                    '#' . $o->id,
                    $o->business_date . ' ' . ($o->closed_at ?: $o->voided_at),
                    $o->restaurant?->name ?? '—',
                    $o->customer_name ?? '—',
                    $o->customer_gstin ?? '—',
                    $o->status,
                    $o->subtotal,
                    $o->discount_amount,
                    $o->tax_amount,
                    $o->cgst_amount ?? 0,
                    $o->sgst_amount ?? 0,
                    $o->igst_amount ?? 0,
                    $o->vat_tax_amount ?? 0,
                    $o->gst_net_taxable ?? 0,
                    $o->vat_net_taxable ?? 0,
                    $o->service_charge_amount,
                    $o->tip_amount,
                    $o->total_amount,
                    $o->refunds->sum('amount'),
                    $paymentModes,
                    $o->waiter?->name ?? '—',
                    $o->order_type ?? 'dine_in',
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function b2bSalesReportExport(Request $request)
    {
        $this->checkPermission('report-b2b-sales');
        $restaurantId = $request->query('restaurant_id');
        $from = $request->query('from') ?? now()->toDateString();
        $to = $request->query('to') ?? now()->toDateString();

        $query = PosOrder::whereIn('status', ['paid', 'refunded'])
            ->whereNotNull('customer_gstin')
            ->where('customer_gstin', '!=', '')
            ->whereDate('business_date', '>=', $from)
            ->whereDate('business_date', '<=', $to)
            ->with(['restaurant:id,name']);

        if ($restaurantId) {
            $this->authorizeRestaurantId((int)$restaurantId);
            $query->where('restaurant_id', $restaurantId);
        }

        $orders = $query->orderBy('id', 'desc')->get();

        $fileName = "b2b_sales_report_{$from}_to_{$to}.csv";
        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$fileName",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $columns = ['Bill #', 'Date', 'Customer', 'GSTIN', 'Outlet', 'GST Taxable', 'VAT Taxable', 'CGST', 'SGST', 'IGST', 'Liquor VAT', 'Total Tax', 'Total Amount'];

        $callback = function() use($orders, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);
            foreach ($orders as $o) {
                fputcsv($file, [
                    '#' . $o->id,
                    $o->business_date,
                    $o->customer_name ?? '—',
                    $o->customer_gstin ?? '—',
                    $o->restaurant?->name ?? '—',
                    round((float) ($o->gst_net_taxable ?? 0), 2),
                    round((float) ($o->vat_net_taxable ?? 0), 2),
                    round((float) ($o->cgst_amount ?? 0), 2),
                    round((float) ($o->sgst_amount ?? 0), 2),
                    round((float) ($o->igst_amount ?? 0), 2),
                    round((float) ($o->vat_tax_amount ?? 0), 2),
                    round((float) ($o->tax_amount ?? 0), 2),
                    round((float) ($o->total_amount ?? 0), 2),
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Get individual orders for a report drill-down audit
     */
    public function salesReportOrders(Request $request)
    {
        $this->checkPermission('report-sales');
        $date = $request->input('date');
        $restaurantId = $request->input('restaurant_id');

        if (! $date || ! $restaurantId) {
            return response()->json(['message' => 'date and restaurant_id are required.'], 422);
        }

        $orders = PosOrder::where('restaurant_id', $restaurantId)
            ->whereDate('business_date', $date)
            ->whereIn('status', ['paid', 'refunded', 'void'])
            ->with(['waiter:id,name', 'openedBy:id,name', 'voidedBy:id,name', 'refunds'])
            ->orderBy('closed_at', 'desc')
            ->get()
            ->map(function ($o) {
                return [
                    'id' => $o->id,
                    'customer_name' => $o->customer_name,
                    'waiter_name' => $o->waiter?->name ?? '—',
                    'opened_by' => $o->openedBy?->name ?? '—',
                    'closed_at' => $o->closed_at?->toDateTimeString(),
                    'voided_at' => $o->voided_at?->toDateTimeString(),
                    'voided_by' => $o->voidedBy?->name ?? '—',
                    'status' => $o->status,
                    'total_amount' => (float) $o->total_amount,
                    'tax_amount' => (float) $o->tax_amount,
                    'refunded_amount' => (float) $o->refunds->sum('amount'),
                    'refund_count' => $o->refunds->count(),
                ];
            });

        return response()->json($orders);
    }

    // ── Tables with live order status ─────────────────────────────────────────

    public function tables(Request $request)
    {
        $this->checkPermission('pos-order');
        $request->validate(['restaurant_id' => 'required|exists:restaurant_masters,id']);
        $this->authorizeRestaurantId((int) $request->restaurant_id);

        $tables = RestaurantTable::with(['category'])
            ->where('restaurant_master_id', $request->restaurant_id)
            ->where('status', '!=', 'inactive')
            ->get()
            ->map(function ($table) {
                $openOrder = PosOrder::where('table_id', $table->id)
                    ->whereIn('status', ['open', 'billed'])
                    ->with('items')
                    ->first();

                $readyBatches = $openOrder
                    ? $this->kotBatchesFromItems($openOrder->items->where('kot_sent', true)->where('status', 'active'), 'kitchen_ready_at')
                    : [];
                $servedBatches = $openOrder
                    ? $this->kotBatchesFromItems($openOrder->items->where('kot_sent', true)->where('status', 'active'), 'kitchen_served_at')
                    : [];
                $kotCounts = $openOrder ? $this->kotLineCounts($openOrder) : ['total' => 0, 'ready' => 0, 'served' => 0];

                return [
                    'id' => $table->id,
                    'table_number' => $table->table_number,
                    'capacity' => $table->capacity,
                    'status' => $table->status,
                    'location' => $table->location,
                    'category' => $table->category,
                    'open_order' => $openOrder ? [
                        'id' => $openOrder->id,
                        'status' => $openOrder->status,
                        'kitchen_status' => $openOrder->kitchen_status ?? 'pending',
                        'ready_batches' => $readyBatches,
                        'served_batches' => $servedBatches,
                        'kot_lines_total' => $kotCounts['total'],
                        'kot_lines_ready' => $kotCounts['ready'],
                        'kot_lines_served' => $kotCounts['served'],
                        'covers' => $openOrder->covers,
                        'item_count' => $openOrder->items->where('status', 'active')->sum('quantity'),
                        'total' => $openOrder->total_amount,
                        'opened_at' => $openOrder->opened_at,
                    ] : null,
                ];
            });

        return response()->json($tables);
    }

    // ── Menu for POS ──────────────────────────────────────────────────────────

    public function menu(Request $request)
    {
        $this->checkPermission('pos-order');
        $restaurantId = $request->input('restaurant_id');
        if ($restaurantId) {
            $this->authorizeRestaurantId((int) $restaurantId);
        }

        $restaurant = $restaurantId ? \App\Models\RestaurantMaster::find($restaurantId) : null;
        $locIds = $restaurant
            ? array_filter([$restaurant->kitchen_location_id, $restaurant->bar_location_id])
            : [];

        if (! empty($locIds)) {
            $physicalStock = DB::table('inventory_item_locations')
                ->whereIn('inventory_location_id', $locIds)
                ->select('inventory_item_id', DB::raw('SUM(quantity) as total'))
                ->groupBy('inventory_item_id')
                ->pluck('total', 'inventory_item_id')
                ->map(fn ($v) => (float) $v);
        } else {
            // Legacy fallback if no location mapped
            $physicalStock = DB::table('inventory_item_locations')
                ->select('inventory_item_id', DB::raw('SUM(quantity) as total'))
                ->groupBy('inventory_item_id')
                ->pluck('total', 'inventory_item_id')
                ->map(fn ($v) => (float) $v);
        }

        $reservedByItem = collect();
        $openProducts = DB::table('pos_order_items')
            ->join('pos_orders', 'pos_order_items.order_id', '=', 'pos_orders.id')
            ->join('restaurant_masters', 'pos_orders.restaurant_id', '=', 'restaurant_masters.id')
            ->leftJoin('menu_items', 'pos_order_items.menu_item_id', '=', 'menu_items.id')
            ->leftJoin('menu_item_variants', 'pos_order_items.menu_item_variant_id', '=', 'menu_item_variants.id')
            ->whereIn('pos_orders.status', ['open', 'billed'])
            ->where('pos_order_items.status', 'active')
            ->where('pos_order_items.inventory_deducted', false)
            ->whereNotNull('menu_items.inventory_item_id')
            ->where(function ($q) use ($locIds) {
                if (! empty($locIds)) {
                    $q->whereIn('restaurant_masters.kitchen_location_id', $locIds)
                        ->orWhereIn('restaurant_masters.bar_location_id', $locIds);
                }
            })
            ->select('menu_items.inventory_item_id', 'pos_order_items.quantity', 'menu_item_variants.ml_quantity')
            ->get();

        foreach ($openProducts as $oi) {
            $qty = (float) $oi->quantity;
            if ((float) $oi->ml_quantity > 0) {
                $qty *= (float) $oi->ml_quantity;
            }
            $reservedByItem->put($oi->inventory_item_id, $reservedByItem->get($oi->inventory_item_id, 0) + $qty);
        }

        // ── Addition: EXPAND COMBOS from Reserved Orders ──
        $openCombos = DB::table('pos_order_items')
            ->join('pos_orders', 'pos_order_items.order_id', '=', 'pos_orders.id')
            ->join('restaurant_masters', 'pos_orders.restaurant_id', '=', 'restaurant_masters.id')
            ->join('combo_items', 'pos_order_items.combo_id', '=', 'combo_items.combo_id')
            ->join('menu_items', 'combo_items.menu_item_id', '=', 'menu_items.id')
            ->whereIn('pos_orders.status', ['open', 'billed'])
            ->where('pos_order_items.status', 'active')
            ->where('pos_order_items.inventory_deducted', false)
            ->whereNotNull('menu_items.inventory_item_id')
            ->where(function ($q) use ($locIds) {
                if (! empty($locIds)) {
                    $q->whereIn('restaurant_masters.kitchen_location_id', $locIds)
                        ->orWhereIn('restaurant_masters.bar_location_id', $locIds);
                }
            })
            ->select('menu_items.inventory_item_id', 'pos_order_items.quantity')
            ->get();

        foreach ($openCombos as $oc) {
            $reservedByItem->put($oc->inventory_item_id, $reservedByItem->get($oc->inventory_item_id, 0) + (float) $oc->quantity);
        }

        // Subtract reserved from physical to get truly available stock
        foreach ($reservedByItem as $itemId => $resQty) {
            if ($physicalStock->has($itemId)) {
                $physicalStock->put($itemId, max(0, $physicalStock[$itemId] - $resQty));
            }
        }

        // Kitchen-only stock for made-to-order ingredient availability.
        // reservedByItem tracks finished-good inventory_item_ids; do NOT subtract those
        // from raw ingredient stock — they are different items on different shelves.
        $kitchenStock = collect();
        $kitchenLocationId = $restaurant?->kitchen_location_id;
        if ($kitchenLocationId) {
            $kitchenStock = DB::table('inventory_item_locations')
                ->where('inventory_location_id', $kitchenLocationId)
                ->pluck('quantity', 'inventory_item_id')
                ->map(fn ($v) => (float) $v);
        }

        // When restaurant_id provided: filter by restaurant_menu_items, use per-restaurant price
        // When not provided: legacy mode — all items, use menu_items.price
        if ($restaurantId) {
            $rmiByItem = RestaurantMenuItem::where('restaurant_master_id', $restaurantId)
                ->where('is_active', true)
                ->get()
                ->keyBy('menu_item_id');

            $categories = MenuCategory::where('is_active', true)->with(['items' => function ($q) use ($rmiByItem) {
                $q->with(['tax', 'variants'])->where('menu_items.is_active', true)
                    ->whereIn('menu_items.id', $rmiByItem->keys()->toArray())
                    ->orderBy('name');
            }])->get()->filter(fn ($c) => $c->items->isNotEmpty())->values();

            $rviByRmiAndVariant = RestaurantMenuItemVariant::whereIn('restaurant_menu_item_id', $rmiByItem->values()->pluck('id'))
                ->get()
                ->keyBy(fn ($rvi) => $rvi->restaurant_menu_item_id.'_'.$rvi->menu_item_variant_id);

            // Made-to-order Sold Out: items without inventory_item_id but with recipe (requires_production=false)
            $madeToOrderSoldOut = collect();
            // Use kitchen_location_id, not kitchenStock->isNotEmpty(): when the kitchen has no
            // inventory_item_locations rows yet (or all ingredients are at 0 with no rows), the map
            // is empty but we must still treat recipe ingredients as 0 available → sold out.
            if ($kitchenLocationId) {
                $noInvItemIds = MenuItem::whereIn('id', $rmiByItem->keys())
                    ->whereNull('inventory_item_id')
                    ->pluck('id')
                    ->toArray();
                $recipes = Recipe::whereIn('menu_item_id', $noInvItemIds)
                    ->where('requires_production', false)
                    ->where('is_active', true)
                    ->with('ingredients')
                    ->get();

                // Build a working copy of kitchen stock adjusted for ingredients already
                // committed by other open/billed orders at this kitchen (not yet deducted).
                $adjustedKitchenStock = $kitchenStock->toBase()->map(fn ($v) => (float) $v);

                if ($recipes->isNotEmpty()) {
                    $committedPortions = DB::table('pos_order_items')
                        ->join('pos_orders', 'pos_order_items.order_id', '=', 'pos_orders.id')
                        ->join('restaurant_masters', 'pos_orders.restaurant_id', '=', 'restaurant_masters.id')
                        ->whereIn('pos_orders.status', ['open', 'billed'])
                        ->where('pos_order_items.status', 'active')
                        ->where('pos_order_items.inventory_deducted', false)
                        ->whereIn('pos_order_items.menu_item_id', $noInvItemIds)
                        ->where('restaurant_masters.kitchen_location_id', $kitchenLocationId)
                        ->select('pos_order_items.menu_item_id', DB::raw('SUM(pos_order_items.quantity) as total'))
                        ->groupBy('pos_order_items.menu_item_id')
                        ->pluck('total', 'menu_item_id');

                    foreach ($recipes as $recipe) {
                        $portions = (float) $committedPortions->get($recipe->menu_item_id, 0);
                        if ($portions <= 0) {
                            continue;
                        }
                        $multiplier = $portions / max(0.001, (float) $recipe->yield_quantity);
                        foreach ($recipe->ingredients as $ing) {
                            $used = round((float) $ing->raw_quantity * $multiplier, 3);
                            $adjustedKitchenStock->put(
                                $ing->inventory_item_id,
                                max(0, $adjustedKitchenStock->get($ing->inventory_item_id, 0) - $used)
                            );
                        }
                    }
                }

                foreach ($recipes as $recipe) {
                    $multiplier = 1 / max(0.001, (float) $recipe->yield_quantity);
                    $ings = $recipe->ingredients;
                    if ($ings->isEmpty()) {
                        $madeToOrderSoldOut->put($recipe->menu_item_id, true);

                        continue;
                    }
                    $soldOut = false;
                    foreach ($ings as $ing) {
                        $needQty = (float) $ing->raw_quantity * $multiplier;
                        if ($adjustedKitchenStock->get($ing->inventory_item_id, 0) < $needQty) {
                            $soldOut = true;
                            break;
                        }
                    }
                    $madeToOrderSoldOut->put($recipe->menu_item_id, $soldOut);
                }
            }

            $kitchenStoreForMenu = $this->getKitchenLocationForRestaurant($restaurant);
            $barStoreForMenu = $this->getBarLocationForRestaurant($restaurant);

            $categories->each(function ($cat) use ($physicalStock, $rmiByItem, $rviByRmiAndVariant, $madeToOrderSoldOut, $reservedByItem, $kitchenStoreForMenu, $barStoreForMenu) {
                $cat->items->each(function ($item) use ($physicalStock, $rmiByItem, $rviByRmiAndVariant, $madeToOrderSoldOut, $reservedByItem, $kitchenStoreForMenu, $barStoreForMenu) {
                    $rmi = $rmiByItem->get($item->id);
                    if ($rmi) {
                        $item->price = (string) $rmi->price;
                        $item->price_tax_inclusive = (bool) ($rmi->price_tax_inclusive ?? true);
                    }
                    if ($item->variants && $item->variants->isNotEmpty()) {
                        $item->variants = $item->variants->map(function ($v) use ($rmi, $rviByRmiAndVariant) {
                            $price = (float) $v->price;
                            if ($rmi) {
                                $rvi = $rviByRmiAndVariant->get($rmi->id.'_'.$v->id);
                                if ($rvi) {
                                    $price = (float) $rvi->price;
                                }
                            }

                            return ['id' => $v->id, 'size_label' => $v->size_label, 'price' => (string) $price, 'ml_quantity' => (float) ($v->ml_quantity ?? 1)];
                        })->values();
                    } else {
                        $item->variants = [];
                    }
                    $item->requires_production = (bool) $item->requires_production;
                    if ($item->inventory_item_id) {
                        $stock = $physicalStock->get($item->inventory_item_id, 0);
                        $targetStore = $this->resolveInventoryDeductionStore($item, $kitchenStoreForMenu, $barStoreForMenu);
                        if ($targetStore) {
                            $phys = (float) (DB::table('inventory_item_locations')
                                ->where('inventory_location_id', $targetStore->id)
                                ->where('inventory_item_id', $item->inventory_item_id)
                                ->value('quantity') ?? 0);
                            $res = (float) ($reservedByItem->get($item->inventory_item_id, 0));
                            $item->available_qty = max(0, $phys - $res);
                        } else {
                            $item->available_qty = max(0, $stock);
                        }
                    } else {
                        $item->available_qty = $madeToOrderSoldOut->get($item->id, false) ? 0 : null;
                    }
                });
            });
        } else {
            $categories = MenuCategory::where('is_active', true)->with(['items' => function ($q) {
                $q->with(['tax', 'variants'])->where('is_active', true)->orderBy('name');
            }])->get()->filter(fn ($c) => $c->items->isNotEmpty())->values();

            $categories->each(function ($cat) use ($physicalStock) {
                $cat->items->each(function ($item) use ($physicalStock) {
                    if ($item->variants && $item->variants->isNotEmpty()) {
                        $item->variants = $item->variants->map(fn ($v) => ['id' => $v->id, 'size_label' => $v->size_label, 'price' => (string) $v->price, 'ml_quantity' => (float) ($v->ml_quantity ?? 1)])->values();
                    } else {
                        $item->variants = [];
                    }
                    $item->requires_production = (bool) $item->requires_production;
                    if ($item->inventory_item_id) {
                        $stock = $physicalStock->get($item->inventory_item_id, 0);
                        $item->available_qty = max(0, $stock);
                    } else {
                        $item->available_qty = null;
                    }
                });
            });
        }

        // Hide items that are not sellable (no positive outlet/base/variant price).
        $categories = $categories
            ->map(function ($cat) {
                $cat->items = collect($cat->items)->filter(function ($item) {
                    $variants = collect($item->variants ?? []);
                    if ($variants->isNotEmpty()) {
                        return $variants->contains(function ($v) {
                            $price = is_array($v) ? ($v['price'] ?? 0) : ($v->price ?? 0);

                            return (float) $price > 0;
                        });
                    }

                    return (float) ($item->price ?? 0) > 0;
                })->values();

                return $cat;
            })
            ->filter(fn ($c) => collect($c->items)->isNotEmpty())
            ->values();

        // Build menu item id => available_qty for combo availability
        $availableByMenuId = $categories->flatMap(fn ($cat) => collect($cat->items))
            ->filter(fn ($i) => is_object($i) && isset($i->id))
            ->mapWithKeys(fn ($i) => [$i->id => $i->available_qty ?? null]);

        // Append combos as a special category
        $restaurantCombosByComboId = $restaurantId
            ? RestaurantCombo::where('restaurant_master_id', $restaurantId)
                ->where('is_active', true)
                ->get()
                ->keyBy('combo_id')
            : collect();

        $combos = Combo::with('menuItems.tax')
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function ($c) use ($physicalStock, $restaurantCombosByComboId, $availableByMenuId, $restaurantId) {
                $availableQty = null;
                if ($c->menuItems->isNotEmpty()) {
                    $availables = [];
                    foreach ($c->menuItems as $mi) {
                        $qty = $availableByMenuId->get($mi->id);
                        if ($qty !== null) {
                            $availables[] = (float) $qty;
                        } elseif ($mi->inventory_item_id) {
                            $availables[] = max(0, $physicalStock->get($mi->inventory_item_id, 0));
                        }
                    }
                    if (! empty($availables)) {
                        $availableQty = (int) floor(min($availables));
                    }
                }
                $rcRow = $restaurantCombosByComboId->get($c->id);
                $price = $rcRow
                    ? (string) $rcRow->price
                    : (string) $c->price;

                if ((float) $price <= 0) {
                    return null;
                }

                $comboTaxRate = $restaurantId
                    ? $this->resolveComboTaxRate($c, (int) $restaurantId)
                    : 0;
                $comboTaxRegime = $restaurantId
                    ? $this->resolveComboTaxRegime($c, (int) $restaurantId)
                    : 'gst';
                $comboSupplyType = $comboTaxRegime === 'vat_liquor'
                    ? 'vat'
                    : ($this->comboHasInterstateGstComponent($c) ? 'inter-state' : 'local');

                return (object) [
                    'id' => $c->id,
                    'name' => $c->name,
                    'price' => $price,
                    'tax_rate' => $comboTaxRate,
                    'tax_regime' => $comboTaxRegime,
                    'tax_supply_type' => $comboSupplyType,
                    'price_tax_inclusive' => $rcRow ? (bool) ($rcRow->price_tax_inclusive ?? true) : true,
                    'type' => 'combo',
                    'item_code' => 'COMBO-'.$c->id,
                    'available_qty' => $availableQty,
                    'combo_id' => $c->id,
                    'menu_items' => $c->menuItems->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->toArray(),
                ];
            })->filter()->values();

        if ($combos->isNotEmpty()) {
            $categories = $categories->push((object) [
                'id' => 0,
                'name' => 'Combos',
                'items' => $combos->values()->all(),
            ]);
        }

        return response()->json($categories->values()->all());
    }

    // ── Open a new order ──────────────────────────────────────────────────────

    public function openOrder(Request $request)
    {
        $this->checkPermission('pos-order');
        $orderType = $request->input('order_type', 'dine_in');

        $rules = [
            'order_type' => 'nullable|in:dine_in,takeaway,room_service,delivery,walk_in',
            'restaurant_id' => 'required|exists:restaurant_masters,id',
            'covers' => 'required|integer|min:1',
            'customer_name' => 'nullable|string|max:191',
            'customer_phone' => 'nullable|string|max:30',
            'customer_gstin' => 'nullable|string|max:15',
            'tax_exempt' => 'nullable|boolean',
        ];

        if ($orderType === 'dine_in') {
            $rules['table_id'] = 'required|integer|exists:restaurant_tables,id';
        } elseif ($orderType === 'room_service') {
            $rules['room_id'] = 'required|integer|exists:rooms,id';
            $rules['booking_id'] = 'nullable|integer|exists:bookings,id';
        } elseif ($orderType === 'delivery') {
            $rules['delivery_address'] = 'required|string|max:500';
            $rules['delivery_channel'] = 'nullable|string|in:own_driver,swiggy,zomato,dunzo,other,magic_pin';
        }

        $validated = $request->validate($rules);
        $this->authorizeRestaurantId((int) $validated['restaurant_id']);

        if ($orderType === 'dine_in' && ! empty($validated['table_id'])) {
            $table = RestaurantTable::find($validated['table_id']);
            if (! $table || (int) $table->restaurant_master_id !== (int) $validated['restaurant_id']) {
                return response()->json(['message' => 'Table does not belong to this outlet.'], 422);
            }
        }

        $restaurant = RestaurantMaster::find($validated['restaurant_id']);
        $businessDate = BusinessDateService::resolve($restaurant);

        $duplicateOrder = null;
        $order = DB::transaction(function () use ($validated, $orderType, &$duplicateOrder, $businessDate, $restaurant) {
            if ($orderType === 'dine_in') {
                // Lock the table to prevent concurrent order creation
                RestaurantTable::where('id', $validated['table_id'])->lockForUpdate()->first();

                $existing = PosOrder::where('table_id', $validated['table_id'])
                    ->whereIn('status', ['open', 'billed'])
                    ->first();

                if ($existing) {
                    $duplicateOrder = $existing;

                    return null;
                }
            }

            if (PosDayClosing::where('restaurant_id', $validated['restaurant_id'])->where('closed_date', $businessDate)->exists()) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                    response()->json([
                        'message' => 'Cannot open new orders: this business date is already closed for this outlet.',
                    ], 422)
                );
            }

            $order = PosOrder::create([
                'order_type' => $orderType,
                'table_id' => $orderType === 'dine_in' ? $validated['table_id'] : null,
                'restaurant_id' => $validated['restaurant_id'],
                'business_date' => $businessDate,
                'room_id' => $validated['room_id'] ?? null,
                'booking_id' => $validated['booking_id'] ?? null,
                'customer_name' => $validated['customer_name'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'customer_gstin' => $validated['customer_gstin'] ?? null,
                'delivery_address' => $validated['delivery_address'] ?? null,
                'delivery_channel' => $validated['delivery_channel'] ?? null,
                'waiter_id' => auth()->id(),
                'opened_by' => auth()->id(),
                'tax_exempt' => (bool) ($validated['tax_exempt'] ?? false),
                'prices_tax_inclusive' => true,
                'receipt_show_tax_breakdown' => (bool) ($restaurant->receipt_show_tax_breakdown ?? true),
                'covers' => $validated['covers'],
                'status' => 'open',
                'opened_at' => now(),
            ]);

            if ($orderType === 'dine_in') {
                RestaurantTable::where('id', $validated['table_id'])
                    ->update(['status' => 'occupied']);
            }

            return $order;
        });

        if ($duplicateOrder) {
            $this->broadcastPosOutletUpdate((int) $duplicateOrder->restaurant_id, (int) $duplicateOrder->id);

            return response()->json($this->formatOrder($duplicateOrder->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'waiter', 'openedBy', 'voidedBy', 'discountApprovedBy')));
        }

        $this->broadcastPosOutletUpdate((int) $order->restaurant_id, (int) $order->id);

        return response()->json($this->formatOrder($order->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'waiter', 'openedBy', 'voidedBy', 'discountApprovedBy')), 201);
    }

    // ── Order history (paid orders for reprint) ─────────────────────────────────

    public function orderHistory(Request $request)
    {
        $this->checkPermission('pos-order');
        $validated = $request->validate([
            'restaurant_id' => 'required|exists:restaurant_masters,id',
            'order_id' => 'nullable|integer|min:1',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $this->authorizeRestaurantId((int) $validated['restaurant_id']);

        $query = PosOrder::with(['room', 'table', 'refunds'])
            ->where('restaurant_id', $validated['restaurant_id'])
            ->whereIn('status', ['paid', 'refunded'])
            ->orderByDesc('closed_at');

        if (! empty($validated['order_id'])) {
            $query->where('id', (int) $validated['order_id']);
        } else {
            if (! empty($validated['from'])) {
                $query->whereDate('closed_at', '>=', $validated['from']);
            }
            if (! empty($validated['to'])) {
                $query->whereDate('closed_at', '<=', $validated['to']);
            }
        }

        $perPage = (int) ($validated['per_page'] ?? 20);
        $paginated = $query->paginate($perPage);

        $orders = $paginated->getCollection()->map(fn ($o) => [
            'id' => $o->id,
            'order_type' => $o->order_type,
            'customer_name' => $o->customer_name,
            'room_number' => $o->room?->room_number,
            'table_number' => $o->table?->table_number,
            'total_amount' => (float) $o->total_amount,
            'discount_amount' => (float) ($o->discount_amount ?? 0),
            'is_complimentary' => (bool) ($o->is_complimentary ?? false),
            'refunded_amount' => (float) $o->refunds->sum('amount'),
            'status' => $o->status,
            'closed_at' => $o->closed_at,
        ]);

        return response()->json([
            'data' => $orders->values()->all(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
        ]);
    }

    // ── Get a single order ────────────────────────────────────────────────────

    public function getOrder(PosOrder $order)
    {
        $this->checkPermission('pos-order');
        $this->authorizeOrderAccess($order);

        return response()->json($this->formatOrder($order->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy', 'discountApprovedBy')));
    }

    // ── Update order details (customer, covers) ─────────────────────────────────

    public function updateOrder(Request $request, PosOrder $order)
    {
        $this->checkPermission('pos-order');
        $this->authorizeOrderAccess($order);
        if (! in_array($order->status, ['open', 'billed'])) {
            return response()->json(['message' => 'Order is not editable.'], 422);
        }
        if ($this->isBusinessDateClosedForOrder($order)) {
            return response()->json(['message' => 'Business date is already closed for this outlet.'], 422);
        }

        $rules = [
            'customer_name' => 'nullable|string|max:191',
            'customer_phone' => 'nullable|string|max:30',
            'customer_gstin' => 'nullable|string|max:15',
            'delivery_address' => 'nullable|string|max:500',
            'delivery_channel' => 'nullable|string|in:own_driver,swiggy,zomato,dunzo,other,magic_pin',
            'covers' => 'nullable|integer|min:1',
            'notes' => 'nullable|string|max:1000',
            'waiter_id' => 'nullable|exists:users,id',
            'tax_exempt' => 'nullable|boolean',
        ];
        $validated = $request->validate($rules);

        $updates = [];
        if (array_key_exists('customer_name', $validated)) {
            $updates['customer_name'] = $validated['customer_name'] ?: null;
        }
        if (array_key_exists('customer_phone', $validated)) {
            $updates['customer_phone'] = $validated['customer_phone'] ?: null;
        }
        if (array_key_exists('customer_gstin', $validated)) {
            $updates['customer_gstin'] = $validated['customer_gstin'] ?: null;
        }
        if (array_key_exists('delivery_address', $validated)) {
            $updates['delivery_address'] = $validated['delivery_address'] ?: null;
        }
        if (array_key_exists('delivery_channel', $validated)) {
            $updates['delivery_channel'] = $validated['delivery_channel'] ?: null;
        }
        if (array_key_exists('covers', $validated) && $validated['covers'] !== null) {
            $updates['covers'] = (int) $validated['covers'];
        }
        if (array_key_exists('notes', $validated)) {
            $updates['notes'] = $validated['notes'] ? trim($validated['notes']) : null;
        }
        if (array_key_exists('waiter_id', $validated)) {
            $updates['waiter_id'] = $validated['waiter_id'] ?: null;
        }
        if (array_key_exists('tax_exempt', $validated)) {
            $updates['tax_exempt'] = (bool) $validated['tax_exempt'];
        }

        if (empty($updates)) {
            return response()->json($this->formatOrder($order->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy', 'discountApprovedBy')));
        }

        $order->update($updates);

        if (array_key_exists('tax_exempt', $updates)) {
            $this->recalculate($order);
            $order->refresh();
        }

        $this->broadcastPosOutletUpdate((int) $order->restaurant_id, (int) $order->id);

        return response()->json($this->formatOrder($order->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy', 'discountApprovedBy')));
    }

    // ── Transfer order to another table (dine-in only) ────────────────────────

    public function transferTable(Request $request, PosOrder $order)
    {
        $this->checkPermission('pos-order');
        $this->authorizeOrderAccess($order);
        if ($order->order_type !== 'dine_in') {
            return response()->json(['message' => 'Only dine-in orders can be transferred.'], 422);
        }
        if (! in_array($order->status, ['open', 'billed'])) {
            return response()->json(['message' => 'Order is not transferable.'], 422);
        }

        $validated = $request->validate([
            'table_id' => 'required|exists:restaurant_tables,id',
        ]);
        $newTableId = (int) $validated['table_id'];

        if ($newTableId === $order->table_id) {
            return response()->json(['message' => 'Order is already at this table.'], 422);
        }

        if ($this->isBusinessDateClosedForOrder($order)) {
            return response()->json(['message' => 'Business date is already closed for this outlet.'], 422);
        }

        $newTable = RestaurantTable::find($newTableId);
        if (! $newTable || (int) $newTable->restaurant_master_id !== (int) $order->restaurant_id) {
            return response()->json(['message' => 'Target table must be in the same restaurant.'], 422);
        }
        if ($newTable->status === 'inactive') {
            return response()->json(['message' => 'Cannot transfer to an inactive table.'], 422);
        }

        $errorResponse = null;
        DB::transaction(function () use ($order, $newTableId, &$errorResponse) {
            $order = PosOrder::where('id', $order->id)->lockForUpdate()->first();
            if (! in_array($order->status, ['open', 'billed'])) {
                $errorResponse = response()->json(['message' => 'Order is no longer transferable.'], 422);
                return;
            }

            RestaurantTable::where('id', $newTableId)->lockForUpdate()->first();
            if ($order->table_id) {
                RestaurantTable::where('id', $order->table_id)->lockForUpdate()->first();
            }

            $existingOrder = PosOrder::where('table_id', $newTableId)
                ->whereIn('status', ['open', 'billed'])
                ->where('id', '!=', $order->id)
                ->first();

            if ($existingOrder) {
                $errorResponse = response()->json(['message' => 'Target table already has an active order.'], 422);

                return;
            }

            if ($order->table_id) {
                RestaurantTable::where('id', $order->table_id)->update(['status' => 'available']);
            }
            $order->update(['table_id' => $newTableId]);
            RestaurantTable::where('id', $newTableId)->update(['status' => 'occupied']);
        });

        if ($errorResponse) {
            return $errorResponse;
        }

        $order = PosOrder::where('id', $order->id)->first();
        if (! $order) {
            return response()->json(['message' => 'Order not found after transfer.'], 404);
        }

        $this->broadcastPosOutletUpdate((int) $order->restaurant_id, (int) $order->id);

        return response()->json($this->formatOrder($order->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy', 'discountApprovedBy')));
    }

    // ── Sync order items (replace all) ────────────────────────────────────────

    public function syncItems(Request $request, PosOrder $order)
    {
        $this->checkPermission('pos-order');
        $this->authorizeOrderAccess($order);
        if ($order->status !== 'open') {
            return response()->json(['message' => 'Order is billed. Re-open to add or edit items.'], 422);
        }
        if ($this->isBusinessDateClosedForOrder($order)) {
            return response()->json(['message' => 'Business date is already closed for this outlet.'], 422);
        }

        $validated = $request->validate([
            'items' => 'present|array',
            'items.*.menu_item_id' => 'nullable|exists:menu_items,id',
            'items.*.menu_item_variant_id' => 'nullable|exists:menu_item_variants,id',
            'items.*.combo_id' => 'nullable|exists:combos,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string',
            'last_updated_at' => 'nullable|string',
        ]);

        foreach ($validated['items'] as $i => $row) {
            $hasMenu = array_key_exists('menu_item_id', $row) && $row['menu_item_id'] !== null && $row['menu_item_id'] !== '';
            $hasCombo = array_key_exists('combo_id', $row) && $row['combo_id'] !== null && $row['combo_id'] !== '';
            if ($hasMenu === $hasCombo) {
                return response()->json([
                    'message' => "Item at index {$i} must have exactly one of menu_item_id or combo_id.",
                ], 422);
            }
            if ($hasMenu && ! empty($row['menu_item_variant_id'])) {
                $variant = \App\Models\MenuItemVariant::where('id', $row['menu_item_variant_id'])
                    ->where('menu_item_id', $row['menu_item_id'])
                    ->first();
                if (! $variant) {
                    return response()->json([
                        'message' => "Item at index {$i}: variant does not belong to this menu item.",
                    ], 422);
                }
            }
        }

        DB::transaction(function () use ($order, $validated) {
            // Lock the order to serialize all cart synchronization requests
            $order = PosOrder::where('id', $order->id)->lockForUpdate()->first();

            if (! empty($validated['last_updated_at'])) {
                $remoteUpdated = $order->updated_at->toIso8601String();
                if ($remoteUpdated !== $validated['last_updated_at']) {
                    throw new \Illuminate\Http\Exceptions\HttpResponseException(
                        response()->json([
                            'message' => 'This order was modified by another terminal. Please reload to see the latest changes.',
                            'order' => $this->formatOrder($order->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy', 'discountApprovedBy')),
                        ], 409)
                    );
                }
            }

            // ── Availability check: prevent overselling produced items (INSIDE transaction) ──
            $produced = DB::table('recipes')
                ->leftJoin('production_logs', 'recipes.id', '=', 'production_logs.recipe_id')
                ->where('recipes.is_active', true)
                ->where('recipes.requires_production', true)
                ->select('recipes.menu_item_id', DB::raw('COALESCE(SUM(production_logs.quantity_produced), 0) as total'))
                ->groupBy('recipes.menu_item_id')
                ->pluck('total', 'menu_item_id')
                ->map(fn ($v) => (float) $v);

            $soldExcludingThis = DB::table('pos_order_items')
                ->join('pos_orders', 'pos_order_items.order_id', '=', 'pos_orders.id')
                ->where('pos_orders.status', '!=', 'void')
                ->where('pos_orders.status', '!=', 'refunded')
                ->where('pos_order_items.order_id', '!=', $order->id)
                ->where('pos_order_items.status', 'active')
                ->where('pos_order_items.inventory_deducted', false) // Only count what's NOT yet subtracted from physical stock
                ->whereNotNull('pos_order_items.menu_item_id')
                ->select('pos_order_items.menu_item_id', DB::raw('SUM(pos_order_items.quantity) as total'))
                ->groupBy('pos_order_items.menu_item_id')
                ->pluck('total', 'menu_item_id')
                ->map(fn ($v) => (float) $v);

            // Add sold from combo items (constituent menu items)
            $comboSold = DB::table('pos_order_items')
                ->join('pos_orders', 'pos_order_items.order_id', '=', 'pos_orders.id')
                ->join('combo_items', 'combo_items.combo_id', '=', 'pos_order_items.combo_id')
                ->where('pos_orders.status', '!=', 'void')
                ->where('pos_orders.status', '!=', 'refunded')
                ->where('pos_order_items.order_id', '!=', $order->id)
                ->where('pos_order_items.status', 'active')
                ->whereNotNull('pos_order_items.combo_id')
                ->select('combo_items.menu_item_id', DB::raw('SUM(pos_order_items.quantity) as total'))
                ->groupBy('combo_items.menu_item_id')
                ->pluck('total', 'menu_item_id')
                ->map(fn ($v) => (float) $v);

            foreach ($comboSold as $mid => $cnt) {
                $soldExcludingThis->put($mid, ($soldExcludingThis->get($mid, 0) + $cnt));
            }

            // Expand combos to constituent items for availability check
            $incomingByItem = collect();
            foreach ($validated['items'] as $row) {
                if (array_key_exists('menu_item_id', $row) && $row['menu_item_id'] !== null && $row['menu_item_id'] !== '') {
                    $incomingByItem->put($row['menu_item_id'], $incomingByItem->get($row['menu_item_id'], 0) + (int) $row['quantity']);
                } elseif (array_key_exists('combo_id', $row) && $row['combo_id'] !== null && $row['combo_id'] !== '') {
                    $combo = \App\Models\Combo::with('menuItems')->find($row['combo_id']);
                    if ($combo && $combo->menuItems->isNotEmpty()) {
                        $qty = (int) $row['quantity'];
                        foreach ($combo->menuItems as $mi) {
                            $incomingByItem->put($mi->id, $incomingByItem->get($mi->id, 0) + $qty);
                        }
                    }
                }
            }

            // Pre-identify made-to-order menu items: requires_production=true on menu item (KOT)
            // but recipe is ingredient-based (recipe.requires_production=false).
            // One query before the loop to avoid N+1.
            $mtoMenuItemIds = \App\Models\Recipe::where('is_active', true)
                ->where('requires_production', false)
                ->whereIn('menu_item_id', $incomingByItem->keys()->toArray())
                ->pluck('menu_item_id')
                ->flip();

            foreach ($incomingByItem as $menuItemId => $incomingQty) {
                $item = \App\Models\MenuItem::find($menuItemId);
                if (! $item) {
                    continue;
                }

                if ($item->inventory_item_id) {
                    // Availability = Physical Stock - Reserved(not deducted) — single source of truth
                    $locIds = $order->restaurant_id ? array_filter([$order->restaurant->kitchen_location_id, $order->restaurant->bar_location_id]) : [];
                    $physical = $locIds ? (float) (DB::table('inventory_item_locations')
                        ->whereIn('inventory_location_id', $locIds)
                        ->where('inventory_item_id', $item->inventory_item_id)
                        ->sum('quantity') ?? 0) : 0;

                    $reservedItemQty = DB::table('pos_order_items')
                        ->join('pos_orders', 'pos_order_items.order_id', '=', 'pos_orders.id')
                        ->join('menu_items', 'pos_order_items.menu_item_id', '=', 'menu_items.id')
                        ->leftJoin('menu_item_variants', 'pos_order_items.menu_item_variant_id', '=', 'menu_item_variants.id')
                        ->whereIn('pos_orders.status', ['open', 'billed'])
                        ->where('pos_order_items.status', 'active')
                        ->where('pos_order_items.inventory_deducted', false)
                        ->where('pos_order_items.order_id', '!=', $order->id)
                        ->where('menu_items.inventory_item_id', $item->inventory_item_id)
                        ->select(DB::raw('SUM(pos_order_items.quantity * COALESCE(menu_item_variants.ml_quantity, 1)) as total'))
                        ->value('total') ?? 0;

                    $reservedComboQty = DB::table('pos_order_items')
                        ->join('pos_orders', 'pos_order_items.order_id', '=', 'pos_orders.id')
                        ->join('combo_items', 'pos_order_items.combo_id', '=', 'combo_items.combo_id')
                        ->join('menu_items', 'combo_items.menu_item_id', '=', 'menu_items.id')
                        ->whereIn('pos_orders.status', ['open', 'billed'])
                        ->where('pos_order_items.status', 'active')
                        ->where('pos_order_items.inventory_deducted', false)
                        ->where('pos_order_items.order_id', '!=', $order->id)
                        ->where('menu_items.inventory_item_id', $item->inventory_item_id)
                        ->sum('pos_order_items.quantity') ?? 0;

                    $available = max(0, (float) $physical - ((float) $reservedItemQty + (float) $reservedComboQty));
                } elseif ($mtoMenuItemIds->has($menuItemId)) {
                    // Type C: made-to-order (KOT item whose recipe is ingredient-based).
                    // menu_item.requires_production=true sends it to KOT; recipe.requires_production=false
                    // means we deduct raw ingredients, not a finished-good SKU.
                    $mockItems = [(object) ['menu_item_id' => $menuItemId, 'quantity' => $incomingQty, 'combo_id' => null, 'menu_item_variant_id' => null, 'variant' => null]];
                    $insufficientIngredients = $this->checkMadeToOrderStock($order, $mockItems);

                    if (! empty($insufficientIngredients)) {
                        $err = $insufficientIngredients[0];
                        throw new \Illuminate\Http\Exceptions\HttpResponseException(
                            response()->json([
                                'message' => "Insufficient ingredients for \"{$err['menu_item']}\". Short of \"{$err['ingredient']}\" ({$err['available']} {$err['uom']} available, needs {$err['required']} {$err['uom']}).",
                            ], 422)
                        );
                    }

                    continue;
                } elseif ($item->requires_production) {
                    // Legacy batch-produced items tracked only by production logs (no inventory_item_id).
                    // If no production logs exist, available = 0 which correctly blocks the sale.
                    $available = max(0, ($produced->get($menuItemId, 0)) - ($soldExcludingThis->get($menuItemId, 0)));
                } else {
                    // menu_item.requires_production=false + no inventory_item_id + MTO recipe:
                    // item goes to bar/direct path but still needs ingredient check.
                    $mockItems = [(object) ['menu_item_id' => $menuItemId, 'quantity' => $incomingQty, 'combo_id' => null, 'menu_item_variant_id' => null, 'variant' => null]];
                    $insufficientIngredients = $this->checkMadeToOrderStock($order, $mockItems);

                    if (! empty($insufficientIngredients)) {
                        $err = $insufficientIngredients[0];
                        throw new \Illuminate\Http\Exceptions\HttpResponseException(
                            response()->json([
                                'message' => "Insufficient ingredients for \"{$err['menu_item']}\". Short of \"{$err['ingredient']}\" ({$err['available']} {$err['uom']} available, needs {$err['required']} {$err['uom']}).",
                            ], 422)
                        );
                    }

                    continue;
                }

                if ($incomingQty > $available + 0.001) {
                    $name = $item->name;
                    throw new \Illuminate\Http\Exceptions\HttpResponseException(
                        response()->json([
                            'message' => "Insufficient stock for \"{$name}\". Only {$available} available, requested {$incomingQty}.",
                        ], 422)
                    );
                }
            }

            $currentActive = $order->items()->where('status', 'active')->get();
            $comboTaxCache = [];

            $key = fn ($row) => (array_key_exists('combo_id', $row) && $row['combo_id'] !== null && $row['combo_id'] !== '')
                ? 'c_'.$row['combo_id'].'|'.trim($row['notes'] ?? '')
                : 'm_'.$row['menu_item_id'].'_v_'.($row['menu_item_variant_id'] ?? '0').'|'.trim($row['notes'] ?? '');
            $incomingByKey = collect($validated['items'])
                ->filter(fn ($row) => (array_key_exists('combo_id', $row) && $row['combo_id'] !== null && $row['combo_id'] !== '')
                    || (array_key_exists('menu_item_id', $row) && $row['menu_item_id'] !== null && $row['menu_item_id'] !== ''))
                ->mapToGroups(function ($row) use ($key) {
                    return [$key($row) => $row];
                })->map(fn ($rows) => [
                    'menu_item_id' => $rows->first()['menu_item_id'] ?? null,
                    'menu_item_variant_id' => $rows->first()['menu_item_variant_id'] ?? null,
                    'combo_id' => $rows->first()['combo_id'] ?? null,
                    'quantity' => $rows->sum('quantity'),
                    'notes' => $rows->first()['notes'] ?? null,
                ]);

            // ── Step 1: Cancel/remove items no longer in cart ───────────────────
            foreach ($currentActive as $item) {
                $k = $item->combo_id ? 'c_'.$item->combo_id.'|'.trim($item->notes ?? '') : 'm_'.$item->menu_item_id.'_v_'.($item->menu_item_variant_id ?? '0').'|'.trim($item->notes ?? '');
                if (! $incomingByKey->has($k)) {
                    $kitchenStore = $this->getKitchenForOrder($order);
                    $barLocationId = $order->restaurant?->bar_location_id;
                    $barStore = $barLocationId ? InventoryLocation::find($barLocationId) : InventoryLocation::query()->where('type', 'bar_store')->where('department_id', $order->restaurant?->department_id)->first();
                    $targetStore = $this->resolveInventoryDeductionStore($item->menuItem, $kitchenStore, $barStore);

                    if ($item->inventory_deducted && $targetStore) {
                        $this->reverseOrderItemInventory($item, $targetStore, 'pos_order_sync_cancel', (string) $order->id);
                    }

                    if ($item->kot_sent) {
                        $item->update(['status' => 'cancelled']);
                    } else {
                        $item->delete();
                    }
                }
            }

            $currentActive = $order->items()->where('status', 'active')->get();

            // ── Step 2: Sync each incoming line ─────────────────────────────────
            foreach ($incomingByKey as $row) {
                $notes = $row['notes'] ?? null;
                $qty = (int) $row['quantity'];

                if (array_key_exists('combo_id', $row) && $row['combo_id'] !== null && $row['combo_id'] !== '') {
                    // Combo item
                    $combo = Combo::find($row['combo_id']);
                    if (! $combo) {
                        continue;
                    } // skip deleted combos
                    $rc = RestaurantCombo::where('combo_id', $row['combo_id'])
                        ->where('restaurant_master_id', $order->restaurant_id)
                        ->where('is_active', true)
                        ->first();
                    $priceTaxInclusive = $rc
                        ? (bool) ($rc->price_tax_inclusive ?? true)
                        : (bool) ($order->prices_tax_inclusive ?? true);
                    $unitPrice = $rc ? floatval($rc->price) : floatval($combo->price);
                    if ($unitPrice <= 0) {
                        throw new \Illuminate\Http\Exceptions\HttpResponseException(
                            response()->json([
                                'message' => "Combo \"{$combo->name}\" has no valid sell price for this outlet. Set pricing under Menu Pricing.",
                            ], 422)
                        );
                    }
                    if (! array_key_exists((int) $combo->id, $comboTaxCache)) {
                        $comboTaxCache[(int) $combo->id] = $this->resolveComboTaxRate($combo, (int) $order->restaurant_id);
                    }
                    $taxRate = (float) $comboTaxCache[(int) $combo->id];
                    $taxRegime = $this->resolveComboTaxRegime($combo, (int) $order->restaurant_id);
                    $matching = $currentActive->filter(
                        fn ($i) => $i->combo_id == $row['combo_id']
                            && trim($i->notes ?? '') === trim($notes ?? '')
                    );
                } else {
                    // Regular menu item (with optional variant)
                    $menuItem = MenuItem::with('tax')->find($row['menu_item_id']);
                    if (! $menuItem) {
                        continue;
                    }
                    $variantId = $row['menu_item_variant_id'] ?? null;
                    $rmi = RestaurantMenuItem::where('menu_item_id', $menuItem->id)
                        ->where('restaurant_master_id', $order->restaurant_id)
                        ->where('is_active', true)
                        ->first();
                    if (! $rmi) {
                        throw new \Illuminate\Http\Exceptions\HttpResponseException(
                            response()->json([
                                'message' => "Item \"{$menuItem->name}\" is not available at this outlet.",
                            ], 422)
                        );
                    }
                    if ($variantId) {
                        $variant = \App\Models\MenuItemVariant::find($variantId);
                        $unitPrice = (float) ($variant?->price ?? 0);
                        if ($variant) {
                            $rvi = RestaurantMenuItemVariant::where('restaurant_menu_item_id', $rmi->id)
                                ->where('menu_item_variant_id', $variant->id)
                                ->first();
                            if ($rvi) {
                                $unitPrice = (float) $rvi->price;
                            }
                        }
                    } else {
                        $unitPrice = floatval($rmi->price);
                    }
                    if ($unitPrice <= 0) {
                        throw new \Illuminate\Http\Exceptions\HttpResponseException(
                            response()->json([
                                'message' => "Item \"{$menuItem->name}\" has no valid sell price for this outlet. Set pricing under Menu Pricing.",
                            ], 422)
                        );
                    }
                    $taxRate = floatval($menuItem->tax?->rate ?? 0);
                    $priceTaxInclusive = (bool) ($rmi->price_tax_inclusive ?? true);
                    $taxRegime = strtolower((string) ($menuItem->tax?->type ?? 'local')) === 'vat' ? 'vat_liquor' : 'gst';
                    $matching = $currentActive->filter(
                        fn ($i) => $i->menu_item_id == $row['menu_item_id']
                            && ($i->menu_item_variant_id ?? null) == $variantId
                            && trim($i->notes ?? '') === trim($notes ?? '')
                    );
                }
                $totalCurrent = $matching->sum('quantity');

                if ($totalCurrent === $qty) {
                    continue;
                }

                $createAttrs = fn ($q, $u) => [
                    'order_id' => $order->id,
                    'menu_item_id' => $row['menu_item_id'] ?? null,
                    'menu_item_variant_id' => $row['menu_item_variant_id'] ?? null,
                    'combo_id' => $row['combo_id'] ?? null,
                    'quantity' => $q,
                    'unit_price' => $u,
                    'tax_rate' => $taxRate,
                    'tax_regime' => $taxRegime,
                    'price_tax_inclusive' => $priceTaxInclusive,
                    'line_total' => $u * $q,
                    'kot_sent' => false,
                    'kot_hold' => false,
                    'status' => 'active',
                    'kot_batch' => null,
                    'notes' => $notes,
                ];

                if ($totalCurrent < $qty) {
                    $delta = $qty - $totalCurrent;
                    $unsent = $matching->first(fn ($i) => ! $i->kot_sent);
                    if ($unsent) {
                        $unsent->update([
                            'quantity' => $unsent->quantity + $delta,
                            'unit_price' => $unitPrice,
                            'tax_rate' => $taxRate,
                            'tax_regime' => $taxRegime,
                            'price_tax_inclusive' => $priceTaxInclusive,
                            'line_total' => $unitPrice * ($unsent->quantity + $delta),
                            'notes' => $notes,
                        ]);
                    } else {
                        PosOrderItem::create($createAttrs($delta, $unitPrice));
                    }
                } else {
                    $toReduce = $totalCurrent - $qty;
                    // Prioritize cancellation: unsent first, then pending, started, ready, and finally served last.
                    $sortedMatching = $matching->sortBy(function ($item) {
                        if (! $item->kot_sent) return 0;
                        if (! $item->kot_started_at && ! $item->kitchen_ready_at && ! $item->kitchen_served_at) return 1;
                        if (! $item->kitchen_ready_at && ! $item->kitchen_served_at) return 2;
                        if (! $item->kitchen_served_at) return 3;
                        return 4;
                    });
                    
                    foreach ($sortedMatching as $item) {
                        if ($toReduce <= 0) {
                            break;
                        }
                        if ($item->quantity <= $toReduce) {
                            $toReduce -= $item->quantity;

                            if ($item->kot_sent) {
                                $name = $item->menuItem ? $item->menuItem->name : 'Item';
                                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                                    response()->json(['message' => "Cannot silently remove '{$name}' from the cart because it has already been sent to the kitchen. Please use the Void feature instead."], 422)
                                );
                            } else {
                                $kitchenStore = $this->getKitchenForOrder($order);
                                $barLocationId = $order->restaurant?->bar_location_id;
                                $barStore = $barLocationId ? InventoryLocation::find($barLocationId) : InventoryLocation::query()->where('type', 'bar_store')->where('department_id', $order->restaurant?->department_id)->first();
                                $targetStore = $this->resolveInventoryDeductionStore($item->menuItem, $kitchenStore, $barStore);

                                if ($item->inventory_deducted && $targetStore) {
                                    $this->reverseOrderItemInventory($item, $targetStore, 'pos_order_sync_reduce', (string) $order->id);
                                }
                                $item->delete();
                            }
                        } else {
                            $newQty = $item->quantity - $toReduce;
                            if ($item->kot_sent) {
                                $name = $item->menuItem ? $item->menuItem->name : 'Item';
                                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                                    response()->json(['message' => "Cannot reduce quantity of '{$name}' because it has already been sent to the kitchen. Please use the Void feature instead."], 422)
                                );
                            } else {
                                $item->update([
                                    'quantity' => $newQty,
                                    'unit_price' => $unitPrice,
                                    'tax_rate' => $taxRate,
                                    'tax_regime' => $taxRegime,
                                    'price_tax_inclusive' => $priceTaxInclusive,
                                    'line_total' => $unitPrice * $newQty,
                                    'notes' => $notes,
                                ]);
                            }
                            $toReduce = 0;
                        }
                    }
                }
            }

            $order->touch(); // Force updated_at change even if only items were synced
            $this->recalculate($order);
        });

        $fresh = $order->fresh();
        $this->broadcastPosOutletUpdate((int) $fresh->restaurant_id, (int) $fresh->id);

        return response()->json($this->formatOrder($fresh->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'waiter', 'openedBy', 'voidedBy', 'discountApprovedBy')));
    }

    // ── Send KOT ──────────────────────────────────────────────────────────────

    public function sendKot(PosOrder $order)
    {
        $this->checkPermission('pos-order');
        $this->authorizeOrderAccess($order);
        if ($order->status !== 'open') {
            return response()->json(['message' => 'Order is billed. Re-open to add items and send KOT.'], 422);
        }
        if ($this->isBusinessDateClosedForOrder($order)) {
            return response()->json(['message' => 'Business date is already closed for this outlet.'], 422);
        }

        DB::transaction(function () use ($order) {
            // Lock the order to prevent concurrent simultaneous KOT triggers issuing the same batch number
            $order = PosOrder::where('id', $order->id)->lockForUpdate()->first();

            // Only items that require production (not just simple stock deductions) go to KOT display
            $kotQuery = $order->items()
                ->where('status', 'active')
                ->where('kot_sent', false)
                ->where('kot_hold', false)
                ->where(function ($q) {
                    $q->whereNull('menu_item_id')
                        ->orWhereHas('menuItem', fn ($mq) => $mq->where('requires_production', true));
                });

            if ($kotQuery->exists()) {
                $order->increment('current_kot_batch');
                $batch = $order->current_kot_batch;

                $order->items()
                    ->where('status', 'active')
                    ->where('kot_sent', false)
                    ->where('kot_hold', false)
                    ->where(function ($q) {
                        $q->whereNull('menu_item_id')
                            ->orWhereHas('menuItem', fn ($mq) => $mq->where('requires_production', true));
                    })
                    ->update(['kot_sent' => true, 'kot_batch' => $batch]);

                if (! in_array($order->kitchen_status, ['pending', 'preparing'])) {
                    $order->update(['kitchen_status' => 'pending']);
                }
            }
        });

        $fresh = $order->fresh();
        $this->broadcastPosOutletUpdate((int) $fresh->restaurant_id, (int) $fresh->id);

        return response()->json([
            'message' => 'KOT sent.',
            'kitchen_status' => $fresh->kitchen_status,
            'kot_batch' => $fresh->current_kot_batch,
        ]);
    }

    /**
     * Hold or release KOT for unsent lines (excluded from bulk Send KOT until fired).
     */
    public function setKotHoldItems(Request $request, PosOrder $order)
    {
        $this->checkPermission('pos-order');
        $this->authorizeOrderAccess($order);
        if ($order->status !== 'open') {
            return response()->json(['message' => 'Order is billed. Re-open to change KOT hold.'], 422);
        }
        if ($this->isBusinessDateClosedForOrder($order)) {
            return response()->json(['message' => 'Business date is already closed for this outlet.'], 422);
        }

        $validated = $request->validate([
            'order_item_ids' => 'required|array|min:1',
            'order_item_ids.*' => 'integer|exists:pos_order_items,id',
            'hold' => 'required|boolean',
        ]);

        $items = PosOrderItem::whereIn('id', $validated['order_item_ids'])->get();
        foreach ($items as $item) {
            if ($item->order_id !== $order->id) {
                return response()->json(['message' => 'Invalid order line.'], 422);
            }
            if ($item->status !== 'active' || $item->kot_sent) {
                return response()->json(['message' => 'Only unsent active lines can be held or released.'], 422);
            }
            if (! $this->orderItemRequiresKot($item)) {
                return response()->json(['message' => 'This line does not use kitchen KOT.'], 422);
            }
        }

        DB::transaction(function () use ($items, $validated, $order) {
            PosOrder::where('id', $order->id)->lockForUpdate()->first();
            foreach ($items as $item) {
                PosOrderItem::where('id', $item->id)->update(['kot_hold' => $validated['hold']]);
            }
            $order->touch();
        });

        $fresh = $order->fresh();
        $this->broadcastPosOutletUpdate((int) $fresh->restaurant_id, (int) $fresh->id);

        return response()->json($this->formatOrder($fresh->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy', 'discountApprovedBy')));
    }

    // ── Merge checks (combine table orders) ───────────────────────────────────

    /**
     * Merge one or more OPEN dine-in orders into a target order.
     *
     * Industry-standard behavior:
     * - Move active items to the target check
     * - Void the source check(s) with an audit note "Merged into Order #X"
     * - Free source table(s); target table remains occupied
     */
    public function merge(Request $request, PosOrder $order)
    {
        $this->checkPermission('pos-order');
        $this->authorizeOrderAccess($order);

        if ($order->order_type !== 'dine_in') {
            return response()->json(['message' => 'Only dine-in orders can be merged.'], 422);
        }
        if ($order->status !== 'open') {
            return response()->json(['message' => 'Only open orders can be merged.'], 422);
        }
        if ($this->isBusinessDateClosedForOrder($order)) {
            return response()->json(['message' => 'Business date is already closed for this outlet.'], 422);
        }

        $validated = $request->validate([
            'source_order_ids' => 'required|array|min:1',
            'source_order_ids.*' => 'required|integer|exists:pos_orders,id',
        ]);

        $targetId = (int) $order->id;
        $sourceIds = collect($validated['source_order_ids'])
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($id) => $id > 0 && $id !== $targetId)
            ->unique()
            ->values();

        if ($sourceIds->isEmpty()) {
            return response()->json(['message' => 'Select at least one other open table order to merge.'], 422);
        }

        $errorResponse = null;
        $mergedSources = [];

        DB::transaction(function () use (&$order, $targetId, $sourceIds, &$errorResponse, &$mergedSources) {
            /** @var PosOrder $target */
            $target = PosOrder::where('id', $targetId)->lockForUpdate()->first();
            if (! $target || $target->status !== 'open' || $target->order_type !== 'dine_in') {
                $errorResponse = response()->json(['message' => 'Target order is no longer mergeable.'], 422);
                return;
            }

            $sources = PosOrder::whereIn('id', $sourceIds)->lockForUpdate()->get();
            if ($sources->count() !== $sourceIds->count()) {
                $errorResponse = response()->json(['message' => 'One or more source orders no longer exist.'], 422);
                return;
            }

            // Validate sources
            foreach ($sources as $src) {
                if ($src->id === $targetId) {
                    continue;
                }
                if ($src->order_type !== 'dine_in') {
                    $errorResponse = response()->json(['message' => 'Can only merge dine-in orders.'], 422);
                    return;
                }
                if ($src->status !== 'open') {
                    $errorResponse = response()->json(['message' => 'Only open orders can be merged.'], 422);
                    return;
                }
                if ((int) $src->restaurant_id !== (int) $target->restaurant_id) {
                    $errorResponse = response()->json(['message' => 'Can only merge orders from the same outlet.'], 422);
                    return;
                }
            }

            // Lock involved tables to prevent concurrent order creation/transfers
            $tableIds = collect([$target->table_id])
                ->merge($sources->pluck('table_id'))
                ->filter()
                ->unique()
                ->values()
                ->all();
            foreach ($tableIds as $tid) {
                RestaurantTable::where('id', $tid)->lockForUpdate()->first();
            }

            // Move active items from sources into target
            foreach ($sources as $src) {
                if ($src->id === $targetId) continue;

                $sourceBatches = PosOrderItem::where('order_id', $src->id)
                    ->where('status', 'active')
                    ->whereNotNull('kot_batch')
                    ->distinct('kot_batch')
                    ->pluck('kot_batch');

                $moved = 0;
                foreach ($sourceBatches as $srcBatch) {
                    $target->increment('current_kot_batch');
                    $newBatch = $target->current_kot_batch;

                    $moved += PosOrderItem::where('order_id', $src->id)
                        ->where('status', 'active')
                        ->where('kot_batch', $srcBatch)
                        ->update([
                            'order_id' => $targetId,
                            'kot_batch' => $newBatch
                        ]);
                }

                // Move any unsent items
                $moved += PosOrderItem::where('order_id', $src->id)
                    ->where('status', 'active')
                    ->whereNull('kot_batch')
                    ->update(['order_id' => $targetId]);

                // Void the source order as "merged"
                $src->update([
                    'status' => 'void',
                    'closed_at' => now(),
                    'void_reason' => 'Duplicate',
                    'void_notes' => trim(($src->void_notes ? $src->void_notes.' ' : '')."Merged into Order #{$targetId}"),
                    'voided_by' => auth()->id(),
                    'voided_at' => now(),
                ]);

                if ($src->table_id) {
                    RestaurantTable::where('id', $src->table_id)->update(['status' => 'available']);
                }

                $mergedSources[] = ['id' => $src->id, 'moved_items' => (int) $moved];
            }

            // Keep target occupied and recalculate totals
            $target->touch();
            $this->recalculate($target);
            $order = $target;
        });

        if ($errorResponse) {
            return $errorResponse;
        }

        // Notify clients (tables list + order view)
        $fresh = $order->fresh();
        $this->broadcastPosOutletUpdate((int) $fresh->restaurant_id, (int) $fresh->id);
        foreach ($mergedSources as $ms) {
            $this->broadcastPosOutletUpdate((int) $fresh->restaurant_id, (int) ($ms['id'] ?? null));
        }

        // Return merged order in the standard order payload shape (same as GET /pos/orders/:id).
        // This keeps the client logic consistent (`items` always present).
        return response()->json($this->formatOrder($fresh->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy', 'discountApprovedBy')));
    }

    /**
     * Fire held (or selected) unsent lines — one batch number for this fire.
     */
    public function fireKotItems(Request $request, PosOrder $order)
    {
        $this->checkPermission('pos-order');
        $this->authorizeOrderAccess($order);
        if ($order->status !== 'open') {
            return response()->json(['message' => 'Order is billed. Re-open to send KOT.'], 422);
        }
        if ($this->isBusinessDateClosedForOrder($order)) {
            return response()->json(['message' => 'Business date is already closed for this outlet.'], 422);
        }

        $validated = $request->validate([
            'order_item_ids' => 'required|array|min:1',
            'order_item_ids.*' => 'integer|exists:pos_order_items,id',
        ]);

        $ids = array_values(array_unique($validated['order_item_ids']));
        $items = PosOrderItem::whereIn('id', $ids)->orderBy('id')->get();

        foreach ($items as $item) {
            if ($item->order_id !== $order->id) {
                return response()->json(['message' => 'Invalid order line.'], 422);
            }
            if ($item->status !== 'active' || $item->kot_sent) {
                return response()->json(['message' => 'Only unsent active lines can be fired.'], 422);
            }
            if (! $item->kot_hold) {
                return response()->json(['message' => 'Only held lines can be fired.'], 422);
            }
            if (! $this->orderItemRequiresKot($item)) {
                return response()->json(['message' => 'This line does not use kitchen KOT.'], 422);
            }
        }

        DB::transaction(function () use ($order, $ids) {
            $locked = PosOrder::where('id', $order->id)->lockForUpdate()->first();
            $locked->increment('current_kot_batch');
            $batch = $locked->fresh()->current_kot_batch;

            PosOrderItem::whereIn('id', $ids)->update([
                'kot_sent' => true,
                'kot_batch' => $batch,
                'kot_hold' => false,
            ]);

            if (! in_array($locked->kitchen_status, ['pending', 'preparing'])) {
                $locked->update(['kitchen_status' => 'pending']);
            }
        });

        $fresh = $order->fresh()->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy', 'discountApprovedBy');

        $this->broadcastPosOutletUpdate((int) $fresh->restaurant_id, (int) $fresh->id);

        return response()->json([
            'message' => 'KOT sent.',
            'order' => $this->formatOrder($fresh),
            'kitchen_status' => $fresh->kitchen_status,
            'kot_batch' => $fresh->current_kot_batch,
        ]);
    }

    // ── Open bill (set status to billed) ────────────────────────────────────────

    public function openBill(PosOrder $order)
    {
        $this->checkPermission('pos-settle');
        $this->authorizeOrderAccess($order);
        if ($this->isBusinessDateClosedForOrder($order)) {
            return response()->json(['message' => 'Cannot open bill: business date is already closed for this outlet.'], 422);
        }
        if ($order->items()->where('status', 'active')->doesntExist()) {
            return response()->json(['message' => 'Cannot bill an order with no active items.'], 422);
        }
        $errorResponse = null;
        DB::transaction(function () use ($order, &$errorResponse) {
            $order = PosOrder::where('id', $order->id)->lockForUpdate()->first();

            if ($order->status !== 'open') {
                $errorResponse = response()->json([
                    'message' => 'Order must be open to generate bill.',
                ], 422);

                return;
            }
            $order->update(['status' => 'billed']);
        });

        if ($errorResponse) {
            return $errorResponse;
        }

        $fresh = $order->fresh();
        $this->broadcastPosOutletUpdate((int) $fresh->restaurant_id, (int) $fresh->id);

        return response()->json($this->formatOrder($fresh->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy', 'discountApprovedBy')));
    }

    // ── Settle / Pay ──────────────────────────────────────────────────────────

    public function settle(Request $request, PosOrder $order)
    {
        $this->checkPermission('pos-settle');
        $this->authorizeOrderAccess($order);
        if (! in_array($order->status, ['open', 'billed'])) {
            return response()->json(['message' => 'Only open or billed orders can be settled.'], 422);
        }

        // ── 1. CHECK IF BUSINESS DATE IS CLOSED (order’s business day, not “today” only) ──
        $order->loadMissing('restaurant');
        if ($this->isBusinessDateClosedForOrder($order)) {
            return response()->json(['message' => 'Cannot settle: this order\'s business date is already closed for this outlet.'], 422);
        }
        $businessDate = $this->businessDateStringForOrder($order);

        $validated = $request->validate([
            'discount_type' => 'nullable|in:percent,flat',
            'discount_value' => 'nullable|numeric|min:0',
            'service_charge_type' => 'nullable|in:percent,flat',
            'service_charge_value' => 'nullable|numeric|min:0',
            'tax_exempt' => 'nullable|boolean',
            'tip_amount' => 'nullable|numeric|min:0',
            'delivery_charge' => 'nullable|numeric|min:0',
            'is_complimentary' => 'nullable|boolean',
            'complimentary_note' => 'nullable|string|max:500',
            'payments' => 'required_unless:is_complimentary,true|array',
            'payments.*.method' => 'required|in:cash,card,upi,room_charge',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.reference_no' => 'nullable|string',
        ]);

        // Discount/complimentary is an elevated permission (separate from settle).
        if (
            ((float) ($validated['discount_value'] ?? 0) > 0) ||
            ((bool) ($validated['is_complimentary'] ?? false) === true)
        ) {
            $this->checkPermission('pos-discount');
        }

        // Percent-type caps at 100; flat-type capped at subtotal in recalculate()
        if (($validated['discount_type'] ?? null) === 'percent' && ($validated['discount_value'] ?? 0) > 100) {
            return response()->json(['message' => 'Discount percent cannot exceed 100%.'], 422);
        }
        if (($validated['service_charge_type'] ?? null) === 'percent' && ($validated['service_charge_value'] ?? 0) > 100) {
            return response()->json(['message' => 'Service charge percent cannot exceed 100%.'], 422);
        }

        // Security check: only allow room-charge if order is linked to a booking (Room Service/Dine-in with Room)
        if (! empty($validated['payments'])) {
            foreach ($validated['payments'] as $pay) {
                if ($pay['method'] === 'room_charge' && ! $order->booking_id) {
                    return response()->json([
                        'message' => 'Room Charge payment is only available for orders with a linked Checked-in Room.',
                    ], 422);
                }
            }
        }

        $hasRoomCharge = collect($validated['payments'] ?? [])->contains('method', 'room_charge');
        if ($hasRoomCharge && ($order->order_type !== 'room_service' || ! $order->booking_id)) {
            return response()->json(['message' => 'Room charge is only available for room service orders with a linked booking.'], 422);
        }

        DB::transaction(function () use ($order, $validated, $businessDate) {
            $order = PosOrder::where('id', $order->id)->lockForUpdate()->first();
            if (! in_array($order->status, ['open', 'billed'])) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                    response()->json(['message' => 'Only open or billed orders can be settled.'], 422)
                );
            }

            // If room charge is used, ensure booking is still active/checked-in
            $hasRoomCharge = collect($validated['payments'] ?? [])->contains('method', 'room_charge');
            if ($hasRoomCharge && $order->booking_id) {
                $booking = Booking::lockForUpdate()->find($order->booking_id);
                if (! $booking || $booking->status !== 'checked_in') {
                    throw new \Illuminate\Http\Exceptions\HttpResponseException(
                        response()->json(['message' => 'Linked booking is no longer checked-in. Cannot process room charge.'], 422)
                    );
                }
            }

            // Apply discount, service charge, tax exempt, delivery charge
            $deliveryCharge = (float) ($validated['delivery_charge'] ?? 0);
            if ($order->order_type !== 'delivery') {
                $deliveryCharge = 0;
            }

            $isComplimentary = (bool) ($validated['is_complimentary'] ?? false);

            $order->update([
                'business_date' => $businessDate,
                'discount_type' => $isComplimentary ? 'percent' : ($validated['discount_type'] ?? null),
                'discount_value' => $isComplimentary ? 100 : ($validated['discount_value'] ?? 0),
                'service_charge_type' => $validated['service_charge_type'] ?? null,
                'service_charge_value' => $validated['service_charge_value'] ?? 0,
                'tax_exempt' => (bool) ($validated['tax_exempt'] ?? $order->tax_exempt),
                'tip_amount' => (float) ($validated['tip_amount'] ?? 0),
                'delivery_charge' => $deliveryCharge,
                'is_complimentary' => $isComplimentary,
                'notes' => $isComplimentary ? ($validated['complimentary_note'] ?? $order->notes) : $order->notes,
            ]);
            $this->recalculate($order);
            $order->refresh();

            if ((float) $order->discount_amount >= 0.01 || $order->is_complimentary) {
                $order->update([
                    'discount_approved_by' => auth()->id(),
                    'discount_approved_at' => now(),
                ]);
            } else {
                $order->update([
                    'discount_approved_by' => null,
                    'discount_approved_at' => null,
                ]);
            }
            $order->refresh();

            $paymentsTotal = collect($validated['payments'] ?? [])->sum('amount');
            if (! $isComplimentary && $paymentsTotal < $order->total_amount - 0.01) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                    response()->json([
                        'message' => 'Total payments ('.number_format($paymentsTotal, 2).') is less than order total ('.number_format($order->total_amount, 2).').',
                    ], 422),
                );
            }

            // Record payments (skip for complimentary — total is 0)
            $order->payments()->delete();
            if (! $isComplimentary) {
                foreach ($validated['payments'] ?? [] as $pay) {
                    PosPayment::create([
                        'order_id' => $order->id,
                        'business_date' => $businessDate,
                        'method' => $pay['method'],
                        'amount' => $pay['amount'],
                        'reference_no' => $pay['reference_no'] ?? null,
                        'paid_at' => now(),
                        'received_by' => auth()->id(),
                    ]);
                }
            }

            // Close order
            $order->update(['status' => 'paid', 'closed_at' => now()]);

            // Ensure ALL active items in the order are deducted from inventory if not already done
            $this->deductOrderInventoryCompletely($order);

            // Set table to cleaning (dine-in only)
            if ($order->table_id) {
                RestaurantTable::where('id', $order->table_id)->update(['status' => 'cleaning']);
            }

            $roomChargeTotal = collect($validated['payments'] ?? [])
                ->where('method', 'room_charge')
                ->sum('amount');

            if ($roomChargeTotal > 0 && $order->booking_id) {
                Booking::where('id', $order->booking_id)
                    ->increment('extra_charges', $roomChargeTotal);
            }
        });

        $fresh = $order->fresh();
        $this->broadcastPosOutletUpdate((int) $fresh->restaurant_id, (int) $fresh->id);

        return response()->json($this->formatOrder($fresh->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy', 'discountApprovedBy')));
    }

    public function reopen(PosOrder $order)
    {
        $this->checkPermission('pos-reopen-order');
        $this->authorizeOrderAccess($order);
        if ($this->isBusinessDateClosedForOrder($order)) {
            return response()->json(['message' => 'Cannot re-open: business date is already closed for this outlet.'], 422);
        }
        $errorResponse = null;
        DB::transaction(function () use ($order, &$errorResponse) {
            // Lock the order to prevent concurrent payments while reopening
            $order = PosOrder::where('id', $order->id)->lockForUpdate()->first();

            if ($order->status !== 'billed') {
                $errorResponse = response()->json([
                    'message' => 'Only billed (unpaid) orders can be re-opened.',
                ], 422);

                return;
            }

            if ($order->payments()->exists()) {
                $errorResponse = response()->json([
                    'message' => 'Cannot re-open: order has payments. Void or refund first.',
                ], 422);

                return;
            }

            $order->update(['status' => 'open']);
        });

        if ($errorResponse) {
            return $errorResponse;
        }

        $fresh = $order->fresh();
        $this->broadcastPosOutletUpdate((int) $fresh->restaurant_id, (int) $fresh->id);

        return response()->json($this->formatOrder($fresh->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy', 'discountApprovedBy')));
    }

    // ── Void ──────────────────────────────────────────────────────────────────

    public function void(Request $request, PosOrder $order)
    {
        $this->checkPermission('pos-void-item');
        $this->authorizeOrderAccess($order);
        if (in_array($order->status, ['paid', 'refunded', 'void'])) {
            return response()->json(['message' => 'Cannot void a paid, refunded, or already-voided order.'], 422);
        }

        if ($this->isBusinessDateClosedForOrder($order)) {
            return response()->json(['message' => 'Cannot void orders from a closed business date.'], 422);
        }

        $validated = $request->validate([
            'void_reason' => 'required|string|in:Duplicate,Wrong order,Guest left,Guest changed mind,Test order,Other',
            'void_notes' => 'nullable|string|max:500',
        ]);

        $blocked = false;
        DB::transaction(function () use ($order, $validated, &$blocked) {
            $order = PosOrder::where('id', $order->id)->lockForUpdate()->first();
            if (! in_array($order->status, ['open', 'billed'])) {
                $blocked = true;
                return;
            }

            $order->update([
                'status' => 'void',
                'closed_at' => now(),
                'void_reason' => $validated['void_reason'],
                'void_notes' => $validated['void_notes'] ?? null,
                'voided_by' => auth()->id(),
                'voided_at' => now(),
            ]);

            if ($order->table_id) {
                RestaurantTable::where('id', $order->table_id)->update(['status' => 'available']);
            }

            $order->load('restaurant');
            $kitchenStore = $this->getKitchenForOrder($order);
            $barLocationId = $order->restaurant?->bar_location_id;
            $barStore = $barLocationId
                ? InventoryLocation::find($barLocationId)
                : InventoryLocation::query()->where('type', 'bar_store')->where('department_id', $order->restaurant?->department_id)->first();

            // Don't reverse if kitchen started cooking (kot_started_at) — ingredients in use or used.
            // System deducts at "Mark Ready", but physically they use ingredients when they start.
            foreach ($order->items()->where('status', 'active')->with('menuItem')->get() as $item) {
                if ($item->kot_started_at || $item->kitchen_ready_at) {
                    $item->update(['status' => 'cancelled']);

                    continue;
                }
                $targetStore = $this->resolveInventoryDeductionStore($item->menuItem, $kitchenStore, $barStore);
                if ($item->inventory_deducted && $targetStore) {
                    $this->reverseOrderItemInventory($item, $targetStore, 'pos_order_void', (string) $order->id);
                }
                $item->update(['status' => 'cancelled']);
            }
        });

        if ($blocked) {
            return response()->json(['message' => 'Order can no longer be voided (status changed).'], 422);
        }

        $this->broadcastPosOutletUpdate((int) $order->restaurant_id, (int) $order->id);

        return response()->json(['message' => 'Order voided.']);
    }

    // ── Void item(s) (individual line cancellation) ─────────────────────────────

    public function voidItems(Request $request, PosOrder $order)
    {
        $this->checkPermission('pos-void-item');
        $this->authorizeOrderAccess($order);
        if (in_array($order->status, ['paid', 'void', 'refunded'])) {
            return response()->json(['message' => 'Cannot void items on a paid, voided or refunded order.'], 422);
        }
        if ($this->isBusinessDateClosedForOrder($order)) {
            return response()->json(['message' => 'Cannot void items: business date is already closed for this outlet.'], 422);
        }

        $validated = $request->validate([
            'order_item_ids' => 'required|array',
            'order_item_ids.*' => 'required|integer|exists:pos_order_items,id',
            'cancel_reason' => 'required|string|in:Wrong item,Guest declined,Duplicate,Spoiled,Other',
            'cancel_notes' => 'nullable|string|max:500',
            'void_quantity' => 'nullable|numeric|min:0.01',
        ]);

        $items = PosOrderItem::where('order_id', $order->id)
            ->whereIn('id', $validated['order_item_ids'])
            ->where('status', 'active')
            ->with('menuItem', 'variant', 'combo')
            ->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => 'No valid active items to void.'], 422);
        }

        $order->loadMissing('restaurant');
        $kitchenStore = $this->getKitchenForOrder($order);
        $barLocationId = $order->restaurant?->bar_location_id;
        $barStore = $barLocationId
            ? InventoryLocation::find($barLocationId)
            : InventoryLocation::query()->where('type', 'bar_store')->where('department_id', $order->restaurant?->department_id)->first();

        $voidQuantity = $validated['void_quantity'] ?? null;

        DB::transaction(function () use ($items, $order, $kitchenStore, $barStore, $validated, $voidQuantity) {
            PosOrder::where('id', $order->id)->lockForUpdate()->first();

            $remainingQuantityToVoid = $voidQuantity;

            foreach ($items as $item) {
                if ($remainingQuantityToVoid !== null && $remainingQuantityToVoid <= 0) {
                    break;
                }

                $item->refresh();
                if ($item->status !== 'active') {
                    continue;
                }

                $qtyToVoidThisRow = $item->quantity;
                if ($remainingQuantityToVoid !== null && $item->quantity > $remainingQuantityToVoid) {
                    $qtyToVoidThisRow = $remainingQuantityToVoid;
                }

                if ($qtyToVoidThisRow < $item->quantity) {
                    // Partially void: Split row (schema uses line_total, not total_price)
                    $cancelItem = $item->replicate();
                    $cancelItem->quantity = $qtyToVoidThisRow;
                    $cancelItem->line_total = round(((float) $item->line_total / (float) $item->quantity) * $qtyToVoidThisRow, 2);
                    $cancelItem->status = 'cancelled';
                    $cancelItem->cancel_reason = $validated['cancel_reason'];
                    $cancelItem->cancel_notes = $validated['cancel_notes'] ?? null;
                    $cancelItem->cancelled_by = auth()->id();
                    $cancelItem->cancelled_at = now();
                    $cancelItem->save();

                    // Reduce original active row
                    $item->quantity -= $qtyToVoidThisRow;
                    $item->line_total = round((float) $item->line_total - (float) $cancelItem->line_total, 2);
                    $item->save();

                    $processItem = $cancelItem;
                } else {
                    // Fully void this row
                    $item->update([
                        'status' => 'cancelled',
                        'cancel_reason' => $validated['cancel_reason'],
                        'cancel_notes' => $validated['cancel_notes'] ?? null,
                        'cancelled_by' => auth()->id(),
                        'cancelled_at' => now(),
                    ]);
                    $processItem = $item;
                }

                if ($remainingQuantityToVoid !== null) {
                    $remainingQuantityToVoid -= $qtyToVoidThisRow;
                }

                // Handle inventory reversal on the actively voided slice
                if ($processItem->kot_started_at || $processItem->kitchen_ready_at) {
                    // Already cooked/cooking - do not reverse inventory, it's wasted
                    continue;
                }
                $targetStore = $this->resolveInventoryDeductionStore($processItem->menuItem, $kitchenStore, $barStore);
                if ($processItem->inventory_deducted && $targetStore) {
                    $this->reverseOrderItemInventory($processItem, $targetStore, 'pos_order_item_void', (string) $order->id);
                }
            }
            $this->recalculate($order);
        });

        $fresh = $order->fresh();
        $this->broadcastPosOutletUpdate((int) $fresh->restaurant_id, (int) $fresh->id);

        return response()->json($this->formatOrder($fresh->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy', 'discountApprovedBy')));
    }

    // ── Refund (paid orders) ───────────────────────────────────────────────────

    public function refund(Request $request, PosOrder $order)
    {
        $this->checkPermission('pos-void-item');
        $this->authorizeOrderAccess($order);
        if (! in_array($order->status, ['paid', 'refunded'])) {
            return response()->json(['message' => 'Only paid orders can be refunded.'], 422);
        }

        // ── 1. CHECK IF ORDER’S BUSINESS DAY IS CLOSED (sealed day — no further refunds) ──
        $order->loadMissing('restaurant');
        if ($this->isBusinessDateClosedForOrder($order)) {
            return response()->json(['message' => 'Cannot refund: this order\'s business date is already closed for this outlet.'], 422);
        }
        // Cash-out / Z-report: attribute refund to the business date when the refund is performed
        $refundBusinessDate = BusinessDateService::resolve($order->restaurant);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|in:cash,card,upi,room_charge',
            'reference_no' => 'nullable|string|max:100',
            'reason' => 'nullable|string|max:500',
        ]);

        $amount = (float) $validated['amount'];
        $totalRefunded = (float) $order->refunds()->sum('amount');
        $refundable = (float) $order->total_amount - $totalRefunded;

        if ($validated['method'] === 'room_charge' && ! $order->booking_id) {
            return response()->json(['message' => 'Room charge refund is only available for room service orders with a linked booking.'], 422);
        }

        if ($amount > $refundable + 0.01) {
            return response()->json([
                'message' => 'Refund amount ('.number_format($amount, 2).') exceeds refundable amount ('.number_format($refundable, 2).').',
            ], 422);
        }

        DB::transaction(function () use ($order, $validated, $amount, $refundBusinessDate) {
            // Lock the order to serialize multiple concurrent refund requests
            $order = PosOrder::where('id', $order->id)->lockForUpdate()->first();

            // Re-verify the refundable amount after acquiring lock
            $currentRefunded = (float) $order->refunds()->sum('amount');
            $currentRefundable = (float) $order->total_amount - $currentRefunded;

            if ($amount > $currentRefundable + 0.01) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                    response()->json(['message' => 'Refund amount exceeds remaining refundable amount.'], 422)
                );
            }

            PosOrderRefund::create([
                'order_id' => $order->id,
                'business_date' => $refundBusinessDate,
                'amount' => $amount,
                'method' => $validated['method'],
                'reference_no' => $validated['reference_no'] ?? null,
                'reason' => $validated['reason'] ?? null,
                'refunded_at' => now(),
                'refunded_by' => auth()->id(),
            ]);

            if ($validated['method'] === 'room_charge' && $order->booking_id) {
                $booking = Booking::lockForUpdate()->find($order->booking_id);
                if ($booking) {
                    $newCharges = max(0, (float) $booking->extra_charges - $amount);
                    $booking->update(['extra_charges' => $newCharges]);
                }
            }

            $newTotalRefunded = (float) $order->refunds()->sum('amount');
            if ($newTotalRefunded >= (float) $order->total_amount - 0.01) {
                $order->update(['status' => 'refunded']);
            }
        });

        $fresh = $order->fresh();
        $this->broadcastPosOutletUpdate((int) $fresh->restaurant_id, (int) $fresh->id);

        return response()->json($this->formatOrder($fresh->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'refunds', 'room', 'table', 'waiter', 'openedBy', 'voidedBy', 'discountApprovedBy')));
    }

    // ── Kitchen Display ───────────────────────────────────────────────────────

    public function kitchenDisplay(Request $request)
    {
        $this->checkPermission('kitchen-production');
        $restaurantId = $request->input('restaurant_id');
        if ($restaurantId) {
            $this->authorizeRestaurantId((int) $restaurantId);
        }

        $query = PosOrder::with(['items.menuItem.tax', 'items.combo.menuItems', 'items.variant', 'table', 'restaurant', 'room'])
            ->whereIn('status', ['open', 'billed'])
            ->where('kitchen_status', '!=', 'served')
            ->whereHas('items', fn ($q) => $q->where('kot_sent', true)->where('status', 'active'));

        $user = auth()->user();

        if ($restaurantId) {
            // Specific restaurant selected
            $query->where('restaurant_id', $restaurantId);
        } elseif ($user && ! $user->hasRole('Admin') && ! $user->hasRole('Super Admin')) {
            // All assigned outlets selected: restrict to user's mapped restaurants
            $assignedIds = $user->restaurants()->pluck('restaurant_masters.id')->map(fn ($id) => (int) $id)->toArray();
            if (! empty($assignedIds)) {
                $query->whereIn('restaurant_id', $assignedIds);
            } else {
                // Fallback to department-based access
                $deptIds = $user->departments()->pluck('departments.id')->toArray();
                if (! empty($deptIds)) {
                    $query->whereHas('restaurant', function ($q) use ($deptIds) {
                        $q->whereIn('department_id', $deptIds)->orWhereNull('department_id');
                    });
                } else {
                    $query->whereRaw('1 = 0');
                }
            }
        }

        $orders = $query->orderBy('opened_at')->get()
            ->map(function ($order) use ($restaurantId, $user) {
                // Determine Bar vs Kitchen KDS based on USER department (not outlet name).
                // Reason: outlet names can contain "bar" (e.g. "Bar & Restaurant") and wrongly
                // hide all kitchen items when a specific outlet is selected.
                $userDept = $user ? $user->departments()->first() : null;
                $isBarKds = (bool) ($userDept && (
                    ($userDept->code && strtoupper((string) $userDept->code) === 'BAR') ||
                    stripos((string) $userDept->name, 'bar') !== false
                ));

                $label = match ($order->order_type ?? 'dine_in') {
                    'takeaway' => 'Takeaway'.($order->customer_name ? ' — '.$order->customer_name : ''),
                    'walk_in' => 'Walk-in'.($order->customer_name ? ' — '.$order->customer_name : ''),
                    'room_service' => 'Room '.($order->room?->room_number ?? $order->room_id),
                    'delivery' => 'Delivery'.($order->customer_name ? ' — '.$order->customer_name : '').($order->delivery_channel ? ' ('.str_replace('_', ' ', ucfirst($order->delivery_channel)).')' : ''),
                    default => 'Table '.($order->table?->table_number ?? '?'),
                };

                // Filtering only happens when a SPECIFIC outlet station is selected.
                // If viewing 'All Outlets', we show EVERYTHING for maximum oversight.
                $allKotItems = $order->items->where('status', 'active')->where('kot_sent', true)
                    ->filter(function ($item) use ($restaurantId, $isBarKds) {
                        // Skip items that don't need to be seen on KDS (e.g. Pepsi, Spirits)
                        if (! (bool) ($item->menuItem?->requires_production ?? true)) {
                            return false;
                        }

                        if (! $restaurantId) {
                            return true;
                        } // Show all in master view

                        // When a specific outlet is selected, only BAR users should be limited
                        // to direct-sale items. Kitchen users should see all KOT items, since
                        // menu master data may not reliably mark is_direct_sale for every item.
                        if ($isBarKds) {
                            return (bool) ($item->menuItem?->is_direct_sale ?? false);
                        }

                        return true;
                    });

                // Per line: hide only when this line is served (picked up / delivered)
                $activeKotItems = $allKotItems->filter(fn ($item) => ! $item->kitchen_served_at)->values();

                // Cancelled items for batches we're still showing
                $shownBatches = $activeKotItems->pluck('kot_batch')->unique();
                $cancelledItems = $order->items
                    ->where('status', 'cancelled')
                    ->where('kot_sent', true)
                    ->filter(fn ($i) => $shownBatches->contains($i->kot_batch))
                    ->values();

                $maxBatch = $activeKotItems->max('kot_batch') ?? 1;
                $readyBatches = $this->kotBatchesFromItems($activeKotItems, 'kitchen_ready_at');
                $startedBatches = $activeKotItems->filter(fn ($i) => $i->kot_started_at)->pluck('kot_batch')->unique()->sort()->values()->toArray();

                return [
                    'id' => $order->id,
                    'order_type' => $order->order_type ?? 'dine_in',
                    'label' => $label,
                    'table_number' => $order->table?->table_number,
                    'room_number' => $order->room?->room_number,
                    'customer_name' => $order->customer_name,
                    'restaurant' => $order->restaurant?->name,
                    'covers' => $order->covers,
                    'status' => $order->status,
                    'kitchen_status' => $order->kitchen_status ?? 'pending',
                    'current_batch' => $maxBatch,
                    'ready_batches' => array_values(array_map('intval', $readyBatches)),
                    'started_batches' => array_values(array_map('intval', $startedBatches)),
                    'opened_at' => $order->opened_at,
                    'items' => $activeKotItems->map(fn ($i) => [
                        'id' => $i->id,
                        'name' => $i->combo_id ? ($i->combo?->name ?? 'Combo') : (
                            $i->menu_item_variant_id ? ($i->menuItem?->name ?? 'Unknown').' — '.($i->variant?->size_label ?? '') : ($i->menuItem?->name ?? 'Unknown')
                        ),
                        'type' => $i->combo_id ? 'combo' : ($i->menuItem?->type ?? null),
                        'combo_items' => $i->combo_id && $i->combo ? $i->combo->menuItems->pluck('name')->toArray() : null,
                        'quantity' => $i->quantity,
                        'notes' => $i->notes,
                        'kot_batch' => $i->kot_batch ?? 1,
                        'is_addl' => ($i->kot_batch ?? 1) > 1,
                        'kot_started_at' => $i->kot_started_at?->toIso8601String(),
                        'kitchen_ready_at' => $i->kitchen_ready_at?->toIso8601String(),
                        'kitchen_served_at' => $i->kitchen_served_at?->toIso8601String(),
                    ]),
                    'cancellations' => $cancelledItems->map(fn ($i) => [
                        'id' => $i->id,
                        'name' => $i->combo_id ? ($i->combo?->name ?? 'Combo') : (
                            $i->menu_item_variant_id ? ($i->menuItem?->name ?? 'Unknown').' — '.($i->variant?->size_label ?? '') : ($i->menuItem?->name ?? 'Unknown')
                        ),
                        'quantity' => $i->quantity,
                        'kot_batch' => $i->kot_batch ?? 1,
                    ]),
                ];
            })
            ->filter(fn ($o) => count($o['items']) > 0)
            ->values()
            ->all();

        return response()->json($orders);
    }

    public function startKotPrep(Request $request, PosOrder $order)
    {
        $this->checkPermission('pos-order');
        $this->authorizeOrderAccess($order);
        $validated = $request->validate([
            'batch' => 'required|integer|min:1',
        ]);
        $batch = (int) $validated['batch'];

        if ($this->isBusinessDateClosedForOrder($order)) {
            return response()->json(['message' => 'Business date is already closed for this outlet.'], 422);
        }

        return DB::transaction(function () use ($order, $batch) {
            $order = PosOrder::where('id', $order->id)->lockForUpdate()->first();

            $batchItems = $order->items()
                ->where('kot_sent', true)
                ->where('status', 'active')
                ->where('kot_batch', $batch)
                ->get();

            if ($batchItems->isEmpty()) {
                return response()->json(['message' => 'No items in batch.'], 422);
            }

            if ($batchItems->every(fn ($i) => $i->kot_started_at)) {
                return response()->json(['message' => 'KOT already started.']);
            }

            $order->items()
                ->where('kot_sent', true)
                ->where('status', 'active')
                ->where('kot_batch', $batch)
                ->update(['kot_started_at' => now()]);

            if ($order->kitchen_status === 'pending') {
                $order->update(['kitchen_status' => 'preparing']);
            }

            $fresh = $order->fresh();
            $this->broadcastPosOutletUpdate((int) $fresh->restaurant_id, (int) $fresh->id);

            return response()->json([
                'id' => $order->id,
                'kitchen_status' => $fresh->kitchen_status,
            ]);
        });
    }

    // ── Mark Batch Ready (per-batch kitchen status) ───────────────────────────

    public function markBatchReady(Request $request, PosOrder $order)
    {
        $this->checkPermission('pos-order');
        $this->authorizeOrderAccess($order);
        $validated = $request->validate([
            'batch' => 'required|integer|min:1',
        ]);
        $batch = (int) $validated['batch'];

        if ($this->isBusinessDateClosedForOrder($order)) {
            return response()->json(['message' => 'Business date is already closed for this outlet.'], 422);
        }

        return DB::transaction(function () use ($order, $batch) {
            // Lock the order to prevent concurrent ready deductions for the same batch
            $order = PosOrder::where('id', $order->id)->lockForUpdate()->first();

            $batchItems = $order->items()
                ->where('kot_sent', true)
                ->where('status', 'active')
                ->where('kot_batch', $batch)
                ->with('menuItem', 'combo.menuItems', 'variant')
                ->get();

            if ($batchItems->isEmpty()) {
                return response()->json(['message' => 'No items in batch.'], 422);
            }

            // Already marked?
            if ($batchItems->every(fn ($i) => $i->kitchen_ready_at)) {
                return response()->json([
                    'id' => $order->id,
                    'kitchen_status' => $order->fresh()->kitchen_status,
                    'ready_batches' => $this->getReadyBatches($order),
                ]);
            }

            $toCheck = $batchItems->filter(fn ($i) => ! $i->inventory_deducted);
            if ($toCheck->isNotEmpty()) {
                $insufficient = $this->checkMadeToOrderStock($order, $toCheck);
                if (count($insufficient) > 0) {
                    return response()->json(['message' => 'Insufficient stock.', 'errors' => $insufficient], 422);
                }
            }

            $this->deductBatchIngredients($order, $batch);

            // Mark batch ready
            $order->items()
                ->where('kot_sent', true)
                ->where('status', 'active')
                ->where('kot_batch', $batch)
                ->update(['kitchen_ready_at' => now()]);

            // If all batches are now ready, set order kitchen_status
            $order->refresh();
            // All KOT items in this order
            $allKotItems = $order->items()->where('kot_sent', true)->where('status', 'active')->get();
            $allReady = $allKotItems->every(fn ($i) => $i->kitchen_ready_at);
            if ($allReady) {
                $order->update(['kitchen_status' => 'ready']);
            }

            $fresh = $order->fresh();
            $this->broadcastPosOutletUpdate((int) $fresh->restaurant_id, (int) $fresh->id);

            return response()->json([
                'id' => $order->id,
                'kitchen_status' => $fresh->kitchen_status,
                'ready_batches' => $this->getReadyBatches($order),
            ]);
        });
    }

    private function getReadyBatches(PosOrder $order): array
    {
        return $this->kotBatchNumbersWhereAllLinesHave($order, 'kitchen_ready_at');
    }

    private function getServedBatches(PosOrder $order): array
    {
        return $this->kotBatchNumbersWhereAllLinesHave($order, 'kitchen_served_at');
    }

    public function markBatchDelivered(Request $request, PosOrder $order)
    {
        $this->checkPermission('pos-order');
        $this->authorizeOrderAccess($order);
        $validated = $request->validate([
            'batch' => 'required|integer|min:1',
        ]);
        $batch = (int) $validated['batch'];

        if ($this->isBusinessDateClosedForOrder($order)) {
            return response()->json(['message' => 'Business date is already closed for this outlet.'], 422);
        }

        return DB::transaction(function () use ($order, $batch) {
            $order = PosOrder::where('id', $order->id)->lockForUpdate()->first();

            $batchItems = $order->items()
                ->where('kot_sent', true)
                ->where('status', 'active')
                ->where('kot_batch', $batch)
                ->get();

            if ($batchItems->isEmpty()) {
                return response()->json(['message' => 'No items in batch.'], 422);
            }

            if ($batchItems->contains(fn ($i) => ! $i->kitchen_ready_at)) {
                return response()->json(['message' => 'Batch must be ready before marking delivered.'], 422);
            }

            if ($batchItems->every(fn ($i) => $i->kitchen_served_at)) {
                return response()->json([
                    'id' => $order->id,
                    'kitchen_status' => $order->kitchen_status,
                    'ready_batches' => $this->getReadyBatches($order),
                    'served_batches' => $this->getServedBatches($order),
                ]);
            }

            $order->items()
                ->where('kot_sent', true)
                ->where('status', 'active')
                ->where('kot_batch', $batch)
                ->update(['kitchen_served_at' => now()]);

            $order->refresh();
            $allKotItems = $order->items()->where('kot_sent', true)->where('status', 'active')->get();
            $allServed = $allKotItems->isNotEmpty() && $allKotItems->every(fn ($i) => $i->kitchen_served_at);
            if ($allServed) {
                $order->update(['kitchen_status' => 'served']);
            }

            $fresh = $order->fresh();
            $this->broadcastPosOutletUpdate((int) $fresh->restaurant_id, (int) $fresh->id);

            return response()->json([
                'id' => $order->id,
                'kitchen_status' => $fresh->kitchen_status,
                'ready_batches' => $this->getReadyBatches($order),
                'served_batches' => $this->getServedBatches($order),
            ]);
        });
    }

    public function markOrderItemReady(Request $request, PosOrder $order)
    {
        $this->checkPermission('pos-order');
        $this->authorizeOrderAccess($order);
        $validated = $request->validate([
            'order_item_id' => 'required|integer|exists:pos_order_items,id',
        ]);
        if ($this->isBusinessDateClosedForOrder($order)) {
            return response()->json(['message' => 'Business date is already closed for this outlet.'], 422);
        }

        return DB::transaction(function () use ($order, $validated) {
            $order = PosOrder::where('id', $order->id)->lockForUpdate()->first();
            $item = PosOrderItem::where('id', $validated['order_item_id'])->first();
            if (! $item || $item->order_id !== $order->id) {
                return response()->json(['message' => 'Invalid order line.'], 422);
            }
            if ($item->status !== 'active' || ! $item->kot_sent) {
                return response()->json(['message' => 'Line is not active or not sent to kitchen.'], 422);
            }
            if (! $this->orderItemRequiresKot($item)) {
                return response()->json(['message' => 'This line does not use kitchen KOT.'], 422);
            }
            if ($item->kitchen_ready_at) {
                return response()->json([
                    'id' => $order->id,
                    'kitchen_status' => $order->fresh()->kitchen_status,
                    'ready_batches' => $this->getReadyBatches($order->fresh()->load('items')),
                ]);
            }

            if (! $item->inventory_deducted) {
                $item->loadMissing('menuItem', 'combo.menuItems', 'variant');
                $insufficient = $this->checkMadeToOrderStock($order, collect([$item]));
                if (count($insufficient) > 0) {
                    return response()->json(['message' => 'Insufficient stock.', 'errors' => $insufficient], 422);
                }
            }

            $order->loadMissing('restaurant');
            $kitchenStore = $this->getKitchenForOrder($order);
            $barLocationId = $order->restaurant?->bar_location_id;
            $barStore = $barLocationId
                ? InventoryLocation::find($barLocationId)
                : InventoryLocation::query()->where('type', 'bar_store')->where('department_id', $order->restaurant?->department_id)->first();

            if (! $item->inventory_deducted && $kitchenStore) {
                $targetStore = $this->resolveInventoryDeductionStore($item->menuItem, $kitchenStore, $barStore);
                $this->deductOrderItemInventory($item, $targetStore, 'pos_order_line_ready', (string) $item->id);
            }

            $itemUpdates = ['kitchen_ready_at' => now()];
            if (! $item->kot_started_at) {
                $itemUpdates['kot_started_at'] = now();
            }
            PosOrderItem::where('id', $item->id)->update($itemUpdates);

            if ($order->kitchen_status === 'pending') {
                $order->update(['kitchen_status' => 'preparing']);
            }
            $order->refresh();

            $allKotItems = $order->items()->where('kot_sent', true)->where('status', 'active')->get();
            $allReady = $allKotItems->isNotEmpty() && $allKotItems->every(fn ($i) => $i->kitchen_ready_at);
            if ($allReady) {
                $order->update(['kitchen_status' => 'ready']);
            }

            $fresh = $order->fresh();
            $this->broadcastPosOutletUpdate((int) $fresh->restaurant_id, (int) $fresh->id);

            return response()->json([
                'id' => $order->id,
                'kitchen_status' => $fresh->kitchen_status,
                'ready_batches' => $this->getReadyBatches($fresh->load('items')),
            ]);
        });
    }

    public function markOrderItemServed(Request $request, PosOrder $order)
    {
        $this->checkPermission('pos-order');
        $this->authorizeOrderAccess($order);
        $validated = $request->validate([
            'order_item_id' => 'required|integer|exists:pos_order_items,id',
        ]);
        if ($this->isBusinessDateClosedForOrder($order)) {
            return response()->json(['message' => 'Business date is already closed for this outlet.'], 422);
        }

        return DB::transaction(function () use ($order, $validated) {
            $order = PosOrder::where('id', $order->id)->lockForUpdate()->first();
            $item = PosOrderItem::where('id', $validated['order_item_id'])->first();
            if (! $item || $item->order_id !== $order->id) {
                return response()->json(['message' => 'Invalid order line.'], 422);
            }
            if ($item->status !== 'active' || ! $item->kot_sent) {
                return response()->json(['message' => 'Line is not active or not sent to kitchen.'], 422);
            }
            if (! $item->kitchen_ready_at) {
                return response()->json(['message' => 'Mark item ready before served.'], 422);
            }
            if ($item->kitchen_served_at) {
                return response()->json([
                    'id' => $order->id,
                    'kitchen_status' => $order->fresh()->kitchen_status,
                    'ready_batches' => $this->getReadyBatches($order->fresh()->load('items')),
                    'served_batches' => $this->getServedBatches($order->fresh()->load('items')),
                ]);
            }

            PosOrderItem::where('id', $item->id)->update(['kitchen_served_at' => now()]);
            $order->refresh();

            $allKotItems = $order->items()->where('kot_sent', true)->where('status', 'active')->get();
            $allServed = $allKotItems->isNotEmpty() && $allKotItems->every(fn ($i) => $i->kitchen_served_at);
            if ($allServed) {
                $order->update(['kitchen_status' => 'served']);
            }

            $fresh = $order->fresh();
            $this->broadcastPosOutletUpdate((int) $fresh->restaurant_id, (int) $fresh->id);

            return response()->json([
                'id' => $order->id,
                'kitchen_status' => $fresh->kitchen_status,
                'ready_batches' => $this->getReadyBatches($fresh->load('items')),
                'served_batches' => $this->getServedBatches($fresh),
            ]);
        });
    }

    private function getKitchenLocationForRestaurant(?RestaurantMaster $restaurant): ?InventoryLocation
    {
        if (! $restaurant) {
            return null;
        }
        if ($restaurant->kitchen_location_id) {
            $loc = InventoryLocation::find($restaurant->kitchen_location_id);
            if ($loc) {
                return $loc;
            }
        }

        return InventoryLocation::where('type', 'kitchen_store')
            ->where('department_id', $restaurant->department_id)
            ->first();
    }

    private function getBarLocationForRestaurant(?RestaurantMaster $restaurant): ?InventoryLocation
    {
        if (! $restaurant) {
            return null;
        }
        if ($restaurant->bar_location_id) {
            $loc = InventoryLocation::find($restaurant->bar_location_id);
            if ($loc) {
                return $loc;
            }
        }

        return InventoryLocation::where('type', 'bar_store')
            ->where('department_id', $restaurant->department_id)
            ->first();
    }

    private function getKitchenForOrder(PosOrder $order): ?InventoryLocation
    {
        $order->loadMissing('restaurant');

        return $this->getKitchenLocationForRestaurant($order->restaurant);
    }

    /**
     * Bar store is for finished-good SKUs (bottles, cans). Ingredient-based made-to-order
     * recipes (tea, coffee, etc.) always use kitchen stock even when is_direct_sale is true.
     */
    private function resolveInventoryDeductionStore(
        ?MenuItem $menuItem,
        ?InventoryLocation $kitchenStore,
        ?InventoryLocation $barStore
    ): ?InventoryLocation {
        if (! $menuItem) {
            return $kitchenStore ?? $barStore;
        }
        $recipe = Recipe::query()
            ->where('menu_item_id', $menuItem->id)
            ->where('is_active', true)
            ->first();
        if ($recipe && ! ($recipe->requires_production ?? true)) {
            return $kitchenStore;
        }
        if ($menuItem->is_direct_sale && $barStore) {
            return $barStore;
        }

        return $kitchenStore;
    }

    /**
     * Check if kitchen has sufficient ingredients for made-to-order items.
     * Returns array of insufficient items: [['menu_item' => 'Tea', 'ingredient' => 'Tea Leaves', 'required' => 3, 'available' => 0, 'uom' => 'Gm']]
     */
    private function checkMadeToOrderStock(PosOrder $order, $items): array
    {
        $kitchenStore = $this->getKitchenForOrder($order);
        if (! $kitchenStore) {
            return [];
        }

        $order->loadMissing('restaurant');
        $barLocationId = $order->restaurant?->bar_location_id;
        $barStore = $barLocationId
            ? InventoryLocation::find($barLocationId)
            : InventoryLocation::query()->where('type', 'bar_store')->where('department_id', $order->restaurant?->department_id)->first();

        $insufficient = [];
        foreach ($items as $orderItem) {
            if ($orderItem instanceof PosOrderItem) {
                $orderItem->loadMissing('variant');
            }
            $comboId = $orderItem->combo_id ?? null;
            $combo = ($comboId && isset($orderItem->combo)) ? $orderItem->combo : ($comboId ? Combo::with('menuItems')->find($comboId) : null);
            $menuItemIds = $comboId && $combo
                ? $combo->menuItems->pluck('id')
                : (($orderItem->menu_item_id ?? null) ? collect([$orderItem->menu_item_id]) : collect());
            $baseName = $comboId ? ($combo?->name ?? 'Combo') : (($orderItem->menuItem ?? null)?->name ?? MenuItem::find($orderItem->menu_item_id)?->name ?? 'Item');

            foreach ($menuItemIds as $menuItemId) {
                $menuItem = MenuItem::find($menuItemId);
                $recipe = Recipe::with('ingredients.inventoryItem.issueUom')
                    ->where('menu_item_id', $menuItemId)
                    ->where('is_active', true)
                    ->first();

                if (! $recipe || ($recipe->requires_production ?? true)) {
                    continue;
                }

                // Ingredient MTO: stock is always at kitchen / prep location, not bar shelf.
                $targetStore = $kitchenStore;

                $yield = max(1, (float) ($recipe->yield_quantity ?? 1));
                $scale = 1.0;
                $variantId = $orderItem->menu_item_variant_id ?? null;
                if ($variantId) {
                    $variant = ($orderItem instanceof PosOrderItem) ? $orderItem->variant : ($orderItem->variant ?? null);
                    $ml = (float) ($variant?->ml_quantity ?? 0);
                    if ($ml > 0 && $ml <= 10) {
                        $scale = $ml;
                    }
                }
                $multiplier = ($orderItem->quantity * $scale) / $yield;
                $menuName = $baseName.' · '.($recipe->menuItem?->name ?? 'Item #'.$menuItemId);

                foreach ($recipe->ingredients as $ing) {
                    $rawQty = round($ing->raw_quantity * $multiplier, 3);
                    $currentStock = (float) (DB::table('inventory_item_locations')
                        ->where('inventory_item_id', $ing->inventory_item_id)
                        ->where('inventory_location_id', $targetStore->id)
                        ->value('quantity') ?? 0);

                    if ($currentStock < $rawQty) {
                        $insufficient[] = [
                            'menu_item' => $menuName,
                            'ingredient' => $ing->inventoryItem?->name ?? 'Unknown',
                            'required' => $rawQty,
                            'available' => $currentStock,
                            'uom' => $ing->inventoryItem?->issueUom?->short_name ?? $ing->uom?->short_name ?? 'unit',
                        ];
                    }
                }
            }
        }

        return $insufficient;
    }

    /**
     * Deduct inventory for batch lines not yet deducted. Idempotent per line via inventory_deducted.
     */
    private function deductBatchIngredients(PosOrder $order, int $batch): ?array
    {
        $kitchenStore = $this->getKitchenForOrder($order);
        if (! $kitchenStore) {
            return null;
        }

        $order->loadMissing('restaurant');
        $barLocationId = $order->restaurant?->bar_location_id;
        $barStore = $barLocationId
            ? InventoryLocation::find($barLocationId)
            : InventoryLocation::query()->where('type', 'bar_store')->where('department_id', $order->restaurant?->department_id)->first();

        $batchItems = $order->items()
            ->where('kot_sent', true)
            ->where('status', 'active')
            ->where('kot_batch', $batch)
            ->where('inventory_deducted', false)
            ->with('menuItem')
            ->get();

        if ($batchItems->isEmpty()) {
            return null;
        }

        $refId = (string) $order->id.'-'.$batch;
        $result = DB::transaction(function () use ($order, $batch, $batchItems, $kitchenStore, $barStore, $refId) {
            foreach ($batchItems as $orderItem) {
                $targetStore = $this->resolveInventoryDeductionStore($orderItem->menuItem, $kitchenStore, $barStore);
                $this->deductOrderItemInventory($orderItem, $targetStore, 'pos_order_batch', $refId);
            }

            return ['order_id' => $order->id, 'batch' => $batch];
        });

        return $result;
    }

    // ── Update Kitchen Status ─────────────────────────────────────────────────

    public function updateKitchenStatus(Request $request, PosOrder $order)
    {
        $this->checkPermission('pos-order');
        $this->authorizeOrderAccess($order);
        $validated = $request->validate([
            'kitchen_status' => 'required|in:pending,preparing,ready,served',
        ]);

        $allowed = match ($order->kitchen_status) {
            'pending' => ['preparing'],
            'preparing' => ['ready'],
            'ready' => ['served'],
            'served' => [],
            default => ['pending', 'preparing', 'ready', 'served'],
        };
        if (! in_array($validated['kitchen_status'], $allowed)) {
            return response()->json(['message' => "Cannot transition from {$order->kitchen_status} to {$validated['kitchen_status']}."], 422);
        }

        if ($this->isBusinessDateClosedForOrder($order)) {
            return response()->json(['message' => 'Business date is already closed for this outlet.'], 422);
        }

        $newStatus = $validated['kitchen_status'];

        return DB::transaction(function () use ($order, $newStatus) {
            $order = PosOrder::where('id', $order->id)->lockForUpdate()->first();
            $previousStatus = $order->kitchen_status;

            // Re-validate transition against the now-locked authoritative status
            $allowedFromLocked = match ($previousStatus) {
                'pending'   => ['preparing'],
                'preparing' => ['ready'],
                'ready'     => ['served'],
                'served'    => [],
                default     => ['pending', 'preparing', 'ready', 'served'],
            };
            if (! in_array($newStatus, $allowedFromLocked)) {
                return response()->json([
                    'message' => "Cannot transition from {$previousStatus} to {$newStatus}.",
                ], 422);
            }

            if ($newStatus === 'served') {
                $kotNotReady = $order->items()
                    ->where('kot_sent', true)
                    ->where('status', 'active')
                    ->whereNull('kitchen_ready_at')
                    ->exists();
                if ($kotNotReady) {
                    return response()->json([
                        'message' => 'All KOT items must be marked ready before marking served.',
                    ], 422);
                }
                $order->update(['kitchen_status' => $newStatus]);
                $order->items()
                    ->where('kot_sent', true)
                    ->where('status', 'active')
                    ->whereNull('kitchen_served_at')
                    ->update(['kitchen_served_at' => now()]);
            } elseif ($newStatus === 'ready' && $previousStatus !== 'ready') {
                $undeductedItems = $order->items()
                    ->where('status', 'active')
                    ->where('kot_sent', true)
                    ->where('inventory_deducted', false)
                    ->with(['menuItem', 'variant', 'combo.menuItems'])
                    ->get();
                if ($undeductedItems->isNotEmpty()) {
                    $insufficient = $this->checkMadeToOrderStock($order, $undeductedItems);
                    if (! empty($insufficient)) {
                        return response()->json([
                            'message' => 'Insufficient stock for some items.',
                            'insufficient' => $insufficient,
                        ], 422);
                    }
                }
                $order->update(['kitchen_status' => $newStatus]);
                $this->deductOrderInventoryCompletely($order);
                $order->refresh();
                PosOrderItem::where('order_id', $order->id)
                    ->where('kot_sent', true)
                    ->where('status', 'active')
                    ->whereNull('kitchen_ready_at')
                    ->update(['kitchen_ready_at' => now()]);
            } else {
                $order->update(['kitchen_status' => $newStatus]);
            }

            $fresh = $order->fresh();
            $this->broadcastPosOutletUpdate((int) $fresh->restaurant_id, (int) $fresh->id);

            return response()->json([
                'id' => $fresh->id,
                'kitchen_status' => $fresh->kitchen_status,
            ]);
        });
    }

    /**
     * One-stop function to ensure EVERY active item in a POS order is deducted from its relevant store.
     * Safe to call multiple times (uses inventory_deducted flag).
     */
    private function deductOrderInventoryCompletely(PosOrder $order): void
    {
        $order->loadMissing(['items.menuItem.inventoryItem', 'items.variant', 'items.combo.menuItems.inventoryItem', 'restaurant']);

        $kitchenStore = $this->getKitchenForOrder($order);

        // Bar default fallback
        $barLocationId = $order->restaurant?->bar_location_id;
        $barStore = $barLocationId ? InventoryLocation::find($barLocationId) : InventoryLocation::query()->where('type', 'bar_store')->where('department_id', $order->restaurant?->department_id)->first();

        foreach ($order->items->where('status', 'active') as $item) {
            if ($item->inventory_deducted) {
                continue;
            }

            $targetStore = $this->resolveInventoryDeductionStore($item->menuItem, $kitchenStore, $barStore);

            if ($targetStore) {
                $this->deductOrderItemInventory($item, $targetStore, 'pos_order', (string) $order->id);
            }
        }
    }

    private function deductOrderItemInventory(PosOrderItem $orderItem, InventoryLocation $location, string $refType, string $refId): void
    {
        if ($orderItem->inventory_deducted || $orderItem->status !== 'active') {
            return;
        }

        DB::transaction(function () use ($orderItem, $location, $refType, $refId) {
            $menuItemIds = $orderItem->combo_id && $orderItem->combo
                ? $orderItem->combo->menuItems->pluck('id')
                : ($orderItem->menu_item_id ? collect([$orderItem->menu_item_id]) : collect());

            foreach ($menuItemIds as $menuItemId) {
                $menuItem = MenuItem::with('inventoryItem')->find($menuItemId);
                if (! $menuItem) {
                    continue;
                }

                $recipe = Recipe::with('ingredients.inventoryItem')->where('menu_item_id', $menuItemId)->where('is_active', true)->first();

                if ($recipe && ! ($recipe->requires_production ?? true)) {
                    // CASE 1: Made-to-order Recipe (Tea, Coffee)
                    $yield = max(1, (float) ($recipe->yield_quantity ?? 1));
                    $scale = 1.0;
                    if ($orderItem->menu_item_variant_id && ($ml = (float) ($orderItem->variant?->ml_quantity ?? 0)) > 0 && $ml <= 10) {
                        // ml_quantity on recipe items is a portion multiplier (e.g. 2 = double ingredients).
                        // Guard against literal-ml values (e.g. 250 ml) being misapplied as a scale factor.
                        $scale = $ml;
                    }
                    $multiplier = ($orderItem->quantity * $scale) / $yield;
                    foreach ($recipe->ingredients as $ing) {
                        $this->executeDeduction($ing->inventory_item_id, $location->id, round($ing->raw_quantity * $multiplier, 3), $refType, $refId, "Order #{$orderItem->order_id} - {$menuItem->name}");
                    }
                } elseif ($menuItem->inventory_item_id) {
                    // CASE 2: Finished Good (Biryani) or Direct Item (Pepsi)
                    $deductQty = $orderItem->quantity;
                    // Handle variants (ML scale for Liquor)
                    if ($orderItem->menu_item_variant_id && ($ml = (float) ($orderItem->variant?->ml_quantity ?? 0)) > 0) {
                        $deductQty = $ml * $orderItem->quantity;
                    }
                    $this->executeDeduction($menuItem->inventory_item_id, $location->id, $deductQty, $refType, $refId, "Order #{$orderItem->order_id} - {$menuItem->name}");
                }
            }
            $orderItem->update(['inventory_deducted' => true]);
        });
    }

    private function reverseOrderItemInventory(PosOrderItem $orderItem, InventoryLocation $location, string $refType, string $refId): void
    {
        $this->reverseInventoryByQuantity($orderItem, $location, $orderItem->quantity, $refType, $refId);
        $orderItem->update(['inventory_deducted' => false]);
    }

    private function reverseInventoryByQuantity(PosOrderItem $orderItem, InventoryLocation $location, float $baseQty, string $refType, string $refId): void
    {
        $orderItem->loadMissing('variant');
        DB::transaction(function () use ($orderItem, $location, $baseQty, $refType, $refId) {
            $menuItemIds = $orderItem->combo_id && $orderItem->combo
                ? $orderItem->combo->menuItems->pluck('id')
                : ($orderItem->menu_item_id ? collect([$orderItem->menu_item_id]) : collect());

            foreach ($menuItemIds as $menuItemId) {
                $menuItem = MenuItem::with('inventoryItem')->find($menuItemId);
                if (! $menuItem) {
                    continue;
                }

                $recipe = Recipe::with('ingredients.inventoryItem')->where('menu_item_id', $menuItemId)->where('is_active', true)->first();

                if ($recipe && ! ($recipe->requires_production ?? true)) {
                    $yield = max(1, (float) ($recipe->yield_quantity ?? 1));
                    $scale = 1.0;
                    if ($orderItem->menu_item_variant_id && ($ml = (float) ($orderItem->variant?->ml_quantity ?? 0)) > 0 && $ml <= 10) {
                        $scale = $ml;
                    }
                    $multiplier = ($baseQty * $scale) / $yield;
                    foreach ($recipe->ingredients as $ing) {
                        $this->executeInventoryIn($ing->inventory_item_id, $location->id, round($ing->raw_quantity * $multiplier, 3), $refType, $refId, "Inventory Reversal (Cancel/Reduce): Order #{$orderItem->order_id}");
                    }
                } elseif ($menuItem->inventory_item_id) {
                    $deductQty = $baseQty;
                    if ($orderItem->menu_item_variant_id && ($ml = (float) ($orderItem->variant?->ml_quantity ?? 0)) > 0) {
                        $deductQty = $ml * $baseQty;
                    }
                    $this->executeInventoryIn($menuItem->inventory_item_id, $location->id, $deductQty, $refType, $refId, "Inventory Reversal (Cancel/Reduce): Order #{$orderItem->order_id}");
                }
            }
        });
    }

    private function executeInventoryIn(int $itemId, int $locId, float $qty, string $refType, string $refId, string $notes): void
    {
        if ($qty <= 0) {
            return;
        }
        DB::table('inventory_item_locations')->updateOrInsert(
            ['inventory_item_id' => $itemId, 'inventory_location_id' => $locId],
            ['updated_at' => now()]
        );
        DB::table('inventory_item_locations')->where('inventory_item_id', $itemId)->where('inventory_location_id', $locId)->increment('quantity', $qty);

        $invItem = InventoryItem::find($itemId);
        $unitCost = floatval($invItem?->cost_price ?? 0) / floatval($invItem?->conversion_factor ?? 1);
        $location = InventoryLocation::find($locId);

        InventoryTransaction::create([
            'inventory_item_id' => $itemId,
            'inventory_location_id' => $locId,
            'department_id' => $location?->department_id,
            'type' => 'in',
            'quantity' => $qty,
            'unit_cost' => round($unitCost, 4),
            'total_cost' => round($qty * $unitCost, 2),
            'reason' => 'Inventory Reversal',
            'notes' => $notes,
            'user_id' => auth()->id(),
            'reference_type' => $refType,
            'reference_id' => $refId,
        ]);
        InventoryItem::syncStoredCurrentStockFromLocations($itemId);
    }

    private function executeDeduction(int $itemId, int $locId, float $qty, string $refType, string $refId, string $notes): void
    {
        if ($qty <= 0) {
            return;
        }

        DB::table('inventory_item_locations')->updateOrInsert(
            ['inventory_item_id' => $itemId, 'inventory_location_id' => $locId],
            ['updated_at' => now()],
        );

        $row = DB::table('inventory_item_locations')
            ->where('inventory_item_id', $itemId)
            ->where('inventory_location_id', $locId)
            ->lockForUpdate()
            ->first();

        $current = (float) ($row->quantity ?? 0);
        if ($current + 0.0001 < $qty) {
            $inv = InventoryItem::find($itemId);
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json([
                    'message' => 'Insufficient stock to complete this deduction.',
                    'inventory_item' => $inv?->name,
                    'available' => $current,
                    'required' => $qty,
                ], 422)
            );
        }

        DB::table('inventory_item_locations')->where('inventory_item_id', $itemId)->where('inventory_location_id', $locId)->decrement('quantity', $qty);

        $invItem = InventoryItem::find($itemId);
        $unitCost = floatval($invItem?->cost_price ?? 0) / floatval($invItem?->conversion_factor ?? 1);
        $location = InventoryLocation::find($locId);

        InventoryTransaction::create([
            'inventory_item_id' => $itemId,
            'inventory_location_id' => $locId,
            'department_id' => $location?->department_id,
            'type' => 'out',
            'quantity' => $qty,
            'unit_cost' => round($unitCost, 4),
            'total_cost' => round($qty * $unitCost, 2),
            'reason' => 'POS Order',
            'notes' => $notes,
            'user_id' => auth()->id(),
            'reference_type' => $refType,
            'reference_id' => $refId,
        ]);

        InventoryItem::syncStoredCurrentStockFromLocations($itemId);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveComboTaxRegime(Combo $combo, int $restaurantId): string
    {
        $combo->loadMissing('menuItems.tax');
        foreach ($combo->menuItems as $mi) {
            $t = strtolower((string) ($mi->tax?->type ?? 'local'));
            if ($t !== 'vat') {
                return 'gst';
            }
        }

        return $combo->menuItems->isNotEmpty() ? 'vat_liquor' : 'gst';
    }

    private function comboHasInterstateGstComponent(Combo $combo): bool
    {
        $combo->loadMissing('menuItems.tax');
        foreach ($combo->menuItems as $mi) {
            if (strtolower((string) ($mi->tax?->type ?? '')) === 'inter-state') {
                return true;
            }
        }

        return false;
    }

    /** @return 'vat'|'igst'|'local_gst' */
    private function posLineTaxSupplyKind(PosOrderItem $i, PosOrder $order): string
    {
        $i->loadMissing(['menuItem.tax', 'combo.menuItems.tax']);
        if ($i->combo_id && $i->combo) {
            if ($this->resolveComboTaxRegime($i->combo, (int) $order->restaurant_id) === 'vat_liquor') {
                return 'vat';
            }

            return $this->comboHasInterstateGstComponent($i->combo) ? 'igst' : 'local_gst';
        }
        if ($i->menu_item_id && $i->menuItem?->tax) {
            $t = strtolower((string) $i->menuItem->tax->type);
            if ($t === 'vat') {
                return 'vat';
            }
            if ($t === 'inter-state') {
                return 'igst';
            }

            return 'local_gst';
        }

        return 'local_gst';
    }

    private function posLineTaxRegimeSnapshot(PosOrderItem $i, int $restaurantId): string
    {
        $i->loadMissing(['menuItem.tax', 'combo.menuItems.tax']);
        if ($i->combo_id && $i->combo) {
            return $this->resolveComboTaxRegime($i->combo, $restaurantId);
        }
        if ($i->menu_item_id && $i->menuItem?->tax) {
            return strtolower((string) $i->menuItem->tax->type) === 'vat' ? 'vat_liquor' : 'gst';
        }

        return 'gst';
    }

    private function posLineItemTaxSupplyTypeForApi(PosOrderItem $i, PosOrder $order): string
    {
        $kind = $this->posLineTaxSupplyKind($i, $order);

        return match ($kind) {
            'vat' => 'vat',
            'igst' => 'inter-state',
            default => 'local',
        };
    }

    /**
     * GST component lines for bills/receipts. Liquor (state VAT) is not listed — price is shown on the line only.
     *
     * @return array{0: array<int, array{name: string, amount: float}>, 1: array<int, array{name: string, amount: float}>} [gstLines, vatLines always empty]
     */
    private function buildPosOrderTaxDisplayLines(PosOrder $order): array
    {
        $order->loadMissing([
            'items' => fn ($q) => $q->where('status', 'active'),
            'items.menuItem.tax',
            'items.combo.menuItems.tax',
        ]);
        $activeItems = $order->items->where('status', 'active');
        $grossSubtotal = $activeItems->sum(fn ($i) => floatval($i->line_total));
        $discountAmount = 0;
        if ($order->discount_type === 'percent') {
            $discountAmount = $grossSubtotal * (floatval($order->discount_value ?? 0) / 100);
        } elseif ($order->discount_type === 'flat') {
            $discountAmount = min(floatval($order->discount_value ?? 0), $grossSubtotal);
        }
        $discountRatio = $grossSubtotal > 0 ? ($discountAmount / $grossSubtotal) : 0;

        $byLocalRate = [];
        $byIgstRate = [];
        $fmtRate = fn (float $r) => rtrim(rtrim(number_format($r, 2, '.', ''), '0'), '.');

        foreach ($activeItems as $i) {
            if ($order->tax_exempt || $order->is_complimentary) {
                continue;
            }
            [$lineTax,] = $this->posLineTaxAndNetTaxable($i, $order, $discountRatio);
            $r = round(floatval($i->tax_rate), 4);
            $kind = $this->posLineTaxSupplyKind($i, $order);
            if ($lineTax <= 0 && $r <= 0) {
                continue;
            }
            if ($kind === 'vat') {
                continue;
            }
            if ($kind === 'igst') {
                $byIgstRate[$r] = ($byIgstRate[$r] ?? 0) + $lineTax;
            } else {
                $byLocalRate[$r] = ($byLocalRate[$r] ?? 0) + $lineTax;
            }
        }

        $gstLines = [];
        krsort($byLocalRate, SORT_NUMERIC);
        foreach ($byLocalRate as $rate => $total) {
            $total = round((float) $total, 2);
            if ($total <= 0 || $rate <= 0) {
                continue;
            }
            $half = $rate / 2;
            $cgst = round($total / 2, 2);
            $sgst = round($total - $cgst, 2);
            $gstLines[] = ['name' => 'CGST @ '.$fmtRate($half).'%', 'amount' => $cgst];
            $gstLines[] = ['name' => 'SGST @ '.$fmtRate($half).'%', 'amount' => $sgst];
        }
        krsort($byIgstRate, SORT_NUMERIC);
        foreach ($byIgstRate as $rate => $total) {
            $total = round((float) $total, 2);
            if ($total <= 0 || $rate <= 0) {
                continue;
            }
            $gstLines[] = ['name' => 'IGST @ '.$fmtRate($rate).'%', 'amount' => $total];
        }

        return [$gstLines, []];
    }

    private function recalculate(PosOrder $order): void
    {
        $order->refresh();
        $order->load(['items' => function ($q) {
            $q->where('status', 'active');
        }, 'items.menuItem.tax', 'items.combo.menuItems.tax']);

        $activeItems = $order->items;
        
        // 1. Calculate Gross Sum (Menu Prices * Qty)
        $grossSubtotal = $activeItems->sum(fn ($i) => floatval($i->line_total));

        // 2. Calculate Order-level Discount
        $discountAmount = 0;
        if ($order->discount_type === 'percent') {
            $discountAmount = $grossSubtotal * (floatval($order->discount_value ?? 0) / 100);
        } elseif ($order->discount_type === 'flat') {
            $discountAmount = min(floatval($order->discount_value ?? 0), $grossSubtotal);
        }

        $discountRatio = $grossSubtotal > 0 ? ($discountAmount / $grossSubtotal) : 0;
        
        // 3. Extract Tax and calculate true Net Subtotal; CGST/SGST/IGST/VAT buckets
        $totalTaxAmount = 0.0;
        $totalNetTaxable = 0.0;
        $localGstTax = 0.0;
        $igstTax = 0.0;
        $vatTax = 0.0;
        $gstNetTaxable = 0.0;
        $vatNetTaxable = 0.0;
        $restaurantId = (int) $order->restaurant_id;

        foreach ($activeItems as $i) {
            $reg = $this->posLineTaxRegimeSnapshot($i, $restaurantId);
            if (($i->tax_regime ?? null) !== $reg) {
                PosOrderItem::where('id', $i->id)->update(['tax_regime' => $reg]);
                $i->tax_regime = $reg;
            }
            [$lineTax, $lineNet] = $this->posLineTaxAndNetTaxable($i, $order, $discountRatio);
            $totalTaxAmount += $lineTax;
            $totalNetTaxable += $lineNet;
            $kind = $this->posLineTaxSupplyKind($i, $order);
            if ($kind === 'vat') {
                $vatTax += $lineTax;
                $vatNetTaxable += $lineNet;
            } elseif ($kind === 'igst') {
                $igstTax += $lineTax;
                $gstNetTaxable += $lineNet;
            } else {
                $localGstTax += $lineTax;
                $gstNetTaxable += $lineNet;
            }
        }

        $cgstAmount = round($localGstTax / 2, 2);
        $sgstAmount = round($localGstTax - $cgstAmount, 2);

        // 4. Other charges (Service Charge, Tips, Delivery)
        // Usually, Service Charge is calculated on the Gross Subtotal
        $serviceChargeAmount = 0;
        if ($order->service_charge_type === 'percent') {
            $serviceChargeAmount = $grossSubtotal * (floatval($order->service_charge_value ?? 0) / 100);
        } elseif ($order->service_charge_type === 'flat') {
            $serviceChargeAmount = min(floatval($order->service_charge_value ?? 0), $grossSubtotal);
        }

        $tipAmount = (float) ($order->tip_amount ?? 0);
        $deliveryCharge = $order->order_type === 'delivery' ? (float) ($order->delivery_charge ?? 0) : 0;

        // 5. Final Total Order Amount
        if ($order->is_complimentary) {
            $finalTotal = 0.0;
            $serviceChargeAmount = 0;
            $tipAmount = 0;
            $totalTaxAmount = 0;
            $cgstAmount = 0;
            $sgstAmount = 0;
            $igstTax = 0;
            $vatTax = 0;
            $gstNetTaxable = 0;
            $vatNetTaxable = 0;
        } else {
            // Gross Total = Items (After Discount) + Tax (if extra) + Extras
            // Note: If inclusive, Tax is already in effGross, but linePaySum calculation below is clearer
            $billPaySum = $activeItems->sum(function($i) use ($discountRatio, $order) {
                $eff = floatval($i->line_total) * (1 - $discountRatio);
                $r = floatval($i->tax_rate);
                if ($this->linePriceTaxInclusive($i, $order)) {
                    return $eff;
                } else {
                    return $eff * (1 + $r / 100);
                }
            });
            
            if ($order->tax_exempt) {
                $billPaySum = $activeItems->sum(fn($i) => floatval($i->line_total) * (1 - $discountRatio));
            }

            $finalTotal = round($billPaySum + $serviceChargeAmount + $tipAmount + $deliveryCharge, 2);
        }

        $order->update([
            'subtotal' => round($totalNetTaxable, 2), // Now stores True Net Taxable
            'tax_amount' => round($totalTaxAmount, 2),
            'cgst_amount' => $cgstAmount,
            'sgst_amount' => $sgstAmount,
            'igst_amount' => round($igstTax, 2),
            'vat_tax_amount' => round($vatTax, 2),
            'gst_net_taxable' => round($gstNetTaxable, 2),
            'vat_net_taxable' => round($vatNetTaxable, 2),
            'service_charge_amount' => round($serviceChargeAmount, 2),
            'discount_amount' => round($discountAmount, 2),
            'tip_amount' => $tipAmount,
            'rounding_amount' => 0,
            'total_amount' => $finalTotal,
        ]);
    }

    /**
     * Tax amount and net taxable base for one line after bill-level discount ratio (same rules as receipts).
     *
     * @return array{0: float, 1: float} [tax_amount, net_taxable]
     */
    private function posLineTaxAndNetTaxable(PosOrderItem $i, PosOrder $order, float $discountRatio): array
    {
        $effGross = floatval($i->line_total) * (1 - $discountRatio);
        $r = floatval($i->tax_rate);

        if ($order->tax_exempt || $order->is_complimentary) {
            return [0.0, $effGross];
        }

        if ($this->linePriceTaxInclusive($i, $order)) {
            $lineTax = $r > 0 ? $effGross * ($r / (100 + $r)) : 0;
            $lineNet = $effGross - $lineTax;

            return [$lineTax, $lineNet];
        }

        $lineTax = $effGross * ($r / 100);
        $lineNet = $effGross;

        return [$lineTax, $lineNet];
    }

    private function resolvePosLineTaxLabel(PosOrderItem $i, float $rate): string
    {
        if ($i->menu_item_id && $i->relationLoaded('menuItem') && $i->menuItem?->tax) {
            return (string) $i->menuItem->tax->name;
        }
        if ($i->combo_id) {
            $rStr = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');

            return 'Combo (blended'.($rStr !== '' && (float) $rStr > 0 ? ' @ '.$rStr.'%' : '').')';
        }
        if ($rate <= 0) {
            return 'Nil rate';
        }

        return 'GST '.number_format($rate, 2).'%';
    }

    /** Per-line sell price includes tax (restaurant_menu_items / snapshot); falls back to order outlet default. */
    private function linePriceTaxInclusive(PosOrderItem $item, PosOrder $order): bool
    {
        if ($item->price_tax_inclusive !== null) {
            return (bool) $item->price_tax_inclusive;
        }

        return (bool) ($order->prices_tax_inclusive ?? true);
    }

    /**
     * Single effective GST % for the combo line so recalculate() can use the same
     * inclusive/exclusive formulas as normal items.
     *
     * Blends embedded tax from each component using outlet prices and each RMI's
     * price_tax_inclusive (defaults to inclusive). Avoids the old bug of treating
     * inclusive MRPs as pre-tax bases (which inflated the effective rate).
     */
    private function resolveComboTaxRate(Combo $combo, int $restaurantId): float
    {
        $combo->loadMissing('menuItems.tax');
        if ($combo->menuItems->isEmpty()) {
            return 0.0;
        }

        $rmiByItem = RestaurantMenuItem::where('restaurant_master_id', $restaurantId)
            ->whereIn('menu_item_id', $combo->menuItems->pluck('id'))
            ->get()
            ->keyBy('menu_item_id');

        $grossSum = 0.0;
        $taxSum = 0.0;

        foreach ($combo->menuItems as $mi) {
            $rmi = $rmiByItem->get($mi->id);
            $base = (float) ($rmi?->price ?? $mi->price ?? 0);
            if ($base <= 0) {
                continue;
            }
            $r = (float) ($mi->tax?->rate ?? 0);
            $inclusive = (bool) ($rmi?->price_tax_inclusive ?? true);

            if ($inclusive) {
                $grossSum += $base;
                if ($r > 0) {
                    $taxSum += $base * ($r / (100 + $r));
                }
            } else {
                $grossSum += $base * (1 + $r / 100);
                if ($r > 0) {
                    $taxSum += $base * ($r / 100);
                }
            }
        }

        if ($grossSum <= 0 || $taxSum <= 0) {
            return 0.0;
        }

        $net = $grossSum - $taxSum;
        if ($net <= 1e-6) {
            return 0.0;
        }

        // R such that grossSum * R/(100+R) = taxSum  =>  R = 100 * taxSum / net
        return round((100.0 * $taxSum) / $net, 2);
    }

    private function formatOrder(PosOrder $order): array
    {
        $order->loadMissing([
            'restaurant',
            'items.menuItem.tax',
            'items.menuItem.category',
            'items.combo',
            'items.combo.menuItems.tax',
            'items.variant',
            'payments',
            'refunds',
            'room',
            'table',
            'waiter',
            'openedBy',
            'voidedBy',
            'discountApprovedBy',
        ]);
        [$gstTaxLines, $vatTaxLines] = $this->buildPosOrderTaxDisplayLines($order);

        return [
            'id' => $order->id,
            'order_type' => $order->order_type ?? 'dine_in',
            'table_id' => $order->table_id,
            'restaurant_id' => $order->restaurant_id,
            'business_date' => $order->business_date?->toDateString(),
            'room_id' => $order->room_id,
            'room_number' => $order->room?->room_number ?? null,
            'table_number' => $order->table?->table_number ?? null,
            'booking_id' => $order->booking_id,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'customer_gstin' => $order->customer_gstin,
            'delivery_address' => $order->delivery_address,
            'delivery_channel' => $order->delivery_channel,
            'covers' => $order->covers,
            'waiter_id' => $order->waiter_id,
            'waiter' => $order->waiter ? ['id' => $order->waiter->id, 'name' => $order->waiter->name] : null,
            'opened_by' => $order->opened_by,
            'opened_by_user' => $order->openedBy ? ['id' => $order->openedBy->id, 'name' => $order->openedBy->name] : null,
            'status' => $order->status,
            'kitchen_status' => $order->kitchen_status ?? 'pending',
            'current_kot_batch' => (int) ($order->current_kot_batch ?? 0),
            'ready_batches' => $this->kotBatchNumbersWhereAllLinesHave($order, 'kitchen_ready_at'),
            'served_batches' => $this->kotBatchNumbersWhereAllLinesHave($order, 'kitchen_served_at'),
            'discount_type' => $order->discount_type,
            'discount_value' => (float) $order->discount_value,
            'service_charge_type' => $order->service_charge_type,
            'service_charge_value' => (float) ($order->service_charge_value ?? 0),
            'service_charge_amount' => (float) ($order->service_charge_amount ?? 0),
            'subtotal' => (float) $order->subtotal,
            'tax_amount' => (float) $order->tax_amount,
            'cgst_amount' => (float) ($order->cgst_amount ?? 0),
            'sgst_amount' => (float) ($order->sgst_amount ?? 0),
            'igst_amount' => (float) ($order->igst_amount ?? 0),
            'vat_tax_amount' => (float) ($order->vat_tax_amount ?? 0),
            'gst_net_taxable' => (float) ($order->gst_net_taxable ?? 0),
            'vat_net_taxable' => (float) ($order->vat_net_taxable ?? 0),
            'tax_breakdown' => $gstTaxLines,
            'vat_breakdown' => $vatTaxLines,
            'discount_amount' => (float) $order->discount_amount,
            'tip_amount' => (float) ($order->tip_amount ?? 0),
            'rounding_amount' => (float) ($order->rounding_amount ?? 0),
            'delivery_charge' => (float) ($order->delivery_charge ?? 0),
            'is_complimentary' => (bool) $order->is_complimentary,
            'discount_approved_by' => $order->discount_approved_by,
            'discount_approved_by_user' => $order->discountApprovedBy ? ['id' => $order->discountApprovedBy->id, 'name' => $order->discountApprovedBy->name] : null,
            'discount_approved_at' => $order->discount_approved_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
            'total_amount' => (float) $order->total_amount,
            'opened_at' => $order->opened_at,
            'closed_at' => $order->closed_at,
            'notes' => $order->notes,
            'tax_exempt' => (bool) ($order->tax_exempt ?? false),
            'prices_tax_inclusive' => (bool) ($order->prices_tax_inclusive ?? true),
            'receipt_show_tax_breakdown' => (bool) ($order->receipt_show_tax_breakdown ?? true),
            'void_reason' => $order->void_reason,
            'void_notes' => $order->void_notes,
            'voided_by' => $order->voided_by,
            'voided_by_user' => $order->voidedBy ? ['id' => $order->voidedBy->id, 'name' => $order->voidedBy->name] : null,
            'voided_at' => $order->voided_at?->toIso8601String(),
            'items' => $order->items->where('status', 'active')->values()->map(fn ($i) => [
                'id' => $i->id,
                'menu_item_id' => $i->menu_item_id,
                'menu_item_variant_id' => $i->menu_item_variant_id,
                'combo_id' => $i->combo_id,
                'name' => $i->combo_id ? ($i->combo?->name ?? 'Combo') : (
                    $i->menu_item_variant_id ? ($i->menuItem?->name ?? 'Unknown').' — '.($i->variant?->size_label ?? '') : ($i->menuItem?->name ?? 'Unknown')
                ),
                'category' => $i->menuItem?->category?->name ?? ($i->combo_id ? 'Combo' : null),
                'type' => $i->combo_id ? 'combo' : ($i->menuItem?->type ?? null),
                'quantity' => $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'tax_rate' => (float) $i->tax_rate,
                'tax_name' => $i->menuItem?->tax?->name ?? null,
                'tax_regime' => $i->tax_regime ?: $this->posLineTaxRegimeSnapshot($i, (int) $order->restaurant_id),
                'tax_supply_type' => $this->posLineItemTaxSupplyTypeForApi($i, $order),
                'price_tax_inclusive' => $i->price_tax_inclusive === null ? null : (bool) $i->price_tax_inclusive,
                'line_total' => (float) $i->line_total,
                'kot_sent' => $i->kot_sent,
                'kot_hold' => (bool) ($i->kot_hold ?? false),
                'kot_batch' => $i->kot_batch,
                'kot_started_at' => $i->kot_started_at?->toIso8601String(),
                'ml_quantity' => $i->menu_item_variant_id && $i->variant ? (float) ($i->variant->ml_quantity ?? 1) : 1,
                'requires_production' => (bool) ($i->menuItem?->requires_production ?? true),
                'is_direct_sale' => (bool) ($i->menuItem?->is_direct_sale ?? false),
                'kitchen_ready_at' => $i->kitchen_ready_at?->toIso8601String(),
                'kitchen_served_at' => $i->kitchen_served_at?->toIso8601String(),
                'notes' => $i->notes,
            ]),
            'cancellations' => $order->items->where('status', 'cancelled')->values()->map(fn ($i) => [
                'id' => $i->id,
                'menu_item_id' => $i->menu_item_id,
                'combo_id' => $i->combo_id,
                'name' => $i->combo_id ? ($i->combo?->name ?? 'Combo') : (
                    $i->menu_item_variant_id ? ($i->menuItem?->name ?? 'Unknown').' — '.($i->variant?->size_label ?? '') : ($i->menuItem?->name ?? 'Unknown')
                ),
                'quantity' => $i->quantity,
                'kot_batch' => $i->kot_batch,
                'cancel_reason' => $i->cancel_reason,
                'cancel_notes' => $i->cancel_notes,
                'cancelled_at' => $i->cancelled_at?->toIso8601String(),
            ]),
            'payments' => $order->payments->map(fn ($p) => [
                'id' => $p->id,
                'method' => $p->method,
                'amount' => (float) $p->amount,
                'reference_no' => $p->reference_no,
                'paid_at' => $p->paid_at,
            ]),
            'refunds' => $order->refunds->map(fn ($r) => [
                'id' => $r->id,
                'amount' => (float) $r->amount,
                'method' => $r->method,
                'reference_no' => $r->reference_no,
                'reason' => $r->reason,
                'refunded_at' => $r->refunded_at,
            ]),
            'refunded_amount' => (float) $order->refunds->sum('amount'),
        ];
    }

    /**
     * @param  array<int, string>  $datesYmd
     * @return array<string, array{day_closed_at: ?string, day_closed_by: string}>
     */
    private function posDayClosingMapForDates(int $restaurantId, array $datesYmd): array
    {
        if ($datesYmd === []) {
            return [];
        }

        $rows = PosDayClosing::query()
            ->where('restaurant_id', $restaurantId)
            ->whereIn('closed_date', $datesYmd)
            ->with('closedByUser:id,name')
            ->get();

        $map = [];
        foreach ($rows as $c) {
            $key = $c->closed_date instanceof \Carbon\CarbonInterface
                ? $c->closed_date->format('Y-m-d')
                : (string) $c->closed_date;
            $map[$key] = [
                'day_closed_at' => $c->closed_at?->toDateTimeString(),
                'day_closed_by' => $c->closedByUser?->name ?? '—',
            ];
        }

        return $map;
    }

    /**
     * @return array{by_type: list<array{order_type: string, orders_count: int, gross_revenue: float, refunded_amount: float, net_revenue: float}>, totals: array{orders_count: int, gross_revenue: float, refunded_amount: float, net_revenue: float}}
     */
    private function buildOrderTypeMixData(int $restaurantId, string $from, string $to): array
    {
        $all = $this->handleOrderTypeMixBucket($restaurantId, $from, $to, 'all');
        $food = $this->handleOrderTypeMixBucket($restaurantId, $from, $to, 'food');
        $liquor = $this->handleOrderTypeMixBucket($restaurantId, $from, $to, 'liquor');

        return [
            'all' => $all,
            'food' => $food,
            'liquor' => $liquor,
            'by_type' => $all['by_type'],
            'totals' => $all['totals'],
        ];
    }

    private function handleOrderTypeMixBucket(int $restaurantId, string $from, string $to, string $category): array
    {
        // 1. Gross Revenue & Orders Count
        $grossQuery = DB::table('pos_orders as po')
            ->whereIn('po.status', ['paid', 'refunded'])
            ->where('po.restaurant_id', $restaurantId)
            ->whereDate('po.business_date', '>=', $from)
            ->whereDate('po.business_date', '<=', $to);

        if ($category !== 'all') {
            $isLiquor = $category === 'liquor';
            $grossQuery->whereExists(function ($q) use ($isLiquor) {
                $q->select(DB::raw(1))
                    ->from('pos_order_items as poi')
                    ->leftJoin('menu_items as mi', 'poi.menu_item_id', '=', 'mi.id')
                    ->leftJoin('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                    ->whereColumn('poi.order_id', 'po.id')
                    ->where('poi.status', 'active');
                if ($isLiquor) {
                    $q->where(function ($w) {
                        $w->where('poi.tax_regime', 'vat_liquor')
                            ->orWhereRaw('LOWER(it.type) = ?', ['vat'])
                            ->orWhereExists(function ($sq) {
                                $sq->select(DB::raw(1))
                                    ->from('combo_items as ci')
                                    ->join('menu_items as mi2', 'ci.menu_item_id', '=', 'mi2.id')
                                    ->join('inventory_taxes as it2', 'mi2.tax_id', '=', 'it2.id')
                                    ->whereColumn('ci.combo_id', 'poi.combo_id')
                                    ->whereNotNull('poi.combo_id')
                                    ->whereRaw('LOWER(it2.type) = ?', ['vat']);
                            });
                    });
                } else {
                    // Food: everything NOT liquor
                    $q->whereNot(function ($w) {
                        $w->where('poi.tax_regime', 'vat_liquor')
                            ->orWhereRaw('LOWER(it.type) = ?', ['vat'])
                            ->orWhereExists(function ($sq) {
                                $sq->select(DB::raw(1))
                                    ->from('combo_items as ci')
                                    ->join('menu_items as mi2', 'ci.menu_item_id', '=', 'mi2.id')
                                    ->join('inventory_taxes as it2', 'mi2.tax_id', '=', 'it2.id')
                                    ->whereColumn('ci.combo_id', 'poi.combo_id')
                                    ->whereNotNull('poi.combo_id')
                                    ->whereRaw('LOWER(it2.type) = ?', ['vat']);
                            });
                    });
                }
            });
        }

        if ($category === 'all') {
            $grossData = (clone $grossQuery)->select(
                DB::raw('COALESCE(po.order_type, \'dine_in\') as order_type'),
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('SUM(po.total_amount) as gross_revenue')
            )
            ->groupBy(DB::raw('COALESCE(po.order_type, \'dine_in\')'))
            ->get()->keyBy('order_type');

            $refundQuery = DB::table('pos_order_refunds as por')
                ->join('pos_orders as po', 'por.order_id', '=', 'po.id')
                ->where('po.restaurant_id', $restaurantId)
                ->whereDate('por.business_date', '>=', $from)
                ->whereDate('por.business_date', '<=', $to);

            $refundData = (clone $refundQuery)->select(
                DB::raw('COALESCE(po.order_type, \'dine_in\') as order_type'),
                DB::raw('SUM(por.amount) as refund_amount')
            )
            ->groupBy(DB::raw('COALESCE(po.order_type, \'dine_in\')'))
            ->get()->keyBy('order_type');

            $grossByType = $grossData;
            $refunds = $refundData->pluck('refund_amount', 'order_type')->map(fn ($v) => (float)$v);
        } else {
            $isLiquor = $category === 'liquor';
            $lineGross = "(CASE WHEN poi.price_tax_inclusive THEN poi.line_total ELSE poi.line_total * (1 + COALESCE(poi.tax_rate, 0)/100) END)";
            $totalGross = "(SELECT COALESCE(SUM(CASE WHEN ps.price_tax_inclusive THEN ps.line_total ELSE ps.line_total * (1+COALESCE(ps.tax_rate,0)/100) END), 0) FROM pos_order_items ps WHERE ps.order_id = po.id AND ps.status = 'active')";

            // 1. Bill Counts (Fastest SQL)
            $grossByType = (clone $grossQuery)->select(
                DB::raw('COALESCE(po.order_type, \'dine_in\') as order_type'),
                DB::raw('COUNT(*) as orders_count')
            )
            ->groupBy(DB::raw('COALESCE(po.order_type, \'dine_in\')'))
            ->get()->keyBy('order_type');

            // 2. Revenue - Sum of categorical shares
            $revRows = DB::table('pos_order_items as poi')
                ->join('pos_orders as po', 'poi.order_id', '=', 'po.id')
                ->leftJoin('menu_items as mi', 'poi.menu_item_id', '=', 'mi.id')
                ->leftJoin('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                ->whereIn('po.status', ['paid', 'refunded'])
                ->where('po.restaurant_id', $restaurantId)
                ->whereDate('po.business_date', '>=', $from)
                ->whereDate('po.business_date', '<=', $to)
                ->where('poi.status', 'active')
                ->where(function($q) use ($isLiquor) {
                    $itemIsLiquor = " (poi.tax_regime = 'vat_liquor' OR (it.type IS NOT NULL AND LOWER(it.type) = 'vat') OR EXISTS (
                        SELECT 1 FROM combo_items ci
                        JOIN menu_items mi2 ON ci.menu_item_id = mi2.id
                        JOIN inventory_taxes it2 ON mi2.tax_id = it2.id
                        WHERE ci.combo_id = poi.combo_id AND LOWER(it2.type) = 'vat'
                    )) ";
                    if ($isLiquor) $q->whereRaw($itemIsLiquor);
                    else $q->whereRaw("NOT $itemIsLiquor");
                })
                ->select(
                    DB::raw('COALESCE(po.order_type, \'dine_in\') as order_type'),
                    DB::raw("SUM($lineGross * (CASE WHEN po.total_amount > 0 THEN po.total_amount / NULLIF($totalGross, 0) ELSE 1 END)) as gross_revenue")
                )
                ->groupBy(DB::raw('COALESCE(po.order_type, \'dine_in\')'))
                ->get()->keyBy('order_type');

            foreach ($grossByType as $type => $row) {
                $row->gross_revenue = $revRows[$type]->gross_revenue ?? 0.0;
            }

            // 3. Refunds — per-refund allocation in inner query, then SUM by order_type (MySQL ONLY_FULL_GROUP_BY)
            $vatFilter = ($isLiquor ? "" : "NOT ") . "(
                            poi.tax_regime = 'vat_liquor'
                            OR it.type IS NOT NULL AND LOWER(it.type) = 'vat'
                            OR EXISTS (
                                SELECT 1 FROM combo_items ci2
                                JOIN menu_items mi4 ON ci2.menu_item_id = mi4.id
                                JOIN inventory_taxes it4 ON mi4.tax_id = it4.id
                                WHERE ci2.combo_id = poi.combo_id AND LOWER(it4.type) = 'vat'
                            )
                        )";
            $refundAllocSub = DB::table('pos_order_refunds as por')
                ->join('pos_orders as po', 'por.order_id', '=', 'po.id')
                ->where('po.restaurant_id', $restaurantId)
                ->whereDate('por.business_date', '>=', $from)
                ->whereDate('por.business_date', '<=', $to)
                ->select(
                    DB::raw('COALESCE(po.order_type, \'dine_in\') as order_type'),
                    DB::raw("(por.amount * (
                        SELECT COALESCE(SUM($lineGross), 0)
                        FROM pos_order_items poi
                        LEFT JOIN menu_items mi ON poi.menu_item_id = mi.id
                        LEFT JOIN inventory_taxes it ON mi.tax_id = it.id
                        WHERE poi.order_id = po.id AND poi.status = 'active'
                        AND $vatFilter
                    ) / NULLIF($totalGross, 0)) as alloc")
                );
            $refRows = DB::query()
                ->fromSub($refundAllocSub, 'allocated')
                ->select('order_type', DB::raw('SUM(alloc) as refund_amount'))
                ->groupBy('order_type')
                ->get();
            $refunds = $refRows->pluck('refund_amount', 'order_type')->map(fn ($v) => (float)$v);
        }

        $gross = $grossByType;

        // 3. Assemble
        $preferredOrder = ['dine_in', 'takeaway', 'delivery', 'room_service', 'walk_in'];
        $keys = $gross->keys()->merge($refunds->keys())->unique()->values();
        $orderedTypes = [];
        foreach ($preferredOrder as $p) {
            if ($keys->contains($p)) { $orderedTypes[] = $p; }
        }
        foreach ($keys->sort()->values() as $k) {
            if (! in_array($k, $orderedTypes, true)) { $orderedTypes[] = $k; }
        }

        $byType = [];
        $totOrders = 0; $totGross = 0.0; $totRef = 0.0; $totNet = 0.0;

        foreach ($orderedTypes as $type) {
            $row = $gross->get($type);
            $ordersCount = $row ? (int) $row->orders_count : 0;
            $grossRev = $row ? (float) $row->gross_revenue : 0.0;
            $refAmt = (float) ($refunds[$type] ?? 0);
            $netRev = $grossRev - $refAmt;

            $byType[] = [
                'order_type' => $type,
                'orders_count' => $ordersCount,
                'gross_revenue' => round($grossRev, 2),
                'refunded_amount' => round($refAmt, 2),
                'net_revenue' => round($netRev, 2),
            ];

            $totOrders += $ordersCount; $totGross += $grossRev; $totRef += $refAmt; $totNet += $netRev;
        }

        return [
            'by_type' => $byType,
            'totals' => [
                'orders_count' => $totOrders,
                'gross_revenue' => round($totGross, 2),
                'refunded_amount' => round($totRef, 2),
                'net_revenue' => round($totNet, 2),
            ],
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function buildMenuPerformanceRows(int $restaurantId, string $from, string $to)
    {
        $menuRows = DB::table('pos_order_items as poi')
            ->join('pos_orders as po', 'poi.order_id', '=', 'po.id')
            ->join('menu_items as mi', 'poi.menu_item_id', '=', 'mi.id')
            ->leftJoin('menu_item_variants as miv', 'poi.menu_item_variant_id', '=', 'miv.id')
            ->leftJoin('menu_categories as mc', 'mi.menu_category_id', '=', 'mc.id')
            ->whereIn('po.status', ['paid', 'refunded'])
            ->where('po.restaurant_id', $restaurantId)
            ->whereDate('po.business_date', '>=', $from)
            ->whereDate('po.business_date', '<=', $to)
            ->where('poi.status', 'active')
            ->whereNotNull('poi.menu_item_id')
            ->whereNull('poi.combo_id')
            ->groupBy('mi.id', 'poi.menu_item_variant_id')
            ->select(
                DB::raw("'menu_item' as row_kind"),
                'mi.id as menu_item_id',
                DB::raw('NULL as combo_id'),
                'poi.menu_item_variant_id as variant_id',
                DB::raw('MAX(mi.name) as name'),
                DB::raw('MAX(COALESCE(miv.size_label, \'\')) as variant_label'),
                DB::raw('MAX(mc.name) as category_name'),
                DB::raw('SUM(poi.quantity) as qty_sold'),
                DB::raw('SUM(poi.line_total) as revenue'),
                DB::raw('COUNT(*) as lines_sold'),
                DB::raw('COUNT(DISTINCT poi.order_id) as bills_count'),
                DB::raw("MAX(CASE WHEN poi.tax_regime = 'vat_liquor' OR (it.type IS NOT NULL AND LOWER(it.type) = 'vat') THEN 1 ELSE 0 END) as is_liquor")
            )
            ->leftJoin('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
            ->get();

        $comboRows = DB::table('pos_order_items as poi')
            ->join('pos_orders as po', 'poi.order_id', '=', 'po.id')
            ->join('combos as c', 'poi.combo_id', '=', 'c.id')
            ->whereIn('po.status', ['paid', 'refunded'])
            ->where('po.restaurant_id', $restaurantId)
            ->whereDate('po.business_date', '>=', $from)
            ->whereDate('po.business_date', '<=', $to)
            ->where('poi.status', 'active')
            ->whereNotNull('poi.combo_id')
            ->groupBy('c.id')
            ->select(
                DB::raw("'combo' as row_kind"),
                DB::raw('NULL as menu_item_id'),
                'c.id as combo_id',
                DB::raw('NULL as variant_id'),
                DB::raw('MAX(c.name) as name'),
                DB::raw("'' as variant_label"),
                DB::raw("'Combo' as category_name"),
                DB::raw('SUM(poi.quantity) as qty_sold'),
                DB::raw('SUM(poi.line_total) as revenue'),
                DB::raw('COUNT(*) as lines_sold'),
                DB::raw('COUNT(DISTINCT poi.order_id) as bills_count'),
                DB::raw("(CASE WHEN EXISTS (
                    SELECT 1 FROM combo_items ci_inner
                    JOIN menu_items mi_inner ON ci_inner.menu_item_id = mi_inner.id
                    JOIN inventory_taxes it_inner ON mi_inner.tax_id = it_inner.id
                    WHERE ci_inner.combo_id = c.id AND LOWER(it_inner.type) = 'vat'
                ) THEN 1 ELSE 0 END) as is_liquor")
            )
            ->get();

        return $menuRows->concat($comboRows)->sortByDesc(fn ($r) => (float) $r->revenue)->values();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array{sku_rows: int, qty_sold: float, revenue: float, bills_with_sales: int}
     */
    private function buildMenuPerformanceSummary(int $restaurantId, string $from, string $to, $rows, string $category = 'all'): array
    {
        $billCountQuery = DB::table('pos_order_items as poi')
            ->join('pos_orders as po', 'poi.order_id', '=', 'po.id')
            ->whereIn('po.status', ['paid', 'refunded'])
            ->where('po.restaurant_id', $restaurantId)
            ->whereDate('po.business_date', '>=', $from)
            ->whereDate('po.business_date', '<=', $to)
            ->where('poi.status', 'active');

        if ($category !== 'all') {
            $isLiquor = $category === 'bar';
            $billCount = $billCountQuery->where(function ($q) use ($isLiquor) {
                $q->whereNotNull('poi.menu_item_id')
                    ->whereExists(function ($sq) use ($isLiquor) {
                        $sq->select(DB::raw(1))
                            ->from('menu_items as mi')
                            ->join('inventory_taxes as it', 'mi.tax_id', '=', 'it.id')
                            ->whereColumn('mi.id', 'poi.menu_item_id')
                            ->whereRaw('LOWER(it.type) ' . ($isLiquor ? '=' : '!=') . ' ?', ['vat']);
                    })
                    ->orWhereNotNull('poi.combo_id')
                    ->whereExists(function ($sq) use ($isLiquor) {
                        $sq->select(DB::raw(1))
                            ->from('combo_items as ci')
                            ->join('menu_items as mi2', 'ci.menu_item_id', '=', 'mi2.id')
                            ->join('inventory_taxes as it2', 'mi2.tax_id', '=', 'it2.id')
                            ->whereColumn('ci.combo_id', 'poi.combo_id')
                            ->whereRaw('LOWER(it2.type) ' . ($isLiquor ? '=' : '!=') . ' ?', ['vat']);
                    });
            })
            ->selectRaw('COUNT(DISTINCT poi.order_id) as c')
            ->value('c');
        } else {
            $billCount = $billCountQuery->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->whereNotNull('poi.menu_item_id')->whereNull('poi.combo_id');
                })->orWhereNotNull('poi.combo_id');
            })
            ->selectRaw('COUNT(DISTINCT poi.order_id) as c')
            ->value('c');
        }

        return [
            'sku_rows' => $rows->count(),
            'qty_sold' => round((float) $rows->sum(fn ($r) => (float) $r->qty_sold), 2),
            'revenue' => round((float) $rows->sum(fn ($r) => (float) $r->revenue), 2),
            'bills_with_sales' => $billCount,
        ];
    }

    /**
     * @return array{by_rate: list<array{rate: float, tax_label: string, taxable_value: float, tax_amount: float, line_count: int}>, totals: array{taxable_value: float, tax_amount: float, bills_count: int, bucket_count: int}}
     */
    private function buildTaxGstSummaryData(int $restaurantId, string $from, string $to): array
    {
        $orders = PosOrder::query()
            ->whereIn('status', ['paid', 'refunded'])
            ->where('restaurant_id', $restaurantId)
            ->where('is_complimentary', false)
            ->whereDate('business_date', '>=', $from)
            ->whereDate('business_date', '<=', $to)
            ->with([
                'items' => fn ($q) => $q->where('status', 'active')->with(['menuItem.tax', 'combo.menuItems.tax']),
            ])
            ->get();

        $gstBuckets = [];
        $vatBuckets = [];

        foreach ($orders as $order) {
            $activeItems = $order->items;
            $grossSubtotal = $activeItems->sum(fn ($i) => floatval($i->line_total));
            $discountAmount = 0;
            if ($order->discount_type === 'percent') {
                $discountAmount = $grossSubtotal * (floatval($order->discount_value ?? 0) / 100);
            } elseif ($order->discount_type === 'flat') {
                $discountAmount = min(floatval($order->discount_value ?? 0), $grossSubtotal);
            }
            $discountRatio = $grossSubtotal > 0 ? ($discountAmount / $grossSubtotal) : 0;

            foreach ($activeItems as $i) {
                [$lineTax, $lineNet] = $this->posLineTaxAndNetTaxable($i, $order, $discountRatio);
                $r = round((float) $i->tax_rate, 2);
                $kind = $this->posLineTaxSupplyKind($i, $order);

                if ($order->tax_exempt) {
                    $label = 'Bill tax exempt';
                    $r = 0.0;
                } else {
                    $label = $this->resolvePosLineTaxLabel($i, $r);
                }

                // Separate GST from VAT
                if ($kind === 'vat') {
                    $label = 'Liquor VAT @ ' . number_format($r, 2) . '%';
                    $key = sprintf('%.4f', $r) . '|' . $label;
                    if (! isset($vatBuckets[$key])) {
                        $vatBuckets[$key] = [
                            'rate' => $r,
                            'tax_label' => $label,
                            'taxable_value' => 0.0,
                            'tax_amount' => 0.0,
                            'line_count' => 0,
                        ];
                    }
                    $vatBuckets[$key]['taxable_value'] += $lineNet;
                    $vatBuckets[$key]['tax_amount'] += $lineTax;
                    $vatBuckets[$key]['line_count']++;
                } else {
                    $key = sprintf('%.4f', $r) . '|' . $label;
                    if (! isset($gstBuckets[$key])) {
                        $gstBuckets[$key] = [
                            'rate' => $r,
                            'tax_label' => $label,
                            'taxable_value' => 0.0,
                            'tax_amount' => 0.0,
                            'line_count' => 0,
                        ];
                    }
                    $gstBuckets[$key]['taxable_value'] += $lineNet;
                    $gstBuckets[$key]['tax_amount'] += $lineTax;
                    $gstBuckets[$key]['line_count']++;
                }
            }
        }

        // Merge both buckets with GST first, then VAT
        $buckets = array_merge($gstBuckets, $vatBuckets);

        $list = collect($buckets)
            ->values()
            ->map(function ($row) {
                $row['taxable_value'] = round($row['taxable_value'], 2);
                $row['tax_amount'] = round($row['tax_amount'], 2);

                return $row;
            })
            ->sortByDesc(fn ($row) => $row['rate'])
            ->values()
            ->all();

        $totalTaxable = round(collect($list)->sum('taxable_value'), 2);
        $totalTax = round(collect($list)->sum('tax_amount'), 2);

        return [
            'by_rate' => $list,
            'totals' => [
                'taxable_value' => $totalTaxable,
                'tax_amount' => $totalTax,
                'bills_count' => $orders->count(),
                'bucket_count' => count($list),
            ],
        ];
    }
}