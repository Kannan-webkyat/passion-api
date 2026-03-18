<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\MenuItem;
use App\Models\MenuCategory;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosPayment;
use App\Models\Recipe;
use App\Models\InventoryLocation;
use App\Models\InventoryTransaction;
use App\Models\RestaurantMaster;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    // ── Restaurants ──────────────────────────────────────────────────────────

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
                $itemCount = $order->items()->where('kot_sent', true)->sum('quantity');
                $total     = $order->items()->where('kot_sent', true)->sum(DB::raw('quantity * unit_price'));

                return [
                    'id'             => $order->id,
                    'order_type'     => $order->order_type,
                    'status'         => $order->status,
                    'kitchen_status' => $order->kitchen_status ?? 'pending',
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

        // Total portions already consumed in non-void orders
        $sold = DB::table('pos_order_items')
            ->join('pos_orders', 'pos_order_items.order_id', '=', 'pos_orders.id')
            ->where('pos_orders.status', '!=', 'void')
            ->select('pos_order_items.menu_item_id', DB::raw('SUM(pos_order_items.quantity) as total'))
            ->groupBy('pos_order_items.menu_item_id')
            ->pluck('total', 'menu_item_id')
            ->map(fn($v) => (float) $v);

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

        return response()->json($categories);
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
                return response()->json($this->formatOrder($existing->load('items.menuItem.tax', 'payments', 'room')));
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

        return response()->json($this->formatOrder($order->load('items.menuItem.tax', 'payments', 'room')), 201);
    }

    // ── Get a single order ────────────────────────────────────────────────────

    public function getOrder(PosOrder $order)
    {
        return response()->json($this->formatOrder($order->load('items.menuItem.tax', 'payments', 'room')));
    }

    // ── Sync order items (replace all) ────────────────────────────────────────

    public function syncItems(Request $request, PosOrder $order)
    {
        if (!in_array($order->status, ['open', 'billed'])) {
            return response()->json(['message' => 'Order is not editable.'], 422);
        }

        $validated = $request->validate([
            'items'              => 'required|array',
            'items.*.menu_item_id' => 'required|exists:menu_items,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'items.*.notes'      => 'nullable|string',
        ]);

        DB::transaction(function () use ($order, $validated) {
            $currentActive = $order->items()->where('status', 'active')->get();

            $key = fn ($mid, $n) => (string) $mid . '|' . trim($n ?? '');
            $incomingByKey = collect($validated['items'])->mapToGroups(function ($row) use ($key) {
                return [$key($row['menu_item_id'], $row['notes'] ?? '') => $row];
            })->map(fn ($rows) => [
                'menu_item_id' => $rows->first()['menu_item_id'],
                'quantity'     => $rows->sum('quantity'),
                'notes'        => $rows->first()['notes'] ?? null,
            ]);

            // ── Step 1: Cancel/remove items no longer in cart ───────────────────
            foreach ($currentActive as $item) {
                $k = $key($item->menu_item_id, $item->notes);
                if (!$incomingByKey->has($k)) {
                    if ($item->kot_sent) {
                        $item->update(['status' => 'cancelled']);
                    } else {
                        $item->delete();
                    }
                }
            }

            $currentActive = $order->items()->where('status', 'active')->get();

            // ── Step 2: Sync each incoming (menu_item_id, notes) line ──────────
            foreach ($incomingByKey as $row) {
                $menuItem  = MenuItem::with('tax')->find($row['menu_item_id']);
                $rmi       = RestaurantMenuItem::where('menu_item_id', $menuItem->id)
                    ->where('restaurant_master_id', $order->restaurant_id)
                    ->first();
                $unitPrice = $rmi ? floatval($rmi->price) : floatval($menuItem->price);
                $taxRate   = floatval($menuItem->tax?->rate ?? 0);
                $notes     = $row['notes'] ?? null;
                $qty       = (int) $row['quantity'];

                $matching = $currentActive->filter(
                    fn ($i) => $i->menu_item_id == $row['menu_item_id']
                        && trim($i->notes ?? '') === trim($notes ?? '')
                );
                $totalCurrent = $matching->sum('quantity');

                if ($totalCurrent === $qty) {
                    continue;
                }

                if ($totalCurrent < $qty) {
                    $delta = $qty - $totalCurrent;
                    $sent  = $matching->first(fn ($i) => $i->kot_sent);
                    if ($sent) {
                        PosOrderItem::create([
                            'order_id'     => $order->id,
                            'menu_item_id' => $row['menu_item_id'],
                            'quantity'     => $delta,
                            'unit_price'   => $unitPrice,
                            'tax_rate'     => $taxRate,
                            'line_total'   => $unitPrice * $delta,
                            'kot_sent'     => false,
                            'status'       => 'active',
                            'kot_batch'    => null,
                            'notes'        => $notes,
                        ]);
                    } else {
                        $first = $matching->first();
                        if ($first) {
                            $first->update([
                                'quantity'   => $qty,
                                'line_total' => $unitPrice * $qty,
                                'notes'      => $notes,
                            ]);
                        } else {
                            PosOrderItem::create([
                                'order_id'     => $order->id,
                                'menu_item_id' => $row['menu_item_id'],
                                'quantity'     => $qty,
                                'unit_price'   => $unitPrice,
                                'tax_rate'     => $taxRate,
                                'line_total'   => $unitPrice * $qty,
                                'kot_sent'     => false,
                                'status'       => 'active',
                                'kot_batch'    => null,
                                'notes'        => $notes,
                            ]);
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
                                PosOrderItem::create([
                                    'order_id'     => $order->id,
                                    'menu_item_id' => $row['menu_item_id'],
                                    'quantity'     => $newQty,
                                    'unit_price'   => $unitPrice,
                                    'tax_rate'     => $taxRate,
                                    'line_total'   => $unitPrice * $newQty,
                                    'kot_sent'     => false,
                                    'status'       => 'active',
                                    'kot_batch'    => null,
                                    'notes'        => $notes,
                                ]);
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

        return response()->json($this->formatOrder($order->fresh()->load('items.menuItem.tax', 'payments', 'room')));
    }

    // ── Send KOT ──────────────────────────────────────────────────────────────

    public function sendKot(PosOrder $order)
    {
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

    // ── Settle / Pay ──────────────────────────────────────────────────────────

    public function settle(Request $request, PosOrder $order)
    {
        if ($order->status === 'paid') {
            return response()->json(['message' => 'Order already paid.'], 422);
        }

        $validated = $request->validate([
            'discount_type'  => 'nullable|in:percent,flat',
            'discount_value' => 'nullable|numeric|min:0',
            'payments'       => 'required|array|min:1',
            'payments.*.method'       => 'required|in:cash,card,upi,room_charge',
            'payments.*.amount'       => 'required|numeric|min:0.01',
            'payments.*.reference_no' => 'nullable|string',
        ]);

        DB::transaction(function () use ($order, $validated) {
            // Apply discount
            $order->update([
                'discount_type'  => $validated['discount_type']  ?? null,
                'discount_value' => $validated['discount_value'] ?? 0,
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

            // Free the table (dine-in only)
            if ($order->table_id) {
                RestaurantTable::where('id', $order->table_id)->update(['status' => 'available']);
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

        return response()->json($this->formatOrder($order->fresh()->load('items.menuItem.tax', 'payments', 'room')));
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

            // ── Reverse ingredient deductions if kitchen had already marked Ready ──
            $deductions = InventoryTransaction::where('reference_type', 'pos_order')
                ->where('reference_id', (string) $order->id)
                ->where('type', 'out')
                ->get();

            foreach ($deductions as $tx) {
                DB::table('inventory_item_locations')
                    ->where('inventory_item_id',     $tx->inventory_item_id)
                    ->where('inventory_location_id', $tx->inventory_location_id)
                    ->increment('quantity', $tx->quantity);

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
        });

        return response()->json(['message' => 'Order voided.']);
    }

    // ── Kitchen Display ───────────────────────────────────────────────────────

    public function kitchenDisplay()
    {
        $orders = PosOrder::with(['items.menuItem.tax', 'table', 'restaurant', 'room'])
            ->whereIn('status', ['open', 'billed'])
            ->where('kitchen_status', '!=', 'served')
            ->whereHas('items', fn($q) => $q->where('kot_sent', true)->where('status', 'active'))
            ->orderBy('opened_at')
            ->get()
            ->map(function ($order) {
                $label = match($order->order_type ?? 'dine_in') {
                    'takeaway'     => 'Takeaway' . ($order->customer_name ? ' — ' . $order->customer_name : ''),
                    'room_service' => 'Room ' . ($order->room?->room_number ?? $order->room_id),
                    default        => 'Table ' . ($order->table?->table_number ?? '?'),
                };

                // Active items sent to kitchen, grouped by batch
                $activeKotItems = $order->items
                    ->where('status', 'active')
                    ->where('kot_sent', true)
                    ->values();

                // Cancelled items that were previously sent — visible cancellation notices
                $cancelledItems = $order->items
                    ->where('status', 'cancelled')
                    ->where('kot_sent', true)
                    ->values();

                $maxBatch = $activeKotItems->max('kot_batch') ?? 1;

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
                    'opened_at'       => $order->opened_at,
                    'items'           => $activeKotItems->map(fn($i) => [
                        'id'        => $i->id,
                        'name'      => $i->menuItem?->name ?? 'Unknown',
                        'type'      => $i->menuItem?->type ?? null,
                        'quantity'  => $i->quantity,
                        'notes'     => $i->notes,
                        'kot_batch' => $i->kot_batch ?? 1,
                        'is_addl'   => ($i->kot_batch ?? 1) > 1,
                    ]),
                    'cancellations'   => $cancelledItems->map(fn($i) => [
                        'id'        => $i->id,
                        'name'      => $i->menuItem?->name ?? 'Unknown',
                        'quantity'  => $i->quantity,
                        'kot_batch' => $i->kot_batch ?? 1,
                    ]),
                ];
            });

        return response()->json($orders);
    }

    // ── Update Kitchen Status ─────────────────────────────────────────────────

    public function updateKitchenStatus(Request $request, PosOrder $order)
    {
        $validated = $request->validate([
            'kitchen_status' => 'required|in:pending,preparing,ready,served',
        ]);

        $previousStatus = $order->kitchen_status;
        $newStatus      = $validated['kitchen_status'];

        $order->update(['kitchen_status' => $newStatus]);

        // ── Deduct ingredients when kitchen marks the order Ready ─────────────
        // Only deduct once — skip if already deducted (idempotent).
        if ($newStatus === 'ready' && $previousStatus !== 'ready') {
            $alreadyDeducted = InventoryTransaction::where('reference_type', 'pos_order')
                ->where('reference_id', (string) $order->id)
                ->exists();

            if (!$alreadyDeducted) {
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
        $kitchenStore = InventoryLocation::where('name', 'Kitchen Store')->first();
        if (!$kitchenStore) return;

        $kotItems = $order->items()->with('menuItem')->where('kot_sent', true)->get();

        DB::transaction(function () use ($order, $kotItems, $kitchenStore) {
            foreach ($kotItems as $orderItem) {
                $recipe = Recipe::with('ingredients.inventoryItem')
                    ->where('menu_item_id', $orderItem->menu_item_id)
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
                        'notes'                 => 'Order #' . $order->id . ' — ' . ($orderItem->menuItem?->name ?? 'item') . ' ×' . $orderItem->quantity,
                        'user_id'               => auth()->id(),
                        'reference_type'        => 'pos_order',
                        'reference_id'          => (string) $order->id,
                    ]);
                }
            }
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function recalculate(PosOrder $order): void
    {
        $order->refresh();
        // Only count active (non-cancelled) items in totals
        $activeItems = $order->items->where('status', 'active');
        $subtotal    = $activeItems->sum(fn($i) => floatval($i->line_total));
        $taxAmount   = $activeItems->sum(fn($i) => floatval($i->line_total) * (floatval($i->tax_rate) / 100));

        $discountAmount = 0;
        if ($order->discount_type === 'percent') {
            $discountAmount = $subtotal * (floatval($order->discount_value) / 100);
        } elseif ($order->discount_type === 'flat') {
            $discountAmount = min(floatval($order->discount_value), $subtotal);
        }

        $order->update([
            'subtotal'        => $subtotal,
            'tax_amount'      => $taxAmount,
            'discount_amount' => $discountAmount,
            'total_amount'    => max(0, $subtotal + $taxAmount - $discountAmount),
            'status'          => in_array($order->status, ['open', 'billed']) ? 'billed' : $order->status,
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
            'booking_id'      => $order->booking_id,
            'customer_name'   => $order->customer_name,
            'customer_phone'  => $order->customer_phone,
            'covers'          => $order->covers,
            'status'          => $order->status,
            'kitchen_status'  => $order->kitchen_status ?? 'pending',
            'discount_type'   => $order->discount_type,
            'discount_value'  => (float) $order->discount_value,
            'subtotal'        => (float) $order->subtotal,
            'tax_amount'      => (float) $order->tax_amount,
            'discount_amount' => (float) $order->discount_amount,
            'total_amount'    => (float) $order->total_amount,
            'opened_at'       => $order->opened_at,
            'closed_at'       => $order->closed_at,
            'notes'           => $order->notes,
            'items'           => $order->items->where('status', 'active')->values()->map(fn($i) => [
                'id'           => $i->id,
                'menu_item_id' => $i->menu_item_id,
                'name'         => $i->menuItem?->name ?? 'Unknown',
                'category'     => $i->menuItem?->category?->name ?? null,
                'type'         => $i->menuItem?->type ?? null,
                'quantity'     => $i->quantity,
                'unit_price'   => (float) $i->unit_price,
                'tax_rate'     => (float) $i->tax_rate,
                'tax_name'     => $i->menuItem?->tax?->name ?? null,
                'line_total'   => (float) $i->line_total,
                'kot_sent'     => $i->kot_sent,
                'kot_batch'    => $i->kot_batch,
                'notes'        => $i->notes,
            ]),
            'cancellations'   => $order->items->where('status', 'cancelled')->values()->map(fn($i) => [
                'id'           => $i->id,
                'menu_item_id' => $i->menu_item_id,
                'name'         => $i->menuItem?->name ?? 'Unknown',
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
        ];
    }
}
