<?php

namespace App\Http\Controllers;

use App\Models\MenuItem;
use App\Models\MenuCategory;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosPayment;
use App\Models\RestaurantMaster;
use App\Models\RestaurantTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    // ── Restaurants ──────────────────────────────────────────────────────────

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
                        'id'          => $openOrder->id,
                        'status'      => $openOrder->status,
                        'covers'      => $openOrder->covers,
                        'item_count'  => $openOrder->items->sum('quantity'),
                        'total'       => $openOrder->total_amount,
                        'opened_at'   => $openOrder->opened_at,
                    ] : null,
                ];
            });

        return response()->json($tables);
    }

    // ── Menu for POS ──────────────────────────────────────────────────────────

    public function menu()
    {
        // Total portions produced per menu_item (via recipe)
        $produced = DB::table('production_logs')
            ->join('recipes', 'production_logs.recipe_id', '=', 'recipes.id')
            ->select('recipes.menu_item_id', DB::raw('SUM(production_logs.quantity_produced) as total'))
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

        $categories = MenuCategory::with(['items' => function ($q) {
            $q->where('is_active', true)->orderBy('name');
        }])->get()->filter(fn($c) => $c->items->isNotEmpty())->values();

        // Attach available_qty to each item (null = no recipe / no tracking)
        $categories->each(function ($cat) use ($produced, $sold) {
            $cat->items->each(function ($item) use ($produced, $sold) {
                if ($produced->has($item->id)) {
                    $item->available_qty = max(0, $produced[$item->id] - ($sold[$item->id] ?? 0));
                } else {
                    $item->available_qty = null;
                }
            });
        });

        return response()->json($categories);
    }

    // ── Open a new order ──────────────────────────────────────────────────────

    public function openOrder(Request $request)
    {
        $validated = $request->validate([
            'table_id'      => 'required|exists:restaurant_tables,id',
            'restaurant_id' => 'required|exists:restaurant_masters,id',
            'covers'        => 'required|integer|min:1',
        ]);

        // Prevent duplicate open orders on the same table
        $existing = PosOrder::where('table_id', $validated['table_id'])
            ->whereIn('status', ['open', 'billed'])
            ->first();

        if ($existing) {
            return response()->json($this->formatOrder($existing->load('items.menuItem', 'payments')));
        }

        $order = DB::transaction(function () use ($validated) {
            $order = PosOrder::create([
                'table_id'      => $validated['table_id'],
                'restaurant_id' => $validated['restaurant_id'],
                'waiter_id'     => auth()->id(),
                'covers'        => $validated['covers'],
                'status'        => 'open',
                'opened_at'     => now(),
            ]);

            RestaurantTable::where('id', $validated['table_id'])
                ->update(['status' => 'occupied']);

            return $order;
        });

        return response()->json($this->formatOrder($order->load('items.menuItem', 'payments')), 201);
    }

    // ── Get a single order ────────────────────────────────────────────────────

    public function getOrder(PosOrder $order)
    {
        return response()->json($this->formatOrder($order->load('items.menuItem', 'payments')));
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
            // Keep track of which were already sent to KOT
            $kotSentIds = $order->items()->where('kot_sent', true)->pluck('menu_item_id')->toArray();

            $order->items()->delete();

            foreach ($validated['items'] as $row) {
                $menuItem = MenuItem::find($row['menu_item_id']);
                $unitPrice = floatval($menuItem->price);
                $taxRate   = 0; // extend later with tax configuration
                $lineTotal = $unitPrice * $row['quantity'];

                PosOrderItem::create([
                    'order_id'     => $order->id,
                    'menu_item_id' => $row['menu_item_id'],
                    'quantity'     => $row['quantity'],
                    'unit_price'   => $unitPrice,
                    'tax_rate'     => $taxRate,
                    'line_total'   => $lineTotal,
                    'kot_sent'     => in_array($row['menu_item_id'], $kotSentIds),
                    'notes'        => $row['notes'] ?? null,
                ]);
            }

            $this->recalculate($order);
        });

        return response()->json($this->formatOrder($order->fresh()->load('items.menuItem', 'payments')));
    }

    // ── Send KOT ──────────────────────────────────────────────────────────────

    public function sendKot(PosOrder $order)
    {
        $order->items()->where('kot_sent', false)->update(['kot_sent' => true]);
        return response()->json(['message' => 'KOT sent.']);
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

            // Free the table
            RestaurantTable::where('id', $order->table_id)
                ->update(['status' => 'available']);
        });

        return response()->json($this->formatOrder($order->fresh()->load('items.menuItem', 'payments')));
    }

    // ── Void ──────────────────────────────────────────────────────────────────

    public function void(PosOrder $order)
    {
        if ($order->status === 'paid') {
            return response()->json(['message' => 'Cannot void a paid order.'], 422);
        }

        DB::transaction(function () use ($order) {
            $order->update(['status' => 'void', 'closed_at' => now()]);
            RestaurantTable::where('id', $order->table_id)
                ->update(['status' => 'available']);
        });

        return response()->json(['message' => 'Order voided.']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function recalculate(PosOrder $order): void
    {
        $order->refresh();
        $subtotal = $order->items->sum(fn($i) => floatval($i->line_total));
        $taxAmount = $order->items->sum(fn($i) => floatval($i->line_total) * (floatval($i->tax_rate) / 100));

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
            'table_id'        => $order->table_id,
            'restaurant_id'   => $order->restaurant_id,
            'covers'          => $order->covers,
            'status'          => $order->status,
            'discount_type'   => $order->discount_type,
            'discount_value'  => (float) $order->discount_value,
            'subtotal'        => (float) $order->subtotal,
            'tax_amount'      => (float) $order->tax_amount,
            'discount_amount' => (float) $order->discount_amount,
            'total_amount'    => (float) $order->total_amount,
            'opened_at'       => $order->opened_at,
            'closed_at'       => $order->closed_at,
            'notes'           => $order->notes,
            'items'           => $order->items->map(fn($i) => [
                'id'           => $i->id,
                'menu_item_id' => $i->menu_item_id,
                'name'         => $i->menuItem?->name ?? 'Unknown',
                'category'     => $i->menuItem?->category?->name ?? null,
                'type'         => $i->menuItem?->type ?? null,
                'quantity'     => $i->quantity,
                'unit_price'   => (float) $i->unit_price,
                'tax_rate'     => (float) $i->tax_rate,
                'line_total'   => (float) $i->line_total,
                'kot_sent'     => $i->kot_sent,
                'notes'        => $i->notes,
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
