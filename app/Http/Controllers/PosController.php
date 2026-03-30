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

class PosController extends Controller
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

        $orders = PosOrder::with(['room'])
            ->where('restaurant_id', $request->restaurant_id)
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
            'logo_url' => $restaurant->logo_path
                ? asset('storage/'.$restaurant->logo_path)
                : ($defaults['logo_url'] ?? null),
        ];

        return response()->json($config);
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

                return (object) [
                    'id' => $c->id,
                    'name' => $c->name,
                    'price' => $price,
                    'tax_rate' => $comboTaxRate,
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

            return response()->json($this->formatOrder($duplicateOrder->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'waiter', 'openedBy', 'voidedBy')));
        }

        $this->broadcastPosOutletUpdate((int) $order->restaurant_id, (int) $order->id);

        return response()->json($this->formatOrder($order->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'waiter', 'openedBy', 'voidedBy')), 201);
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

        return response()->json($this->formatOrder($order->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy')));
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
            return response()->json($this->formatOrder($order->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy')));
        }

        $order->update($updates);

        if (array_key_exists('tax_exempt', $updates)) {
            $this->recalculate($order);
            $order->refresh();
        }

        $this->broadcastPosOutletUpdate((int) $order->restaurant_id, (int) $order->id);

        return response()->json($this->formatOrder($order->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy')));
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

        return response()->json($this->formatOrder($order->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy')));
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
                            'order' => $this->formatOrder($order->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy')),
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
                    $sent = $matching->first(fn ($i) => $i->kot_sent);
                    if ($sent) {
                        PosOrderItem::create($createAttrs($delta, $unitPrice));
                    } else {
                        $first = $matching->first();
                        if ($first) {
                            $first->update([
                                'quantity' => $qty,
                                'unit_price' => $unitPrice,
                                'tax_rate' => $taxRate,
                                'price_tax_inclusive' => $priceTaxInclusive,
                                'line_total' => $unitPrice * $qty,
                                'notes' => $notes,
                            ]);
                        } else {
                            PosOrderItem::create($createAttrs($qty, $unitPrice));
                        }
                    }
                } else {
                    $toReduce = $totalCurrent - $qty;
                    foreach ($matching->sortBy('kot_sent') as $item) {
                        if ($toReduce <= 0) {
                            break;
                        }
                        if ($item->quantity <= $toReduce) {
                            $toReduce -= $item->quantity;

                            $kitchenStore = $this->getKitchenForOrder($order);
                            $barLocationId = $order->restaurant?->bar_location_id;
                            $barStore = $barLocationId ? InventoryLocation::find($barLocationId) : InventoryLocation::query()->where('type', 'bar_store')->where('department_id', $order->restaurant?->department_id)->first();
                            $targetStore = $this->resolveInventoryDeductionStore($item->menuItem, $kitchenStore, $barStore);

                            if ($item->inventory_deducted && $targetStore) {
                                $this->reverseOrderItemInventory($item, $targetStore, 'pos_order_sync_reduce', (string) $order->id);
                            }

                            if ($item->kot_sent) {
                                $item->update(['status' => 'cancelled']);
                            } else {
                                $item->delete();
                            }
                        } else {
                            $newQty = $item->quantity - $toReduce;
                            if ($item->kot_sent) {
                                $wasDeducted = $item->inventory_deducted;
                                $kitchenStore = $this->getKitchenForOrder($order);
                                $barLocationId = $order->restaurant?->bar_location_id;
                                $barStore = $barLocationId ? InventoryLocation::find($barLocationId) : InventoryLocation::query()->where('type', 'bar_store')->where('department_id', $order->restaurant?->department_id)->first();
                                $targetStore = $this->resolveInventoryDeductionStore($item->menuItem, $kitchenStore, $barStore);

                                if ($wasDeducted && $targetStore) {
                                    $this->reverseInventoryByQuantity($item, $targetStore, $toReduce, 'pos_order_sync_partial', (string) $order->id);
                                }

                                $item->update(['status' => 'cancelled']);
                                $newLine = PosOrderItem::create($createAttrs($newQty, $unitPrice));
                                $newLine->update([
                                    'kot_sent' => true,
                                    'kot_batch' => $item->kot_batch,
                                    'kot_started_at' => $item->kot_started_at,
                                    'kitchen_ready_at' => $item->kitchen_ready_at,
                                    'kitchen_served_at' => $item->kitchen_served_at,
                                    'inventory_deducted' => $wasDeducted,
                                ]);
                            } else {
                                $item->update([
                                    'quantity' => $newQty,
                                    'unit_price' => $unitPrice,
                                    'tax_rate' => $taxRate,
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

        return response()->json($this->formatOrder($fresh->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'waiter', 'openedBy', 'voidedBy')));
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

        return response()->json($this->formatOrder($fresh->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy')));
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

                $moved = PosOrderItem::where('order_id', $src->id)
                    ->where('status', 'active')
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
        return response()->json($this->formatOrder($fresh->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy')));
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

        $fresh = $order->fresh()->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy');

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

        return response()->json($this->formatOrder($fresh->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy')));
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

        return response()->json($this->formatOrder($fresh->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy')));
    }

    public function reopen(PosOrder $order)
    {
        $this->checkPermission('pos-manage');
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

        return response()->json($this->formatOrder($fresh->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy')));
    }

    // ── Void ──────────────────────────────────────────────────────────────────

    public function void(Request $request, PosOrder $order)
    {
        $this->checkPermission('manage-restaurant');
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
        $this->checkPermission('pos-order');
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

        DB::transaction(function () use ($items, $order, $kitchenStore, $barStore, $validated) {
            PosOrder::where('id', $order->id)->lockForUpdate()->first();

            foreach ($items as $item) {
                $item->refresh();
                if ($item->status !== 'active') {
                    continue;
                }
                if ($item->kot_started_at || $item->kitchen_ready_at) {
                    $item->update([
                        'status' => 'cancelled',
                        'cancel_reason' => $validated['cancel_reason'],
                        'cancel_notes' => $validated['cancel_notes'] ?? null,
                        'cancelled_by' => auth()->id(),
                        'cancelled_at' => now(),
                    ]);

                    continue;
                }
                $targetStore = $this->resolveInventoryDeductionStore($item->menuItem, $kitchenStore, $barStore);
                if ($item->inventory_deducted && $targetStore) {
                    $this->reverseOrderItemInventory($item, $targetStore, 'pos_order_item_void', (string) $order->id);
                }
                $item->update([
                    'status' => 'cancelled',
                    'cancel_reason' => $validated['cancel_reason'],
                    'cancel_notes' => $validated['cancel_notes'] ?? null,
                    'cancelled_by' => auth()->id(),
                    'cancelled_at' => now(),
                ]);
            }
            $this->recalculate($order);
        });

        $fresh = $order->fresh();
        $this->broadcastPosOutletUpdate((int) $fresh->restaurant_id, (int) $fresh->id);

        return response()->json($this->formatOrder($fresh->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'room', 'table', 'waiter', 'openedBy', 'voidedBy')));
    }

    // ── Refund (paid orders) ───────────────────────────────────────────────────

    public function refund(Request $request, PosOrder $order)
    {
        $this->checkPermission('manage-restaurant');
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

        return response()->json($this->formatOrder($fresh->load('items.menuItem.tax', 'items.menuItem.category', 'items.combo', 'items.variant', 'payments', 'refunds', 'room', 'table', 'waiter', 'openedBy', 'voidedBy')));
    }

    // ── Kitchen Display ───────────────────────────────────────────────────────

    public function kitchenDisplay(Request $request)
    {
        $this->checkPermission('pos-order');
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
                // Determine if THIS KDS SCREEN we are viewing is a Bar or Kitchen
                $currentScreen = $restaurantId ? \App\Models\RestaurantMaster::with('department')->find($restaurantId) : null;
                $userDept = $user ? $user->departments()->first() : null;

                // Check by selected outlet OR by user's assigned department for 'All Outlets' view
                $isBarKds = ($currentScreen && (
                    stripos($currentScreen->name, 'bar') !== false ||
                    ($currentScreen->department && stripos($currentScreen->department->name, 'bar') !== false) ||
                    ($currentScreen->department && $currentScreen->department->code === 'BAR')
                )) || (! $currentScreen && $userDept && (
                    stripos($userDept->name, 'bar') !== false ||
                    $userDept->code === 'BAR'
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

                        // Bar KDS sees items that ARE direct sale (spirits, beer, cocktails)
                        // Kitchen KDS sees items that are NOT direct sale (food, made-to-order)
                        return $isBarKds
                            ? (bool) ($item->menuItem?->is_direct_sale ?? false)
                            : ! (bool) ($item->menuItem?->is_direct_sale ?? false);
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

    private function recalculate(PosOrder $order): void
    {
        $order->refresh();
        $order->load('items');
        $activeItems = $order->items->where('status', 'active');
        $subtotal = $activeItems->sum(fn ($i) => floatval($i->line_total));

        $discountAmount = 0;
        if ($order->discount_type === 'percent') {
            $discountAmount = $subtotal * (floatval($order->discount_value) / 100);
        } elseif ($order->discount_type === 'flat') {
            $discountAmount = min(floatval($order->discount_value), $subtotal);
        }

        $discountRatio = $subtotal > 0 ? ($discountAmount / $subtotal) : 0;

        // GST: per-line inclusive (sell price includes tax) vs exclusive (tax on top). Mixed carts supported.
        if ($order->tax_exempt || $order->is_complimentary) {
            $taxAmount = 0.0;
        } else {
            $taxAmount = 0.0;
            foreach ($activeItems as $i) {
                $eff = floatval($i->line_total) * (1 - $discountRatio);
                $r = floatval($i->tax_rate);
                if ($this->linePriceTaxInclusive($i, $order)) {
                    $taxAmount += $r > 0 ? $eff * ($r / (100 + $r)) : 0;
                } else {
                    $taxAmount += $eff * ($r / 100);
                }
            }
        }

        $serviceChargeAmount = 0;
        if ($order->service_charge_type === 'percent') {
            $serviceChargeAmount = $subtotal * (floatval($order->service_charge_value ?? 0) / 100);
        } elseif ($order->service_charge_type === 'flat') {
            $serviceChargeAmount = min(floatval($order->service_charge_value ?? 0), $subtotal);
        }

        $tipAmount = (float) ($order->tip_amount ?? 0);
        $deliveryCharge = $order->order_type === 'delivery' ? (float) ($order->delivery_charge ?? 0) : 0;

        if ($order->is_complimentary) {
            $rawTotal = 0.0;
        } elseif ($order->tax_exempt) {
            $linePaySum = $activeItems->sum(fn ($i) => floatval($i->line_total) * (1 - $discountRatio));
            $rawTotal = max(0, $linePaySum + $serviceChargeAmount + $tipAmount + $deliveryCharge);
        } else {
            $linePaySum = 0.0;
            foreach ($activeItems as $i) {
                $eff = floatval($i->line_total) * (1 - $discountRatio);
                $r = floatval($i->tax_rate);
                if ($this->linePriceTaxInclusive($i, $order)) {
                    $linePaySum += $eff;
                } else {
                    $linePaySum += $eff * (1 + $r / 100);
                }
            }
            $rawTotal = max(0, $linePaySum + $serviceChargeAmount + $tipAmount + $deliveryCharge);
        }

        if ($order->is_complimentary) {
            $finalTotal = 0.0;
            $serviceChargeAmount = 0;
            $tipAmount = 0;
        } else {
            $finalTotal = round(max(0, $rawTotal), 2);
        }

        $order->update([
            'subtotal' => $subtotal,
            'tax_amount' => round($taxAmount, 2),
            'service_charge_amount' => round($serviceChargeAmount, 2),
            'discount_amount' => round($discountAmount, 2),
            'tip_amount' => $tipAmount,
            'rounding_amount' => 0,
            'total_amount' => $finalTotal,
        ]);
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
            'discount_amount' => (float) $order->discount_amount,
            'tip_amount' => (float) ($order->tip_amount ?? 0),
            'rounding_amount' => (float) ($order->rounding_amount ?? 0),
            'delivery_charge' => (float) ($order->delivery_charge ?? 0),
            'is_complimentary' => (bool) $order->is_complimentary,
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
}
