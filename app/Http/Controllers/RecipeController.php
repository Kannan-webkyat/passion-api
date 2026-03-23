<?php

namespace App\Http\Controllers;

use App\Models\InventoryTransaction;
use App\Models\MenuItem;
use App\Models\ProductionLog;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecipeController extends Controller
{
    /**
     * List all menu items with their recipe status.
     * Query: ?requires_production=1 — only items with recipe.requires_production = true (for Kitchen Production).
     */
    public function index(Request $request)
    {
        $this->checkPermission('kitchen-production');
        // Hide items that are direct-sale AND directly linked to an inventory item
        // (e.g. Pepsi, Sprite, spirits) — they are deducted directly, no recipe needed.
        // Show Tea, Coffee etc. (direct-sale but NO inventory link) so ingredients can be set.
        $query = MenuItem::where(function ($q) {
            $q->where('is_direct_sale', false)
              ->orWhereNull('is_direct_sale')
              ->orWhereNull('inventory_item_id');
        })
            ->with([
                'category',
                'subCategory',
                'recipe.ingredients.inventoryItem.issueUom',
                'recipe.yieldUom',
                'restaurantMenuItems.restaurant',
            ]);

        if ($request->boolean('requires_production')) {
            $query->whereHas('recipe', fn ($q) => $q->where('requires_production', true));
        }

        $items = $query->get()->map(function ($item) {
            $recipe = $item->recipe;
            $costPerPortion = $recipe ? round($recipe->cost_per_portion, 2) : null;

            // Per-restaurant food cost % (price varies by restaurant)
            $foodCostByRestaurant = [];
            if ($recipe && $costPerPortion > 0) {
                $links = $item->restaurantMenuItems ?? collect();
                foreach ($links->where('is_active', true) as $rmi) {
                    $price = (float) $rmi->price;
                    $foodCostByRestaurant[] = [
                        'restaurant_id' => $rmi->restaurant_master_id,
                        'restaurant_name' => $rmi->restaurant?->name ?? '—',
                        'price' => round($price, 2),
                        'food_cost_pct' => $price > 0 ? round(($costPerPortion / $price) * 100, 1) : null,
                    ];
                }
            }

            // Fallback: single food_cost_pct using menu_items.price (for items without restaurant links)
            $fallbackPrice = (float) $item->price;
            $foodCostPct = $recipe && $costPerPortion > 0 && $fallbackPrice > 0
                ? round(($costPerPortion / $fallbackPrice) * 100, 1)
                : (count($foodCostByRestaurant) > 0 ? $foodCostByRestaurant[0]['food_cost_pct'] : null);

            return [
                'id' => $item->id,
                'item_code' => $item->item_code,
                'name' => $item->name,
                'price' => $item->price,
                'type' => $item->type,
                'is_active' => $item->is_active,
                'category' => $item->category,
                'sub_category' => $item->subCategory,
                'has_recipe' => (bool) $recipe,
                'recipe' => $recipe ? [
                    'id' => $recipe->id,
                    'yield_quantity' => $recipe->yield_quantity,
                    'yield_uom' => $recipe->yieldUom,
                    'food_cost_target' => $recipe->food_cost_target,
                    'notes' => $recipe->notes,
                    'is_active' => $recipe->is_active,
                    'requires_production' => $recipe->requires_production ?? true,
                    'total_cost' => round($recipe->total_cost, 2),
                    'cost_per_portion' => $costPerPortion,
                    'food_cost_pct' => $foodCostPct,
                    'food_cost_by_restaurant' => $foodCostByRestaurant,
                    'ingredients' => $recipe->ingredients->map(fn ($ing) => [
                        'id' => $ing->id,
                        'inventory_item' => $ing->inventoryItem,
                        'uom' => $ing->uom,
                        'quantity' => $ing->quantity,
                        'yield_percentage' => $ing->yield_percentage,
                        'raw_quantity' => round($ing->raw_quantity, 3),
                        'line_cost' => round($ing->line_cost, 2),
                        'notes' => $ing->notes,
                    ]),
                ] : null,
            ];
        });

        return response()->json($items);
    }

    private function checkPermission(string $permission)
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    /**
     * Save (create or update) a full recipe for a menu item.
     */
    public function upsert(Request $request, $menuItemId)
    {
        $this->checkPermission('kitchen-production');
        $menuItem = MenuItem::findOrFail($menuItemId);
        // Direct-sale items (e.g. Tea, Coffee) are allowed to have recipes
        // so their ingredients can be tracked and deducted from inventory.

        $validated = $request->validate([
            'yield_quantity' => 'required|numeric|min:0.001',
            'yield_uom_id' => 'nullable|exists:inventory_uoms,id',
            'food_cost_target' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
            'requires_production' => 'boolean',
            'ingredients' => 'required|array|min:1',
            'ingredients.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'ingredients.*.uom_id' => 'nullable|exists:inventory_uoms,id',
            'ingredients.*.quantity' => 'required|numeric|min:0.001',
            'ingredients.*.yield_percentage' => 'nullable|numeric|min:1|max:100',
            'ingredients.*.notes' => 'nullable|string',
        ]);

        $recipe = DB::transaction(function () use ($menuItemId, $validated) {
            $recipe = Recipe::updateOrCreate(
                ['menu_item_id' => $menuItemId],
                [
                    'yield_quantity' => $validated['yield_quantity'],
                    'yield_uom_id' => $validated['yield_uom_id'] ?? null,
                    'food_cost_target' => $validated['food_cost_target'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'is_active' => $validated['is_active'] ?? true,
                    'requires_production' => $validated['requires_production'] ?? true,
                ]
            );

            // Replace all ingredients
            $recipe->ingredients()->delete();
            foreach ($validated['ingredients'] as $ing) {
                RecipeIngredient::create([
                    'recipe_id' => $recipe->id,
                    'inventory_item_id' => $ing['inventory_item_id'],
                    'uom_id' => $ing['uom_id'] ?? null,
                    'quantity' => $ing['quantity'],
                    'yield_percentage' => $ing['yield_percentage'] ?? 100,
                    'notes' => $ing['notes'] ?? null,
                ]);
            }

            return $recipe->load('ingredients.inventoryItem', 'yieldUom');
        });

        return response()->json($recipe, 201);
    }

    /**
     * Trigger a production run — deducts ingredients from kitchen stock.
     */
    public function produce(Request $request, $recipeId)
    {
        $this->checkPermission('kitchen-production');
        $validated = $request->validate([
            'quantity_produced' => 'required|numeric|min:0.001',
            'inventory_location_id' => 'required|exists:inventory_locations,id',
            'notes' => 'nullable|string',
        ]);

        $recipe = Recipe::with('ingredients.inventoryItem')->findOrFail($recipeId);
        $multiplier = $validated['quantity_produced'] / $recipe->yield_quantity;
        $refId = (string) Str::uuid();

        $insufficient = [];
        $totalProductionCost = 0;
        $affectedItemIds = [];

        try {
            DB::transaction(function () use ($recipe, $multiplier, $validated, $refId, &$totalProductionCost, &$affectedItemIds) {

                // ── 0. Pre-flight: check all ingredients have sufficient stock ──────
                $shortfalls = [];
                foreach ($recipe->ingredients as $ing) {
                    $item = $ing->inventoryItem;
                    if (! $item) continue;

                    $rawQty = round($ing->raw_quantity * $multiplier, 3);
                    $locationStock = DB::table('inventory_item_locations')
                        ->where('inventory_item_id', $ing->inventory_item_id)
                        ->where('inventory_location_id', $validated['inventory_location_id'])
                        ->value('quantity') ?? 0;

                    if ((float) $locationStock < $rawQty) {
                        $shortfalls[] = [
                            'item'      => $item->name,
                            'required'  => $rawQty,
                            'available' => (float) $locationStock,
                            'uom'       => $ing->uom?->short_name ?? 'unit',
                        ];
                    }
                }

                if (! empty($shortfalls)) {
                    throw new \Exception(json_encode(['__shortfall' => true, 'errors' => $shortfalls]));
                }

                // ── 1. Deduct raw ingredients and record snapshot costs ─────────────
                foreach ($recipe->ingredients as $ing) {
                    $item = $ing->inventoryItem;
                    if (! $item) {
                        continue;
                    }
                    $affectedItemIds[] = $ing->inventory_item_id;

                    $rawQty = round($ing->raw_quantity * $multiplier, 3);
                    $unitCostAtTime = floatval($item->cost_price ?? 0) / floatval($item->conversion_factor ?? 1);
                    $lineCostAtTime = $rawQty * $unitCostAtTime;
                    $totalProductionCost += $lineCostAtTime;

                    // Ensure row exists (supports negative stock — cook can produce even if store is at 0)
                    DB::table('inventory_item_locations')->updateOrInsert(
                        ['inventory_item_id' => $ing->inventory_item_id, 'inventory_location_id' => $validated['inventory_location_id']],
                        ['updated_at' => now(), 'created_at' => now()]
                    );

                    DB::table('inventory_item_locations')
                        ->where('inventory_item_id', $ing->inventory_item_id)
                        ->where('inventory_location_id', $validated['inventory_location_id'])
                        ->decrement('quantity', $rawQty);

                    InventoryTransaction::create([
                        'inventory_item_id' => $ing->inventory_item_id,
                        'inventory_location_id' => $validated['inventory_location_id'],
                        'type' => 'out',
                        'quantity' => $rawQty,
                        'unit_cost' => $unitCostAtTime,
                        'total_cost' => $lineCostAtTime,
                        'reason' => 'Production',
                        'notes' => 'Batch Production: '.($recipe->menuItem?->name ?? 'Unknown').' × '.$validated['quantity_produced'],
                        'user_id' => auth()->id(),
                        'reference_id' => $refId,
                        'reference_type' => 'production',
                    ]);
                }

                // ── 2. Increment stock of the FINISHED MENU ITEM (if linked to inventory) ──
                if ($recipe->menuItem && $recipe->menuItem->inventory_item_id) {
                    $qtyProduced = $validated['quantity_produced'];
                    $finishedItemId = $recipe->menuItem->inventory_item_id;
                    $affectedItemIds[] = $finishedItemId;

                    // Atomic increment for finished goods stock
                    DB::table('inventory_item_locations')->updateOrInsert(
                        ['inventory_item_id' => $finishedItemId, 'inventory_location_id' => $validated['inventory_location_id']],
                        ['updated_at' => now(), 'created_at' => now()]
                    );
                    DB::table('inventory_item_locations')
                        ->where('inventory_item_id', $finishedItemId)
                        ->where('inventory_location_id', $validated['inventory_location_id'])
                        ->increment('quantity', $qtyProduced);

                    InventoryTransaction::create([
                        'inventory_item_id' => $finishedItemId,
                        'inventory_location_id' => $validated['inventory_location_id'],
                        'type' => 'in',
                        'quantity' => $qtyProduced,
                        'unit_cost' => $totalProductionCost / $qtyProduced,
                        'total_cost' => $totalProductionCost,
                        'reason' => 'Finished Goods',
                        'notes' => 'Produced Batch: '.($recipe->menuItem->name),
                        'user_id' => auth()->id(),
                        'reference_id' => $refId,
                        'reference_type' => 'production_finished',
                    ]);
                }

                ProductionLog::create([
                    'recipe_id' => $recipe->id,
                    'inventory_location_id' => $validated['inventory_location_id'],
                    'quantity_produced' => $validated['quantity_produced'],
                    'unit_cost' => $totalProductionCost / $validated['quantity_produced'],
                    'total_cost' => $totalProductionCost,
                    'produced_by' => auth()->id(),
                    'production_date' => now(),
                    'notes' => $validated['notes'] ?? null,
                    'reference_id' => $refId,
                ]);
            });

            // ── SYNC CACHED STOCK ──
            foreach (array_unique($affectedItemIds) as $itemId) {
                \App\Models\InventoryItem::syncStoredCurrentStockFromLocations($itemId);
            }
        } catch (\Exception $e) {
            $data = json_decode($e->getMessage(), true);
            if (isset($data['__shortfall'])) {
                return response()->json([
                    'message' => 'Insufficient stock for production.',
                    'errors' => $data['errors']
                ], 422);
            }
            throw $e; 
        }

        return response()->json(['message' => 'Production logged successfully.', 'reference_id' => $refId]);
    }

    /**
     * Return ingredient breakdown for a single production run.
     */
    public function productionLogDetails(ProductionLog $log)
    {
        $this->checkPermission('kitchen-production');
        $ingredients = InventoryTransaction::with(['item.issueUom'])
            ->where('reference_id', $log->reference_id)
            ->where('reference_type', 'production')
            ->get()
            ->map(fn ($tx) => [
                'name' => $tx->item?->name ?? 'Unknown',
                'quantity' => (float) $tx->quantity,
                'uom' => $tx->item?->issueUom?->short_name ?? 'unit',
                'unit_cost' => (float) $tx->unit_cost,
                'total_cost' => (float) $tx->total_cost,
            ]);

        $log->loadMissing(['recipe.menuItem', 'recipe.yieldUom', 'location', 'producer']);

        return response()->json([
            'log' => [
                'id' => $log->id,
                'reference_id' => $log->reference_id,
                'recipe_name' => $log->recipe?->menuItem?->name ?? 'Unknown',
                'yield_uom' => $log->recipe?->yieldUom?->short_name ?? 'unit',
                'quantity_produced' => (float) $log->quantity_produced,
                'unit_cost' => (float) $log->unit_cost,
                'total_cost' => (float) $log->total_cost,
                'location' => $log->location?->name ?? '—',
                'produced_by' => $log->producer?->name ?? '—',
                'production_date' => $log->production_date,
                'notes' => $log->notes,
            ],
            'ingredients' => $ingredients,
        ]);
    }

    /**
     * List recent production runs.
     */
    public function productionLogs()
    {
        $this->checkPermission('kitchen-production');
        $logs = ProductionLog::with([
            'recipe.menuItem',
            'recipe.yieldUom',
            'location',
            'producer',
        ])
            ->orderByDesc('production_date')
            ->limit(50)
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'reference_id' => $log->reference_id,
                'recipe_name' => $log->recipe?->menuItem?->name ?? 'Unknown',
                'yield_uom' => $log->recipe?->yieldUom?->short_name ?? 'unit',
                'quantity_produced' => $log->quantity_produced,
                'unit_cost' => $log->unit_cost,
                'total_cost' => $log->total_cost,
                'location' => $log->location?->name ?? '—',
                'produced_by' => $log->producer?->name ?? '—',
                'production_date' => $log->production_date,
                'notes' => $log->notes,
            ]);

        return response()->json($logs);
    }
}