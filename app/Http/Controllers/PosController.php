<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Combo;
use App\Models\RestaurantCombo;
use App\Models\MenuItem;
use App\Models\MenuCategory;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosOrderRefund;
use App\Models\PosPayment;
use App\Models\Recipe;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTransaction;
use App\Models\RestaurantMaster;
use App\Models\RestaurantMenuItem;
use App\Models\Setting;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    // ── Restaurants ──────────────────────────────────────────────────────────

    // ── Waiters (for Change Waiter dropdown) ──────────────────────────────────

    public function waiters(Request $request)
    {
        $users = User::role(['Waiter', 'Senior Waiter'])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->keyBy('id');

        $currentId = $request->integer('current_waiter_id');
        if ($currentId && !$users->has($currentId)) {
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
        $request->validate(['restaurant_id' => 'required|exists:restaurant_masters,id']);

        $orders = PosOrder::with(['room'])
            ->where('restaurant_id', $request->restaurant_id)
            ->whereIn('order_type', ['takeaway', 'room_service'])
            ->whereIn('status', ['open', 'billed'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) {
                $kotItems = $order->items()->where('kot_sent', true)->where('status', 'active')->get();
                $itemCount = $kotItems->sum('quantity');
                $total     = $kotItems->sum(fn($i) => $i->quantity * $i->unit_price);
                $readyBatches = $kotItems->filter(fn($i) => $i->kitchen_ready_at)->pluck('kot_batch')->unique()->sort()->values()->map(fn($b) => (int) $b)->toArray();
                $servedBatches = $kotItems->filter(fn($i) => $i->kitchen_served_at)->pluck('kot_batch')->unique()->sort()->values()->map(fn($b) => (int) $b)->toArray();

                return [
                    'id'             => $order->id,
                    'order_type'     => $order->order_type,
                    'status'         => $order->status,
                    'kitchen_status' => $order->kitchen_status ?? 'pending',
                    'ready_batches'  => $readyBatches,
                    'served_batches' => $servedBatches,
                    'room_number'    => $order->room?->room_number,
                    'customer_name'  => $order->customer_name,
                    'customer_phone' => $order->customer_phone,
                    'item_count'     => (int) $itemCount,
                    'total'          => (float) $total,
                    'opened_at'      => $order->created_at,
                ];
            });

        return response()->json($orders);
    }

    public function restaurants()
    {
        $restaurants = RestaurantMaster::where('is_active', true)
            ->withCount('tables')
            ->get();

        return response()->json($restaurants);
    }

    public function receiptConfig(RestaurantMaster $restaurant)
    {
        $defaults = Setting::getReceiptDefaults();
        $config = [
            'restaurant_name' => $restaurant->name,
            'address'         => $restaurant->address ?: ($defaults['address'] ?? ''),
            'email'           => $restaurant->email ?: ($defaults['email'] ?? ''),
            'phone'           => $restaurant->phone ?: ($defaults['phone'] ?? ''),
            'gstin'           => $restaurant->gstin ?: '',
            'fssai'           => $restaurant->fssai ?: '',
            'logo_url'        => $restaurant->logo_path
                ? asset('storage/' . $restaurant->logo_path)
                : ($defaults['logo_url'] ?? null),
        ];
        return response()->json($config);
    }

    // ── Tables with live order status ─────────────────────────────────────────

    public function tables(Request $request)
    {
        $request->validate(['restaurant_id' => 'required|exists:restaurant_masters,id']);

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
                    ? $openOrder->items->where('kot_sent', true)->where('status', 'active')->filter(fn($i) => $i->kitchen_ready_at)->pluck('kot_batch')->unique()->sort()->values()->map(fn($b) => (int) $b)->toArray()
                    : [];
                $servedBatches = $openOrder
                    ? $openOrder->items->where('kot_sent', true)->where('status', 'active')->filter(fn($i) => $i->kitchen_served_at)->pluck('kot_batch')->unique()->sort()->values()->map(fn($b) => (int) $b)->toArray()
                    : [];

                return [
                    'id'           => $table->id,
                    'table_number' => $table->table_number,
                    'capacity'     => $table->capacity,
                    'status'       => $table->status,
                    'location'     => $table->location,
                    'category'     => $table->category,
                    'open_order'   => $openOrder ? [
                        'id'             => $openOrder->id,
                        'status'         => $openOrder->status,
                        'kitchen_status' => $openOrder->kitchen_status ?? 'pending',
                        'ready_batches'  => $readyBatches,
                        'served_batches' => $servedBatches,
                        'covers'         => $openOrder->covers,
                        'item_count'     => $openOrder->items->sum('quantity'),
                        'total'          => $openOrder->total_amount,
                        'opened_at'      => $openOrder->opened_at,
                    ] : null,
                ];
            });

        return response()->json($tables);
    }

    // ── Menu for POS ──────────────────────────────────────────────────────────

    public function menu(Request $request)
    {
        $restaurantId = $request->input('restaurant_id');

        // Total portions produced per menu_item (via recipe)
        $produced = DB::table('recipes')
            ->leftJoin('production_logs', 'recipes.id', '=', 'production_logs.recipe_id')
            ->where('recipes.is_active', true)
            ->where('recipes.requires_production', true)
            ->select('recipes.menu_item_id', DB::raw('COALESCE(SUM(production_logs.quantity_produced), 0) as total'))
            ->groupBy('recipes.menu_item_id')
            ->pluck('total', 'menu_item_id')
            ->map(fn($v) => (float) $v);

        // Total portions already committed (active items in non-void orders)
        $sold = DB::table('pos_order_items')
            ->join('pos_orders', 'pos_order_items.order_id', '=', 'pos_orders.id')
            ->where('pos_orders.status', '!=', 'void')
            ->where('pos_order_items.status', 'active')
            ->whereNotNull('pos_order_items.menu_item_id')
            ->select('pos_order_items.menu_item_id', DB::raw('SUM(pos_order_items.quantity) as total'))
            ->groupBy('pos_order_items.menu_item_id')
            ->pluck('total', 'menu_item_id')
            ->map(fn($v) => (float) $v);

        // Add sold from combo items (constituent menu items)
        $comboSold = DB::table('pos_order_items')
            ->join('pos_orders', 'pos_order_items.order_id', '=', 'pos_orders.id')
            ->join('combo_items', 'combo_items.combo_id', '=', 'pos_order_items.combo_id')
            ->where('pos_orders.status', '!=', 'void')
            ->where('pos_order_items.status', 'active')
            ->whereNotNull('pos_order_items.combo_id')
            ->select('combo_items.menu_item_id', DB::raw('SUM(pos_order_items.quantity) as total'))
            ->groupBy('combo_items.menu_item_id')
            ->pluck('total', 'menu_item_id')
            ->map(fn($v) => (float) $v);
        foreach ($comboSold as $mid => $cnt) {
            $sold->put($mid, ($sold->get($mid, 0) + $cnt));
        }

        // When restaurant_id provided: filter by restaurant_menu_items, use per-restaurant price
        // When not provided: legacy mode — all items, use menu_items.price
        if ($restaurantId) {
            $rmiByItem = RestaurantMenuItem::where('restaurant_master_id', $restaurantId)
                ->where('is_active', true)
                ->get()
                ->keyBy('menu_item_id');

            $categories = MenuCategory::with(['items' => function ($q) use ($restaurantId, $rmiByItem) {
                $q->with('tax')->where('menu_items.is_active', true)
                    ->whereIn('menu_items.id', $rmiByItem->keys()->toArray())
                    ->orderBy('name');
            }])->get()->filter(fn($c) => $c->items->isNotEmpty())->values();

            $categories->each(function ($cat) use ($produced, $sold, $rmiByItem) {
                $cat->items->each(function ($item) use ($produced, $sold, $rmiByItem) {
                    $rmi = $rmiByItem->get($item->id);
                    if ($rmi) {
                        $item->price = (string) $rmi->price;
                    }
                    if ($produced->has($item->id)) {
                        $item->available_qty = max(0, $produced[$item->id] - ($sold[$item->id] ?? 0));
                    } else {
                        $item->available_qty = null;
                    }
                });
            });
        } else {
            $categories = MenuCategory::with(['items' => function ($q) {
                $q->with('tax')->where('is_active', true)->orderBy('name');
            }])->get()->filter(fn($c) => $c->items->isNotEmpty())->values();

            $categories->each(function ($cat) use ($produced, $sold) {
                $cat->items->each(function ($item) use ($produced, $sold) {
                    if ($produced->has($item->id)) {
                        $item->available_qty = max(0, $produced[$item->id] - ($sold[$item->id] ?? 0));
                    } else {
                        $item->available_qty = null;
                    }
                });
            });
        }

        // Append combos as a special category
        $restaurantComboPrices = $restaurantId
            ? RestaurantCombo::where('restaurant_master_id', $restaurantId)
                ->where('is_active', true)
                ->pluck('price', 'combo_id')
            : collect();

        $combos = Combo::with('menuItems')
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function ($c) use ($produced, $sold, $restaurantComboPrices) {
                $availableQty = null;
                if ($c->menuItems->isNotEmpty()) {
                    $availables = [];
                    foreach ($c->menuItems as $mi) {
                        if ($produced->has($mi->id)) {
                            $availables[] = max(0, $produced[$mi->id] - ($sold->get($mi->id, 0)));
                        }
                    }
                    if (!empty($availables)) {
                        $availableQty = (int) min($availables);
                    }
                }
                $price = $restaurantComboPrices->has($c->id)
                    ? (string) $restaurantComboPrices[$c->id]
                    : (string) $c->price;
                return (object) [
                    'id'            => $c->id,
                    'name'          => $c->name,
                    'price'         => $price,
                    'type'          => 'combo',
                    'item_code'     => 'COMBO-' . $c->id,
                    'available_qty' => $availableQty,
                    'combo_id'      => $c->id,
                    'menu_items'    => $c->menuItems->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->toArray(),
                ];
            });

        if ($combos->isNotEmpty()) {
            $categories = $categories->push((object) [
                'id'       => 0,
                'name'     => 'Combos',
                'items'    => $combos->values()->all(),
            ]);
        }

        return response()->json($categories->values()->all());
    }

    // ── Open a new order ──────────────────────────────────────────────────────

    public function openOrder(Request $request)
    {
        $orderType = $request->input('order_type', 'dine_in');

        $rules = [
            'order_type'     => 'nullable|in:dine_in,takeaway,room_service',
            'restaurant_id'  => 'required|exists:restaurant_masters,id',
            'covers'         => 'required|integer|min:1',
            'customer_name'  => 'nullable|string|max:191',
            'customer_phone' => 'nullable|string|max:30',
            'tax_exempt'     => 'nullable|boolean',
        ];

        if ($orderType === 'dine_in') {
            $rules['table_id'] = 'required|exists:restaurant_tables,id';
        } elseif ($orderType === 'room_service') {
            $rules['room_id']    = 'required|exists:rooms,id';
            $rules['booking_id'] = 'nullable|exists:bookings,id';
        }

        $validated = $request->validate($rules);

        if ($orderType === 'dine_in') {
            // Prevent duplicate open orders on the same table
            $existing = PosOrder::where('table_id', $validated['table_id'])
                ->whereIn('status', ['open', 'billed'])
                ->first();

            if ($existing) {
                return response()->json($this->formatOrder($existing->load('items.menuItem.tax', 'items.combo', 'payments', 'room', 'waiter', 'openedBy')));
            }
        }

        $order = DB::transaction(function () use ($validated, $orderType) {
            $order = PosOrder::create([
                'order_type'     => $orderType,
                'table_id'       => $orderType === 'dine_in' ? $validated['table_id'] : null,
                'restaurant_id'  => $validated['restaurant_id'],
                'room_id'        => $validated['room_id'] ?? null,
                'booking_id'     => $validated['booking_id'] ?? null,
                'customer_name'  => $validated['customer_name'] ?? null,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'waiter_id'      => auth()->id(),
                'opened_by'      => auth()->id(),
                'tax_exempt'     => (bool) ($validated['tax_exempt'] ?? false),
                'covers'         => $validated['covers'],
                'status'         => 'open',
                'opened_at'      => now(),
            ]);

            if ($orderType === 'dine_in') {
                RestaurantTable::where('id', $validated['table_id'])
                    ->update(['status' => 'occupied']);
            }

            return $order;
        });

        return response()->json($this->formatOrder($order->load('items.menuItem.tax', 'items.combo', 'payments', 'room', 'waiter', 'openedBy')), 201);
    }

    // ── Order history (paid orders for reprint) ─────────────────────────────────

    public function orderHistory(Request $request)
    {
        $validated = $request->validate([
            'restaurant_id' => 'required|exists:restaurant_masters,id',
            'order_id'      => 'nullable|integer|min:1',
            'from'          => 'nullable|date',
            'to'            => 'nullable|date|after_or_equal:from',
            'page'          => 'nullable|integer|min:1',
            'per_page'      => 'nullable|integer|min:1|max:100',
        ]);

        $query = PosOrder::with(['room', 'table', 'refunds'])
            ->where('restaurant_id', $validated['restaurant_id'])
            ->whereIn('status', ['paid', 'refunded'])
            ->orderByDesc('closed_at');

        if (!empty($validated['order_id'])) {
            $query->where('id', (int) $validated['order_id']);
        } else {
            if (!empty($validated['from'])) {
                $query->whereDate('closed_at', '>=', $validated['from']);
            }
            if (!empty($validated['to'])) {
                $query->whereDate('closed_at', '<=', $validated['to']);
            }
        }

        $perPage = (int) ($validated['per_page'] ?? 20);
        $paginated = $query->paginate($perPage);

        $orders = $paginated->getCollection()->map(fn ($o) => [
            'id'              => $o->id,
            'order_type'      => $o->order_type,
            'customer_name'   => $o->customer_name,
            'room_number'     => $o->room?->room_number,
            'table_number'    => $o->table?->table_number,
            'total_amount'    => (float) $o->total_amount,
            'refunded_amount'  => (float) $o->refunds->sum('amount'),
            'status'          => $o->status,
            'closed_at'       => $o->closed_at,
        ]);

        return response()->json([
            'data'         => $orders->values()->all(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'per_page'     => $paginated->perPage(),
            'total'        => $paginated->total(),
        ]);
    }

    // ── Get a single order ────────────────────────────────────────────────────

    public function getOrder(PosOrder $order)
    {
        return response()->json($this->formatOrder($order->load('items.menuItem.tax', 'items.combo', 'payments', 'room', 'table', 'waiter', 'openedBy')));
    }

    // ── Update order details (customer, covers) ─────────────────────────────────

    public function updateOrder(Request $request, PosOrder $order)
    {
        if (!in_array($order->status, ['open', 'billed'])) {
            return response()->json(['message' => 'Order is not editable.'], 422);
        }

        $rules = [
            'customer_name'  => 'nullable|string|max:191',
            'customer_phone' => 'nullable|string|max:30',
            'covers'         => 'nullable|integer|min:1',
            'notes'          => 'nullable|string|max:1000',
            'waiter_id'      => 'nullable|exists:users,id',
            'tax_exempt'     => 'nullable|boolean',
        ];
        $validated = $request->validate($rules);

        $updates = [];
        if (array_key_exists('customer_name', $validated)) {
            $updates['customer_name'] = $validated['customer_name'] ?: null;
        }
        if (array_key_exists('customer_phone', $validated)) {
            $updates['customer_phone'] = $validated['customer_phone'] ?: null;
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
            return response()->json($this->formatOrder($order->load('items.menuItem.tax', 'items.combo', 'payments', 'room', 'table', 'waiter', 'openedBy')));
        }

        $order->update($updates);

        if (array_key_exists('tax_exempt', $updates)) {
            $this->recalculate($order);
            $order->refresh();
        }

        return response()->json($this->formatOrder($order->load('items.menuItem.tax', 'items.combo', 'payments', 'room', 'table', 'waiter', 'openedBy')));
    }

    // ── Transfer order to another table (dine-in only) ────────────────────────

    public function transferTable(Request $request, PosOrder $order)
    {
        if ($order->order_type !== 'dine_in') {
            return response()->json(['message' => 'Only dine-in orders can be transferred.'], 422);
        }
        if (!in_array($order->status, ['open', 'billed'])) {
            return response()->json(['message' => 'Order is not transferable.'], 422);
        }

        $validated = $request->validate([
            'table_id' => 'required|exists:restaurant_tables,id',
        ]);
        $newTableId = (int) $validated['table_id'];

        if ($newTableId === $order->table_id) {
            return response()->json(['message' => 'Order is already at this table.'], 422);
        }

        $newTable = RestaurantTable::find($newTableId);
        if ($newTable->restaurant_master_id != $order->restaurant_id) {
            return response()->json(['message' => 'Target table must be in the same restaurant.'], 422);
        }

        $existingOrder = PosOrder::where('table_id', $newTableId)
            ->whereIn('status', ['open', 'billed'])
            ->where('id', '!=', $order->id)
            ->first();
        if ($existingOrder) {
            return response()->json(['message' => 'Target table already has an active order.'], 422);
        }

        DB::transaction(function () use ($order, $newTableId) {
            if ($order->table_id) {
                RestaurantTable::where('id', $order->table_id)->update(['status' => 'available']);
            }
            $order->update(['table_id' => $newTableId]);
            RestaurantTable::where('id', $newTableId)->update(['status' => 'occupied']);
        });

        return response()->json($this->formatOrder($order->load('items.menuItem.tax', 'items.combo', 'payments', 'room', 'table', 'waiter', 'openedBy')));
    }

    // ── Sync order items (replace all) ────────────────────────────────────────

    public function syncItems(Request $request, PosOrder $order)
    {
        if ($order->status !== 'open') {
            return response()->json(['message' => 'Order is billed. Re-open to add or edit items.'], 422);
        }

        $validated = $request->validate([
            'items'                => 'present|array',
            'items.*.menu_item_id' => 'nullable|exists:menu_items,id',
            'items.*.combo_id'    => 'nullable|exists:combos,id',
            'items.*.quantity'     => 'required|integer|min:1',
            'items.*.notes'        => 'nullable|string',
        ]);

        foreach ($validated['items'] as $i => $row) {
            $hasMenu = array_key_exists('menu_item_id', $row) && $row['menu_item_id'] !== null && $row['menu_item_id'] !== '';
            $hasCombo = array_key_exists('combo_id', $row) && $row['combo_id'] !== null && $row['combo_id'] !== '';
            if ($hasMenu === $hasCombo) {
                return response()->json([
                    'message' => "Item at index {$i} must have exactly one of menu_item_id or combo_id.",
                ], 422);
            }
        }

        // ── Availability check: prevent overselling produced items ─────────────
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
            ->where('pos_order_items.order_id', '!=', $order->id)
            ->where('pos_order_items.status', 'active')
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
                $combo = Combo::with('menuItems')->find($row['combo_id']);
                if ($combo && $combo->menuItems->isNotEmpty()) {
                    $qty = (int) $row['quantity'];
                    foreach ($combo->menuItems as $mi) {
                        $incomingByItem->put($mi->id, $incomingByItem->get($mi->id, 0) + $qty);
                    }
                }
            }
        }

        foreach ($incomingByItem as $menuItemId => $incomingQty) {
            if (!$produced->has($menuItemId)) {
                continue;
            }
            $available = max(0, $produced[$menuItemId] - ($soldExcludingThis[$menuItemId] ?? 0));
            if ($incomingQty > $available + 0.001) {
                $item = MenuItem::find($menuItemId);
                $name = $item ? $item->name : "Item #{$menuItemId}";
                return response()->json([
                    'message' => "Insufficient stock for \"{$name}\". Only {$available} available, requested {$incomingQty}.",
                ], 422);
            }
        }

        DB::transaction(function () use ($order, $validated) {
            $currentActive = $order->items()->where('status', 'active')->get();

            $key = fn ($row) => (array_key_exists('combo_id', $row) && $row['combo_id'] !== null && $row['combo_id'] !== '')
                ? 'c_' . $row['combo_id'] . '|' . trim($row['notes'] ?? '')
                : 'm_' . $row['menu_item_id'] . '|' . trim($row['notes'] ?? '');
            $incomingByKey = collect($validated['items'])
                ->filter(fn ($row) => (array_key_exists('combo_id', $row) && $row['combo_id'] !== null && $row['combo_id'] !== '')
                    || (array_key_exists('menu_item_id', $row) && $row['menu_item_id'] !== null && $row['menu_item_id'] !== ''))
                ->mapToGroups(function ($row) use ($key) {
                    return [$key($row) => $row];
                })->map(fn ($rows) => [
                'menu_item_id' => $rows->first()['menu_item_id'] ?? null,
                'combo_id'     => $rows->first()['combo_id'] ?? null,
                'quantity'     => $rows->sum('quantity'),
                'notes'        => $rows->first()['notes'] ?? null,
            ]);

            // ── Step 1: Cancel/remove items no longer in cart ───────────────────
            foreach ($currentActive as $item) {
                $k = $item->combo_id ? 'c_' . $item->combo_id . '|' . trim($item->notes ?? '') : 'm_' . $item->menu_item_id . '|' . trim($item->notes ?? '');
                if (!$incomingByKey->has($k)) {
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
                $qty   = (int) $row['quantity'];

                if (array_key_exists('combo_id', $row) && $row['combo_id'] !== null && $row['combo_id'] !== '') {
                    // Combo item
                    $combo = Combo::find($row['combo_id']);
                    if (!$combo) continue; // skip deleted combos
                    $rc = RestaurantCombo::where('combo_id', $row['combo_id'])
                        ->where('restaurant_master_id', $order->restaurant_id)
                        ->where('is_active', true)
                        ->first();
                    $unitPrice = $rc ? floatval($rc->price) : floatval($combo->price);
                    $taxRate   = 0;
                    $matching  = $currentActive->filter(
                        fn ($i) => $i->combo_id == $row['combo_id']
                            && trim($i->notes ?? '') === trim($notes ?? '')
                    );
                } else {
                    // Regular menu item
                    $menuItem  = MenuItem::with('tax')->find($row['menu_item_id']);
                    if (!$menuItem) continue;
                    $rmi       = RestaurantMenuItem::where('menu_item_id', $menuItem->id)
                        ->where('restaurant_master_id', $order->restaurant_id)
                        ->first();
                    $unitPrice = $rmi ? floatval($rmi->price) : floatval($menuItem->price);
                    $taxRate   = floatval($menuItem->tax?->rate ?? 0);
                    $matching  = $currentActive->filter(
                        fn ($i) => $i->menu_item_id == $row['menu_item_id']
                            && trim($i->notes ?? '') === trim($notes ?? '')
                    );
                }
                $totalCurrent = $matching->sum('quantity');

                if ($totalCurrent === $qty) {
                    continue;
                }

                $createAttrs = fn ($q, $u) => [
                    'order_id'     => $order->id,
                    'menu_item_id' => $row['menu_item_id'] ?? null,
                    'combo_id'     => $row['combo_id'] ?? null,
                    'quantity'     => $q,
                    'unit_price'   => $u,
                    'tax_rate'     => $taxRate,
                    'line_total'   => $u * $q,
                    'kot_sent'     => false,
                    'status'       => 'active',
                    'kot_batch'    => null,
                    'notes'        => $notes,
                ];

                if ($totalCurrent < $qty) {
                    $delta = $qty - $totalCurrent;
                    $sent  = $matching->first(fn ($i) => $i->kot_sent);
                    if ($sent) {
                        PosOrderItem::create($createAttrs($delta, $unitPrice));
                    } else {
                        $first = $matching->first();
                        if ($first) {
                            $first->update([
                                'quantity'   => $qty,
                                'line_total' => $unitPrice * $qty,
                                'notes'      => $notes,
                            ]);
                        } else {
                            PosOrderItem::create($createAttrs($qty, $unitPrice));
                        }
                    }
                } else {
                    $toReduce = $totalCurrent - $qty;
                    foreach ($matching->sortByDesc('kot_sent') as $item) {
                        if ($toReduce <= 0) break;
                        if ($item->quantity <= $toReduce) {
                            $toReduce -= $item->quantity;
                            if ($item->kot_sent) {
                                $item->update(['status' => 'cancelled']);
                            } else {
                                $item->delete();
                            }
                        } else {
                            $newQty = $item->quantity - $toReduce;
                            if ($item->kot_sent) {
                                $item->update(['status' => 'cancelled']);
                                PosOrderItem::create($createAttrs($newQty, $unitPrice));
                            } else {
                                $item->update([
                                    'quantity'   => $newQty,
                                    'line_total' => $unitPrice * $newQty,
                                    'notes'      => $notes,
                                ]);
                            }
                            $toReduce = 0;
                        }
                    }
                }
            }

            $this->recalculate($order);
        });

        return response()->json($this->formatOrder($order->fresh()->load('items.menuItem.tax', 'items.combo', 'payments', 'room', 'waiter', 'openedBy')));
    }

    // ── Send KOT ──────────────────────────────────────────────────────────────

    public function sendKot(PosOrder $order)
    {
        if ($order->status !== 'open') {
            return response()->json(['message' => 'Order is billed. Re-open to add items and send KOT.'], 422);
        }

        $hasPending = $order->items()
            ->where('status', 'active')
            ->where('kot_sent', false)
            ->exists();

        if ($hasPending) {
            // Advance to the next batch
            $order->increment('current_kot_batch');
            $batch = $order->fresh()->current_kot_batch;

            $order->items()
                ->where('status', 'active')
                ->where('kot_sent', false)
                ->update(['kot_sent' => true, 'kot_batch' => $batch]);
        }

        // Always reset kitchen status so the display re-acknowledges
        if (!in_array($order->kitchen_status, ['pending', 'preparing'])) {
            $order->update(['kitchen_status' => 'pending']);
        }

        return response()->json([
            'message'        => 'KOT sent.',
            'kitchen_status' => $order->fresh()->kitchen_status,
            'kot_batch'      => $order->fresh()->current_kot_batch,
        ]);
    }

    // ── Open bill (set status to billed) ────────────────────────────────────────

    public function openBill(PosOrder $order)
    {
        if ($order->status !== 'open') {
            return response()->json([
                'message' => 'Order must be open to generate bill.',
            ], 422);
        }

        $order->update(['status' => 'billed']);

        return response()->json($this->formatOrder($order->fresh()->load('items.menuItem.tax', 'items.combo', 'payments', 'room', 'table', 'waiter', 'openedBy')));
    }

    // ── Settle / Pay ──────────────────────────────────────────────────────────

    public function settle(Request $request, PosOrder $order)
    {
        $user = auth()->user();
        if (!$user->hasRole('Admin') && !$user->can('pos-settle')) {
            return response()->json(['message' => 'You do not have permission to settle payments.'], 403);
        }

        if ($order->status === 'paid') {
            return response()->json(['message' => 'Order already paid.'], 422);
        }

        $validated = $request->validate([
            'discount_type'  => 'nullable|in:percent,flat',
            'discount_value' => 'nullable|numeric|min:0',
            'service_charge_type'  => 'nullable|in:percent,flat',
            'service_charge_value' => 'nullable|numeric|min:0',
            'tax_exempt'     => 'nullable|boolean',
            'tip_amount'     => 'nullable|numeric|min:0',
            'payments'       => 'required|array|min:1',
            'payments.*.method'       => 'required|in:cash,card,upi,room_charge',
            'payments.*.amount'       => 'required|numeric|min:0.01',
            'payments.*.reference_no' => 'nullable|string',
        ]);

        $hasRoomCharge = collect($validated['payments'])->contains('method', 'room_charge');
        if ($hasRoomCharge && ($order->order_type !== 'room_service' || !$order->booking_id)) {
            return response()->json(['message' => 'Room charge is only available for room service orders with a linked booking.'], 422);
        }

        DB::transaction(function () use ($order, $validated) {
            // Apply discount, service charge, tax exempt
            $order->update([
                'discount_type'  => $validated['discount_type']  ?? null,
                'discount_value' => $validated['discount_value'] ?? 0,
                'service_charge_type'  => $validated['service_charge_type']  ?? null,
                'service_charge_value' => $validated['service_charge_value'] ?? 0,
                'tax_exempt'     => (bool) ($validated['tax_exempt'] ?? $order->tax_exempt),
                'tip_amount'     => (float) ($validated['tip_amount'] ?? 0),
            ]);
            $this->recalculate($order);
            $order->refresh();

            $paymentsTotal = collect($validated['payments'])->sum('amount');
            if ($paymentsTotal < $order->total_amount - 0.01) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                    response()->json([
                        'message' => 'Total payments (' . number_format($paymentsTotal, 2) . ') is less than order total (' . number_format($order->total_amount, 2) . ').',
                    ], 422),
                );
            }

            // Record payments
            $order->payments()->delete();
            foreach ($validated['payments'] as $pay) {
                PosPayment::create([
                    'order_id'     => $order->id,
                    'method'       => $pay['method'],
                    'amount'       => $pay['amount'],
                    'reference_no' => $pay['reference_no'] ?? null,
                    'paid_at'      => now(),
                    'received_by'  => auth()->id(),
                ]);
            }

            // Close order
            $order->update(['status' => 'paid', 'closed_at' => now()]);

            // Set table to cleaning (dine-in only) — staff will mark available when ready
            if ($order->table_id) {
                RestaurantTable::where('id', $order->table_id)->update(['status' => 'cleaning']);
            }

            // Post room_charge payments to the booking folio
            $roomChargeTotal = collect($validated['payments'])
                ->where('method', 'room_charge')
                ->sum('amount');

            if ($roomChargeTotal > 0 && $order->booking_id) {
                Booking::where('id', $order->booking_id)
                    ->increment('extra_charges', $roomChargeTotal);
            }
        });

        return response()->json($this->formatOrder($order->fresh()->load('items.menuItem.tax', 'items.combo', 'payments', 'room', 'waiter', 'openedBy')));
    }

    public function reopen(PosOrder $order)
    {
        if ($order->status !== 'billed') {
            return response()->json([
                'message' => 'Only billed (unpaid) orders can be re-opened.',
            ], 422);
        }

        if ($order->payments()->exists()) {
            return response()->json([
                'message' => 'Cannot re-open: order has payments. Void or refund first.',
            ], 422);
        }

        $order->update(['status' => 'open']);

        return response()->json($this->formatOrder($order->fresh()->load('items.menuItem.tax', 'items.combo', 'payments', 'room', 'table', 'waiter', 'openedBy')));
    }

    // ── Void ──────────────────────────────────────────────────────────────────

    public function void(PosOrder $order)
    {
        if ($order->status === 'paid') {
            return response()->json(['message' => 'Cannot void a paid order.'], 422);
        }

        DB::transaction(function () use ($order) {
            $order->update(['status' => 'void', 'closed_at' => now()]);

            if ($order->table_id) {
                RestaurantTable::where('id', $order->table_id)->update(['status' => 'available']);
            }

            // ── Reverse ingredient deductions (pos_order and pos_order_batch) ──
            $deductions = InventoryTransaction::whereIn('reference_type', ['pos_order', 'pos_order_batch'])
                ->where(function ($q) use ($order) {
                    $q->where('reference_id', (string) $order->id)
                        ->orWhere('reference_id', 'like', (string) $order->id . '-%');
                })
                ->where('type', 'out')
                ->get();

            $affectedItemIds = [];
            foreach ($deductions as $tx) {
                DB::table('inventory_item_locations')
                    ->where('inventory_item_id',     $tx->inventory_item_id)
                    ->where('inventory_location_id', $tx->inventory_location_id)
                    ->increment('quantity', $tx->quantity);

                $affectedItemIds[$tx->inventory_item_id] = true;

                InventoryTransaction::create([
                    'inventory_item_id'     => $tx->inventory_item_id,
                    'inventory_location_id' => $tx->inventory_location_id,
                    'type'                  => 'in',
                    'quantity'              => $tx->quantity,
                    'unit_cost'             => $tx->unit_cost,
                    'total_cost'            => $tx->total_cost,
                    'reason'                => 'Void Reversal',
                    'notes'                 => 'Reversed: Order #' . $order->id . ' voided',
                    'user_id'               => auth()->id(),
                    'reference_type'        => 'pos_order_void',
                    'reference_id'          => (string) $order->id,
                ]);
            }
            foreach (array_keys($affectedItemIds) as $itemId) {
                InventoryItem::syncStoredCurrentStockFromLocations($itemId);
            }
        });

        return response()->json(['message' => 'Order voided.']);
    }

    // ── Refund (paid orders) ───────────────────────────────────────────────────

    public function refund(Request $request, PosOrder $order)
    {
        if (!in_array($order->status, ['paid', 'refunded'])) {
            return response()->json(['message' => 'Only paid orders can be refunded.'], 422);
        }

        $validated = $request->validate([
            'amount'       => 'required|numeric|min:0.01',
            'method'       => 'required|in:cash,card,upi,room_charge',
            'reference_no' => 'nullable|string|max:100',
            'reason'       => 'nullable|string|max:500',
        ]);

        $amount = (float) $validated['amount'];
        $totalRefunded = (float) $order->refunds()->sum('amount');
        $refundable = (float) $order->total_amount - $totalRefunded;

        if ($validated['method'] === 'room_charge' && !$order->booking_id) {
            return response()->json(['message' => 'Room charge refund is only available for room service orders with a linked booking.'], 422);
        }

        if ($amount > $refundable + 0.01) {
            return response()->json([
                'message' => 'Refund amount (' . number_format($amount, 2) . ') exceeds refundable amount (' . number_format($refundable, 2) . ').',
            ], 422);
        }

        DB::transaction(function () use ($order, $validated, $amount) {
            PosOrderRefund::create([
                'order_id'     => $order->id,
                'amount'       => $amount,
                'method'       => $validated['method'],
                'reference_no' => $validated['reference_no'] ?? null,
                'reason'       => $validated['reason'] ?? null,
                'refunded_at'  => now(),
                'refunded_by'  => auth()->id(),
            ]);

            if ($validated['method'] === 'room_charge' && $order->booking_id) {
                Booking::where('id', $order->booking_id)
                    ->decrement('extra_charges', $amount);
            }

            $newTotalRefunded = (float) $order->refunds()->sum('amount');
            if ($newTotalRefunded >= (float) $order->total_amount - 0.01) {
                $order->update(['status' => 'refunded']);
            }
        });

        return response()->json($this->formatOrder($order->fresh()->load('items.menuItem.tax', 'items.combo', 'payments', 'refunds', 'room', 'table', 'waiter', 'openedBy')));
    }

    // ── Kitchen Display ───────────────────────────────────────────────────────

    public function kitchenDisplay(Request $request)
    {
        $restaurantId = $request->input('restaurant_id');

        $query = PosOrder::with(['items.menuItem.tax', 'items.combo.menuItems', 'table', 'restaurant', 'room'])
            ->whereIn('status', ['open', 'billed'])
            ->where('kitchen_status', '!=', 'served')
            ->whereHas('items', fn($q) => $q->where('kot_sent', true)->where('status', 'active'));

        if ($restaurantId) {
            $query->where('restaurant_id', $restaurantId);
        }

        $orders = $query->orderBy('opened_at')->get()
            ->map(function ($order) {
                $label = match($order->order_type ?? 'dine_in') {
                    'takeaway'     => 'Takeaway' . ($order->customer_name ? ' — ' . $order->customer_name : ''),
                    'room_service' => 'Room ' . ($order->room?->room_number ?? $order->room_id),
                    default        => 'Table ' . ($order->table?->table_number ?? '?'),
                };

                $allKotItems = $order->items->where('status', 'active')->where('kot_sent', true);
                // Only include items from batches not yet delivered (per-KOT: delivered KOTs disappear)
                $activeKotItems = $allKotItems->filter(function ($item) use ($allKotItems) {
                    $batch = $item->kot_batch ?? 1;
                    $batchItems = $allKotItems->where('kot_batch', $batch);
                    return !$batchItems->every(fn($i) => $i->kitchen_served_at);
                })->values();

                // Cancelled items for batches we're still showing
                $shownBatches = $activeKotItems->pluck('kot_batch')->unique();
                $cancelledItems = $order->items
                    ->where('status', 'cancelled')
                    ->where('kot_sent', true)
                    ->filter(fn($i) => $shownBatches->contains($i->kot_batch))
                    ->values();

                $maxBatch = $activeKotItems->max('kot_batch') ?? 1;
                $readyBatches = $activeKotItems->filter(fn($i) => $i->kitchen_ready_at)->pluck('kot_batch')->unique()->sort()->values()->toArray();
                $startedBatches = $activeKotItems->filter(fn($i) => $i->kot_started_at)->pluck('kot_batch')->unique()->sort()->values()->toArray();

                return [
                    'id'              => $order->id,
                    'order_type'      => $order->order_type ?? 'dine_in',
                    'label'           => $label,
                    'table_number'    => $order->table?->table_number,
                    'room_number'     => $order->room?->room_number,
                    'customer_name'   => $order->customer_name,
                    'restaurant'      => $order->restaurant?->name,
                    'covers'          => $order->covers,
                    'status'          => $order->status,
                    'kitchen_status'  => $order->kitchen_status ?? 'pending',
                    'current_batch'   => $maxBatch,
                    'ready_batches'   => array_values(array_map('intval', $readyBatches)),
                    'started_batches' => array_values(array_map('intval', $startedBatches)),
                    'opened_at'       => $order->opened_at,
                    'items'           => $activeKotItems->map(fn($i) => [
                        'id'               => $i->id,
                        'name'             => $i->combo_id ? ($i->combo?->name ?? 'Combo') : ($i->menuItem?->name ?? 'Unknown'),
                        'type'             => $i->combo_id ? 'combo' : ($i->menuItem?->type ?? null),
                        'combo_items'      => $i->combo_id && $i->combo ? $i->combo->menuItems->pluck('name')->toArray() : null,
                        'quantity'         => $i->quantity,
                        'notes'            => $i->notes,
                        'kot_batch'        => $i->kot_batch ?? 1,
                        'is_addl'          => ($i->kot_batch ?? 1) > 1,
                        'kitchen_ready_at' => $i->kitchen_ready_at?->toIso8601String(),
                    ]),
                    'cancellations'   => $cancelledItems->map(fn($i) => [
                        'id'        => $i->id,
                        'name'      => $i->combo_id ? ($i->combo?->name ?? 'Combo') : ($i->menuItem?->name ?? 'Unknown'),
                        'quantity'  => $i->quantity,
                        'kot_batch' => $i->kot_batch ?? 1,
                    ]),
                ];
            })
            ->filter(fn($o) => count($o['items']) > 0)
            ->values()
            ->all();

        return response()->json($orders);
    }

    public function startKotPrep(Request $request, PosOrder $order)
    {
        $validated = $request->validate([
            'batch' => 'required|integer|min:1',
        ]);
        $batch = (int) $validated['batch'];

        $batchItems = $order->items()
            ->where('kot_sent', true)
            ->where('status', 'active')
            ->where('kot_batch', $batch)
            ->get();

        if ($batchItems->isEmpty()) {
            return response()->json(['message' => 'No items in batch.'], 422);
        }

        if ($batchItems->every(fn($i) => $i->kot_started_at)) {
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

        return response()->json([
            'id'             => $order->id,
            'kitchen_status' => $order->fresh()->kitchen_status,
        ]);
    }

    // ── Mark Batch Ready (per-batch kitchen status) ───────────────────────────

    public function markBatchReady(Request $request, PosOrder $order)
    {
        $validated = $request->validate([
            'batch' => 'required|integer|min:1',
        ]);
        $batch = (int) $validated['batch'];

        $batchItems = $order->items()
            ->where('kot_sent', true)
            ->where('status', 'active')
            ->where('kot_batch', $batch)
            ->get();

        if ($batchItems->isEmpty()) {
            return response()->json(['message' => 'No items in batch.'], 422);
        }

        // Already marked?
        if ($batchItems->every(fn($i) => $i->kitchen_ready_at)) {
            return response()->json([
                'id'             => $order->id,
                'kitchen_status' => $order->fresh()->kitchen_status,
                'ready_batches'  => $this->getReadyBatches($order),
            ]);
        }

        // Deduct first (returns insufficient array if stock short); then mark ready
        $insufficient = $this->deductBatchIngredients($order, $batch);
        if ($insufficient !== null) {
            $msg = collect($insufficient)->map(fn($e) =>
                "{$e['menu_item']}: {$e['ingredient']} — {$e['available']} {$e['uom']} available, {$e['required']} required"
            )->join('; ');
            return response()->json([
                'message' => 'Insufficient stock. ' . $msg,
                'errors'  => $insufficient,
            ], 422);
        }

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
        $allReady = $allKotItems->every(fn($i) => $i->kitchen_ready_at);
        if ($allReady) {
            $order->update(['kitchen_status' => 'ready']);
        }

        return response()->json([
            'id'             => $order->id,
            'kitchen_status' => $order->fresh()->kitchen_status,
            'ready_batches'  => $this->getReadyBatches($order),
        ]);
    }

    private function getReadyBatches(PosOrder $order): array
    {
        return $order->items()
            ->where('kot_sent', true)
            ->where('status', 'active')
            ->whereNotNull('kitchen_ready_at')
            ->distinct()
            ->pluck('kot_batch')
            ->filter()
            ->sort()
            ->values()
            ->toArray();
    }

    private function getServedBatches(PosOrder $order): array
    {
        return $order->items()
            ->where('kot_sent', true)
            ->where('status', 'active')
            ->whereNotNull('kitchen_served_at')
            ->distinct()
            ->pluck('kot_batch')
            ->filter()
            ->sort()
            ->values()
            ->toArray();
    }

    public function markBatchDelivered(Request $request, PosOrder $order)
    {
        $validated = $request->validate([
            'batch' => 'required|integer|min:1',
        ]);
        $batch = (int) $validated['batch'];

        $batchItems = $order->items()
            ->where('kot_sent', true)
            ->where('status', 'active')
            ->where('kot_batch', $batch)
            ->get();

        if ($batchItems->isEmpty()) {
            return response()->json(['message' => 'No items in batch.'], 422);
        }

        // Must be ready before delivered
        if ($batchItems->contains(fn($i) => !$i->kitchen_ready_at)) {
            return response()->json(['message' => 'Batch must be ready before marking delivered.'], 422);
        }

        // Already marked delivered?
        if ($batchItems->every(fn($i) => $i->kitchen_served_at)) {
            return response()->json([
                'id'              => $order->id,
                'kitchen_status'  => $order->fresh()->kitchen_status,
                'ready_batches'   => $this->getReadyBatches($order),
                'served_batches'  => $this->getServedBatches($order),
            ]);
        }

        $order->items()
            ->where('kot_sent', true)
            ->where('status', 'active')
            ->where('kot_batch', $batch)
            ->update(['kitchen_served_at' => now()]);

        $order->refresh();
        $allKotItems = $order->items()->where('kot_sent', true)->where('status', 'active')->get();
        $allServed = $allKotItems->isNotEmpty() && $allKotItems->every(fn($i) => $i->kitchen_served_at);
        if ($allServed) {
            $order->update(['kitchen_status' => 'served']);
        }

        return response()->json([
            'id'             => $order->id,
            'kitchen_status' => $order->fresh()->kitchen_status,
            'ready_batches'  => $this->getReadyBatches($order),
            'served_batches' => $this->getServedBatches($order),
        ]);
    }

    private function getKitchenForOrder(PosOrder $order): ?InventoryLocation
    {
        $order->loadMissing('restaurant');
        $restaurant = $order->restaurant;
        if ($restaurant?->kitchen_location_id) {
            $loc = InventoryLocation::find($restaurant->kitchen_location_id);
            if ($loc) return $loc;
        }
        return InventoryLocation::where('type', 'kitchen_store')->first();
    }

    /**
     * Check if kitchen has sufficient ingredients for made-to-order items.
     * Returns array of insufficient items: [['menu_item' => 'Tea', 'ingredient' => 'Tea Leaves', 'required' => 3, 'available' => 0, 'uom' => 'Gm']]
     */
    private function checkMadeToOrderStock(PosOrder $order, $items): array
    {
        $kitchenStore = $this->getKitchenForOrder($order);
        if (!$kitchenStore) return [];

        $insufficient = [];
        foreach ($items as $orderItem) {
            $menuItemIds = $orderItem->combo_id && $orderItem->combo
                ? $orderItem->combo->menuItems->pluck('id')
                : ($orderItem->menu_item_id ? collect([$orderItem->menu_item_id]) : collect());
            $baseName = $orderItem->combo_id ? ($orderItem->combo?->name ?? 'Combo') : ($orderItem->menuItem?->name ?? 'Item');

            foreach ($menuItemIds as $menuItemId) {
                $recipe = Recipe::with('ingredients.inventoryItem.issueUom')
                    ->where('menu_item_id', $menuItemId)
                    ->where('is_active', true)
                    ->first();

                if (!$recipe || ($recipe->requires_production ?? true)) continue;

                $multiplier = $orderItem->quantity / $recipe->yield_quantity;
                $menuName = $baseName . ' · ' . ($recipe->menuItem?->name ?? 'Item #' . $menuItemId);

            foreach ($recipe->ingredients as $ing) {
                $rawQty = round($ing->raw_quantity * $multiplier, 3);
                $currentStock = (float) (DB::table('inventory_item_locations')
                    ->where('inventory_item_id', $ing->inventory_item_id)
                    ->where('inventory_location_id', $kitchenStore->id)
                    ->value('quantity') ?? 0);

                if ($currentStock < $rawQty) {
                    $insufficient[] = [
                        'menu_item'  => $menuName,
                        'ingredient' => $ing->inventoryItem?->name ?? 'Unknown',
                        'required'   => $rawQty,
                        'available'  => $currentStock,
                        'uom'        => $ing->inventoryItem?->issueUom?->short_name ?? $ing->uom?->short_name ?? 'unit',
                    ];
                }
            }
            }
        }
        return $insufficient;
    }

    /**
     * Deduct ingredients for made-to-order items in a batch.
     * Returns array of insufficient items if stock is short; null on success.
     */
    private function deductBatchIngredients(PosOrder $order, int $batch): ?array
    {
        $kitchenStore = $this->getKitchenForOrder($order);
        if (!$kitchenStore) return null;

        $refId = $order->id . '-' . $batch;
        $alreadyDeducted = InventoryTransaction::where('reference_type', 'pos_order_batch')
            ->where('reference_id', $refId)
            ->exists();
        if ($alreadyDeducted) return null;

        $batchItems = $order->items()
            ->with(['menuItem', 'combo.menuItems'])
            ->where('kot_sent', true)
            ->where('status', 'active')
            ->where('kot_batch', $batch)
            ->get();

        $result = DB::transaction(function () use ($order, $batch, $batchItems, $kitchenStore, $refId) {
            // Pass 1: Verify all made-to-order ingredients have sufficient stock (with lock)
            $insufficient = [];
            foreach ($batchItems as $orderItem) {
                $menuItemIds = $orderItem->combo_id && $orderItem->combo
                    ? $orderItem->combo->menuItems->pluck('id')
                    : ($orderItem->menu_item_id ? collect([$orderItem->menu_item_id]) : collect());
                $baseName = $orderItem->combo_id ? ($orderItem->combo?->name ?? 'Combo') : ($orderItem->menuItem?->name ?? 'Item');

                foreach ($menuItemIds as $menuItemId) {
                    $recipe = Recipe::with('ingredients.inventoryItem.issueUom')
                        ->where('menu_item_id', $menuItemId)
                        ->where('is_active', true)
                        ->first();

                    if (!$recipe || ($recipe->requires_production ?? true)) continue;

                    $multiplier = $orderItem->quantity / $recipe->yield_quantity;
                    $menuName = $baseName . ' · ' . ($recipe->menuItem?->name ?? 'Item #' . $menuItemId);

                    foreach ($recipe->ingredients as $ing) {
                        $rawQty = round($ing->raw_quantity * $multiplier, 3);
                        $currentStock = (float) (DB::table('inventory_item_locations')
                            ->where('inventory_item_id', $ing->inventory_item_id)
                            ->where('inventory_location_id', $kitchenStore->id)
                            ->lockForUpdate()
                            ->value('quantity') ?? 0);

                        if ($currentStock < $rawQty) {
                            $insufficient[] = [
                                'menu_item'  => $menuName,
                                'ingredient' => $ing->inventoryItem?->name ?? 'Unknown',
                                'required'   => $rawQty,
                                'available'  => $currentStock,
                                'uom'        => $ing->inventoryItem?->issueUom?->short_name ?? $ing->uom?->short_name ?? 'unit',
                            ];
                        }
                    }
                }
            }
            if (!empty($insufficient)) {
                return ['insufficient' => $insufficient];
            }

            // Pass 2: Deduct
            $affectedItemIds = [];
            foreach ($batchItems as $orderItem) {
                $menuItemIds = $orderItem->combo_id && $orderItem->combo
                    ? $orderItem->combo->menuItems->pluck('id')
                    : ($orderItem->menu_item_id ? collect([$orderItem->menu_item_id]) : collect());
                $itemLabel = $orderItem->combo_id ? ($orderItem->combo?->name ?? 'Combo') : ($orderItem->menuItem?->name ?? 'item');

                foreach ($menuItemIds as $menuItemId) {
                    $recipe = Recipe::with('ingredients.inventoryItem')
                        ->where('menu_item_id', $menuItemId)
                        ->where('is_active', true)
                        ->first();

                    if (!$recipe || ($recipe->requires_production ?? true)) continue;

                    $multiplier = $orderItem->quantity / $recipe->yield_quantity;

                    foreach ($recipe->ingredients as $ing) {
                        $rawQty = round($ing->raw_quantity * $multiplier, 3);

                        $currentStock = DB::table('inventory_item_locations')
                            ->where('inventory_item_id', $ing->inventory_item_id)
                            ->where('inventory_location_id', $kitchenStore->id)
                            ->lockForUpdate()
                            ->value('quantity') ?? 0;

                        $deduct = min($rawQty, max(0, (float) $currentStock));
                        if ($deduct <= 0) continue;

                        DB::table('inventory_item_locations')
                            ->where('inventory_item_id', $ing->inventory_item_id)
                            ->where('inventory_location_id', $kitchenStore->id)
                            ->decrement('quantity', $deduct);

                        $affectedItemIds[$ing->inventory_item_id] = true;

                        $item = $ing->inventoryItem;
                        $unitCostAtTime = floatval($item->cost_price ?? 0) / floatval($item->conversion_factor ?? 1);

                        InventoryTransaction::create([
                            'inventory_item_id'     => $ing->inventory_item_id,
                            'inventory_location_id' => $kitchenStore->id,
                            'type'                  => 'out',
                            'quantity'              => $deduct,
                            'unit_cost'             => $unitCostAtTime,
                            'total_cost'            => round($deduct * $unitCostAtTime, 2),
                            'reason'                => 'POS Order',
                            'notes'                 => 'Order #' . $order->id . ' Batch ' . $batch . ' — ' . $itemLabel . ' ×' . $orderItem->quantity,
                            'user_id'               => auth()->id(),
                            'reference_type'        => 'pos_order_batch',
                            'reference_id'          => $refId,
                        ]);
                    }
                }
            }
            return ['affected' => array_keys($affectedItemIds)];
        });

        if (isset($result['insufficient'])) {
            return $result['insufficient'];
        }
        foreach ($result['affected'] ?? [] as $itemId) {
            InventoryItem::syncStoredCurrentStockFromLocations($itemId);
        }
        return null;
    }

    // ── Update Kitchen Status ─────────────────────────────────────────────────

    public function updateKitchenStatus(Request $request, PosOrder $order)
    {
        $validated = $request->validate([
            'kitchen_status' => 'required|in:pending,preparing,ready,served',
        ]);

        $previousStatus = $order->kitchen_status;
        $newStatus      = $validated['kitchen_status'];

        // When marking ready: check stock before updating (legacy flow - whole order at once)
        if ($newStatus === 'ready' && $previousStatus !== 'ready') {
            $perBatchDeducted = InventoryTransaction::where('reference_type', 'pos_order_batch')
                ->where('reference_id', 'like', (string) $order->id . '-%')
                ->exists();
            $legacyDeducted = InventoryTransaction::where('reference_type', 'pos_order')
                ->where('reference_id', (string) $order->id)
                ->exists();

            if (!$perBatchDeducted && !$legacyDeducted) {
                $kotItems = $order->items()->with(['menuItem', 'combo.menuItems'])->where('kot_sent', true)->get();
                $insufficient = $this->checkMadeToOrderStock($order, $kotItems);
                if (!empty($insufficient)) {
                    $msg = collect($insufficient)->map(fn($e) =>
                        "{$e['menu_item']}: {$e['ingredient']} — {$e['available']} {$e['uom']} available, {$e['required']} required"
                    )->join('; ');
                    return response()->json([
                        'message' => 'Insufficient stock. ' . $msg,
                        'errors'  => $insufficient,
                    ], 422);
                }
            }
        }

        $order->update(['kitchen_status' => $newStatus]);

        // When marking served, set kitchen_served_at for all KOT items
        if ($newStatus === 'served') {
            $order->items()
                ->where('kot_sent', true)
                ->where('status', 'active')
                ->whereNull('kitchen_served_at')
                ->update(['kitchen_served_at' => now()]);
        }

        // ── Deduct ingredients when kitchen marks the order Ready ─────────────
        // Skip if using per-batch (already deducted via markBatchReady).
        if ($newStatus === 'ready' && $previousStatus !== 'ready') {
            $perBatchDeducted = InventoryTransaction::where('reference_type', 'pos_order_batch')
                ->where('reference_id', 'like', (string) $order->id . '-%')
                ->exists();
            $legacyDeducted = InventoryTransaction::where('reference_type', 'pos_order')
                ->where('reference_id', (string) $order->id)
                ->exists();

            if (!$perBatchDeducted && !$legacyDeducted) {
                $this->deductOrderIngredients($order);
            }
        }

        return response()->json([
            'id'             => $order->id,
            'kitchen_status' => $order->fresh()->kitchen_status,
        ]);
    }

    /**
     * Deduct recipe ingredients for all KOT items in an order from Kitchen Store.
     * Uses lockForUpdate() for concurrency safety. Silently skips items with no recipe.
     */
    private function deductOrderIngredients(PosOrder $order): void
    {
        $kitchenStore = $this->getKitchenForOrder($order);
        if (!$kitchenStore) return;

        $kotItems = $order->items()->with(['menuItem', 'combo.menuItems'])->where('kot_sent', true)->get();
        $affectedItemIds = [];

        DB::transaction(function () use ($order, $kotItems, $kitchenStore, &$affectedItemIds) {
            foreach ($kotItems as $orderItem) {
                $menuItemIds = $orderItem->combo_id && $orderItem->combo
                    ? $orderItem->combo->menuItems->pluck('id')
                    : ($orderItem->menu_item_id ? collect([$orderItem->menu_item_id]) : collect());
                $itemLabel = $orderItem->combo_id ? ($orderItem->combo?->name ?? 'Combo') : ($orderItem->menuItem?->name ?? 'item');

                foreach ($menuItemIds as $menuItemId) {
                    $recipe = Recipe::with('ingredients.inventoryItem')
                        ->where('menu_item_id', $menuItemId)
                        ->where('is_active', true)
                        ->first();

                    if (!$recipe) continue;

                    // Batch items (Chicken Biryani): ingredients already deducted at production.
                    // Only deduct for made-to-order items (Tea, Coffee, Omelette).
                    if ($recipe->requires_production ?? true) continue;

                    // multiplier = portions ordered / recipe yield
                    $multiplier = $orderItem->quantity / $recipe->yield_quantity;

                foreach ($recipe->ingredients as $ing) {
                    $rawQty = round($ing->raw_quantity * $multiplier, 3);

                    // Lock the row before reading to prevent race conditions
                    $currentStock = DB::table('inventory_item_locations')
                        ->where('inventory_item_id',     $ing->inventory_item_id)
                        ->where('inventory_location_id', $kitchenStore->id)
                        ->lockForUpdate()
                        ->value('quantity') ?? 0;

                    // Deduct only what's available — no hard-stop for quick items
                    $deduct = min($rawQty, max(0, (float) $currentStock));

                    if ($deduct <= 0) continue;

                    DB::table('inventory_item_locations')
                        ->where('inventory_item_id',     $ing->inventory_item_id)
                        ->where('inventory_location_id', $kitchenStore->id)
                        ->decrement('quantity', $deduct);

                    $affectedItemIds[$ing->inventory_item_id] = true;

                    $item           = $ing->inventoryItem;
                    $unitCostAtTime = floatval($item->cost_price ?? 0) / floatval($item->conversion_factor ?? 1);

                    InventoryTransaction::create([
                        'inventory_item_id'     => $ing->inventory_item_id,
                        'inventory_location_id' => $kitchenStore->id,
                        'type'                  => 'out',
                        'quantity'              => $deduct,
                        'unit_cost'             => $unitCostAtTime,
                        'total_cost'            => round($deduct * $unitCostAtTime, 2),
                        'reason'                => 'POS Order',
                        'notes'                 => 'Order #' . $order->id . ' — ' . $itemLabel . ' ×' . $orderItem->quantity,
                        'user_id'               => auth()->id(),
                        'reference_type'        => 'pos_order',
                        'reference_id'          => (string) $order->id,
                    ]);
                }
                }
            }
        });
        foreach (array_keys($affectedItemIds) as $itemId) {
            InventoryItem::syncStoredCurrentStockFromLocations($itemId);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function recalculate(PosOrder $order): void
    {
        $order->refresh();
        // Only count active (non-cancelled) items in totals
        $activeItems = $order->items->where('status', 'active');
        $subtotal    = $activeItems->sum(fn($i) => floatval($i->line_total));
        $taxAmount   = $order->tax_exempt ? 0 : $activeItems->sum(fn($i) => floatval($i->line_total) * (floatval($i->tax_rate) / 100));

        $serviceChargeAmount = 0;
        if ($order->service_charge_type === 'percent') {
            $serviceChargeAmount = $subtotal * (floatval($order->service_charge_value ?? 0) / 100);
        } elseif ($order->service_charge_type === 'flat') {
            $serviceChargeAmount = floatval($order->service_charge_value ?? 0);
        }

        $discountAmount = 0;
        if ($order->discount_type === 'percent') {
            $discountAmount = $subtotal * (floatval($order->discount_value) / 100);
        } elseif ($order->discount_type === 'flat') {
            $discountAmount = min(floatval($order->discount_value), $subtotal);
        }

        $tipAmount = (float) ($order->tip_amount ?? 0);
        $order->update([
            'subtotal'             => $subtotal,
            'tax_amount'           => $taxAmount,
            'service_charge_amount' => $serviceChargeAmount,
            'discount_amount'      => $discountAmount,
            'total_amount'         => max(0, $subtotal + $taxAmount + $serviceChargeAmount - $discountAmount + $tipAmount),
        ]);
    }

    private function formatOrder(PosOrder $order): array
    {
        return [
            'id'              => $order->id,
            'order_type'      => $order->order_type ?? 'dine_in',
            'table_id'        => $order->table_id,
            'restaurant_id'   => $order->restaurant_id,
            'room_id'         => $order->room_id,
            'room_number'     => $order->room?->room_number ?? null,
            'table_number'    => $order->table?->table_number ?? null,
            'booking_id'      => $order->booking_id,
            'customer_name'   => $order->customer_name,
            'customer_phone'  => $order->customer_phone,
            'covers'          => $order->covers,
            'waiter_id'       => $order->waiter_id,
            'waiter'          => $order->waiter ? ['id' => $order->waiter->id, 'name' => $order->waiter->name] : null,
            'opened_by'       => $order->opened_by,
            'opened_by_user'  => $order->openedBy ? ['id' => $order->openedBy->id, 'name' => $order->openedBy->name] : null,
            'status'          => $order->status,
            'kitchen_status'  => $order->kitchen_status ?? 'pending',
            'ready_batches'   => $order->items->where('status', 'active')->where('kot_sent', true)->filter(fn($i) => $i->kitchen_ready_at)->pluck('kot_batch')->unique()->sort()->values()->map(fn($b) => (int) $b)->toArray(),
            'served_batches'  => $order->items->where('status', 'active')->where('kot_sent', true)->filter(fn($i) => $i->kitchen_served_at)->pluck('kot_batch')->unique()->sort()->values()->map(fn($b) => (int) $b)->toArray(),
            'discount_type'   => $order->discount_type,
            'discount_value'  => (float) $order->discount_value,
            'service_charge_type'  => $order->service_charge_type,
            'service_charge_value' => (float) ($order->service_charge_value ?? 0),
            'service_charge_amount' => (float) ($order->service_charge_amount ?? 0),
            'subtotal'        => (float) $order->subtotal,
            'tax_amount'      => (float) $order->tax_amount,
            'discount_amount' => (float) $order->discount_amount,
            'tip_amount'      => (float) ($order->tip_amount ?? 0),
            'total_amount'    => (float) $order->total_amount,
            'opened_at'       => $order->opened_at,
            'closed_at'       => $order->closed_at,
            'notes'           => $order->notes,
            'tax_exempt'      => (bool) ($order->tax_exempt ?? false),
            'items'           => $order->items->where('status', 'active')->values()->map(fn($i) => [
                'id'           => $i->id,
                'menu_item_id' => $i->menu_item_id,
                'combo_id'     => $i->combo_id,
                'name'         => $i->combo_id ? ($i->combo?->name ?? 'Combo') : ($i->menuItem?->name ?? 'Unknown'),
                'category'     => $i->menuItem?->category?->name ?? ($i->combo_id ? 'Combo' : null),
                'type'         => $i->combo_id ? 'combo' : ($i->menuItem?->type ?? null),
                'quantity'     => $i->quantity,
                'unit_price'   => (float) $i->unit_price,
                'tax_rate'     => (float) $i->tax_rate,
                'tax_name'     => $i->menuItem?->tax?->name ?? null,
                'line_total'   => (float) $i->line_total,
                'kot_sent'     => $i->kot_sent,
                'kot_batch'    => $i->kot_batch,
                'kitchen_ready_at' => $i->kitchen_ready_at?->toIso8601String(),
                'notes'        => $i->notes,
            ]),
            'cancellations'   => $order->items->where('status', 'cancelled')->values()->map(fn($i) => [
                'id'           => $i->id,
                'menu_item_id' => $i->menu_item_id,
                'combo_id'     => $i->combo_id,
                'name'         => $i->combo_id ? ($i->combo?->name ?? 'Combo') : ($i->menuItem?->name ?? 'Unknown'),
                'quantity'     => $i->quantity,
                'kot_batch'    => $i->kot_batch,
            ]),
            'payments'        => $order->payments->map(fn($p) => [
                'id'           => $p->id,
                'method'       => $p->method,
                'amount'       => (float) $p->amount,
                'reference_no' => $p->reference_no,
                'paid_at'      => $p->paid_at,
            ]),
            'refunds'         => $order->refunds->map(fn($r) => [
                'id'           => $r->id,
                'amount'       => (float) $r->amount,
                'method'       => $r->method,
                'reference_no' => $r->reference_no,
                'reason'       => $r->reason,
                'refunded_at'  => $r->refunded_at,
            ]),
            'refunded_amount' => (float) $order->refunds->sum('amount'),
        ];
    }
}
