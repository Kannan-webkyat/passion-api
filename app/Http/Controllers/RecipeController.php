<?php

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\MenuItem;
use App\Models\InventoryTransaction;
use App\Models\InventoryItem;
use App\Models\ProductionLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecipeController extends Controller
{
    /**
     * List all menu items with their recipe status.
     */
    public function index()
    {
        $items = MenuItem::with([
            'category',
            'subCategory',
            'recipe.ingredients.inventoryItem.issueUom',
            'recipe.yieldUom',
            'restaurantMenuItems.restaurant',
        ])->get()->map(function ($item) {
            $recipe = $item->recipe;
            $costPerPortion = $recipe ? round($recipe->cost_per_portion, 2) : null;

            // Per-restaurant food cost % (price varies by restaurant)
            $foodCostByRestaurant = [];
            if ($recipe && $costPerPortion > 0) {
                $links = $item->restaurantMenuItems ?? collect();
                foreach ($links->where('is_active', true) as $rmi) {
                    $price = (float) $rmi->price;
                    $foodCostByRestaurant[] = [
                        'restaurant_id'   => $rmi->restaurant_master_id,
                        'restaurant_name'  => $rmi->restaurant?->name ?? '—',
                        'price'            => round($price, 2),
                        'food_cost_pct'    => $price > 0 ? round(($costPerPortion / $price) * 100, 1) : null,
                    ];
                }
            }

            // Fallback: single food_cost_pct using menu_items.price (for items without restaurant links)
            $fallbackPrice = (float) $item->price;
            $foodCostPct = $recipe && $costPerPortion > 0 && $fallbackPrice > 0
                ? round(($costPerPortion / $fallbackPrice) * 100, 1)
                : (count($foodCostByRestaurant) > 0 ? $foodCostByRestaurant[0]['food_cost_pct'] : null);

            return [
                'id'            => $item->id,
                'item_code'     => $item->item_code,
                'name'          => $item->name,
                'price'         => $item->price,
                'type'          => $item->type,
                'is_active'     => $item->is_active,
                'category'      => $item->category,
                'sub_category'  => $item->subCategory,
                'has_recipe'    => !!$recipe,
                'recipe'        => $recipe ? [
                    'id'                   => $recipe->id,
                    'yield_quantity'       => $recipe->yield_quantity,
                    'yield_uom'            => $recipe->yieldUom,
                    'food_cost_target'     => $recipe->food_cost_target,
                    'notes'                => $recipe->notes,
                    'is_active'            => $recipe->is_active,
                    'requires_production'  => $recipe->requires_production ?? true,
                    'total_cost'           => round($recipe->total_cost, 2),
                    'cost_per_portion'     => $costPerPortion,
                    'food_cost_pct'        => $foodCostPct,
                    'food_cost_by_restaurant' => $foodCostByRestaurant,
                    'ingredients'          => $recipe->ingredients->map(fn($ing) => [
                        'id'               => $ing->id,
                        'inventory_item'   => $ing->inventoryItem,
                        'uom'              => $ing->uom,
                        'quantity'         => $ing->quantity,
                        'yield_percentage' => $ing->yield_percentage,
                        'raw_quantity'     => round($ing->raw_quantity, 3),
                        'line_cost'        => round($ing->line_cost, 2),
                        'notes'            => $ing->notes,
                    ]),
                ] : null,
            ];
        });

        return response()->json($items);
    }

    /**
     * Save (create or update) a full recipe for a menu item.
     */
    public function upsert(Request $request, $menuItemId)
    {
        $validated = $request->validate([
            'yield_quantity'       => 'required|numeric|min:0.001',
            'yield_uom_id'         => 'nullable|exists:inventory_uoms,id',
            'food_cost_target'     => 'nullable|numeric|min:0|max:100',
            'notes'                => 'nullable|string',
            'is_active'            => 'boolean',
            'requires_production'  => 'boolean',
            'ingredients'          => 'required|array|min:1',
            'ingredients.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'ingredients.*.uom_id'            => 'nullable|exists:inventory_uoms,id',
            'ingredients.*.quantity'          => 'required|numeric|min:0.001',
            'ingredients.*.yield_percentage'  => 'nullable|numeric|min:1|max:100',
            'ingredients.*.notes'             => 'nullable|string',
        ]);

        $recipe = DB::transaction(function () use ($menuItemId, $validated) {
            $recipe = Recipe::updateOrCreate(
                ['menu_item_id' => $menuItemId],
                [
                    'yield_quantity'       => $validated['yield_quantity'],
                    'yield_uom_id'         => $validated['yield_uom_id'] ?? null,
                    'food_cost_target'     => $validated['food_cost_target'] ?? null,
                    'notes'                => $validated['notes'] ?? null,
                    'is_active'            => $validated['is_active'] ?? true,
                    'requires_production'  => $validated['requires_production'] ?? true,
                ]
            );

            // Replace all ingredients
            $recipe->ingredients()->delete();
            foreach ($validated['ingredients'] as $ing) {
                RecipeIngredient::create([
                    'recipe_id'           => $recipe->id,
                    'inventory_item_id'   => $ing['inventory_item_id'],
                    'uom_id'              => $ing['uom_id'] ?? null,
                    'quantity'            => $ing['quantity'],
                    'yield_percentage'    => $ing['yield_percentage'] ?? 100,
                    'notes'               => $ing['notes'] ?? null,
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
        $validated = $request->validate([
            'quantity_produced'    => 'required|numeric|min:0.001',
            'inventory_location_id'=> 'required|exists:inventory_locations,id',
            'notes'                => 'nullable|string',
        ]);

        $recipe = Recipe::with('ingredients.inventoryItem')->findOrFail($recipeId);
        $multiplier = $validated['quantity_produced'] / $recipe->yield_quantity;
        $refId      = (string) Str::uuid();

        $insufficient        = [];
        $totalProductionCost = 0;

        try {
            DB::transaction(function () use ($recipe, $multiplier, $validated, $refId, &$insufficient, &$totalProductionCost) {

                // ── Pass 1: Lock rows and verify stock atomically ──────────────
                // lockForUpdate() issues SELECT … FOR UPDATE so concurrent
                // production requests are serialised at the DB level.
                foreach ($recipe->ingredients as $ing) {
                    $rawQty       = round($ing->raw_quantity * $multiplier, 3);
                    $currentStock = DB::table('inventory_item_locations')
                        ->where('inventory_item_id',       $ing->inventory_item_id)
                        ->where('inventory_location_id',   $validated['inventory_location_id'])
                        ->lockForUpdate()
                        ->value('quantity') ?? 0;

                    if ((float) $currentStock < $rawQty) {
                        $insufficient[] = [
                            'item'      => $ing->inventoryItem->name,
                            'required'  => $rawQty,
                            'available' => (float) $currentStock,
                            'uom'       => $ing->uom?->short_name ?? 'unit',
                        ];
                    }
                }

                // Throw inside the transaction so it rolls back automatically
                // before any stock is touched.
                if (!empty($insufficient)) {
                    throw new \RuntimeException('INSUFFICIENT_STOCK');
                }

                // ── Pass 2: Deduct stock and record snapshot costs ─────────────
                foreach ($recipe->ingredients as $ing) {
                    $rawQty = round($ing->raw_quantity * $multiplier, 3);

                    $item            = $ing->inventoryItem;
                    $unitCostAtTime  = floatval($item->cost_price ?? 0) / floatval($item->conversion_factor ?? 1);
                    $lineCostAtTime  = $rawQty * $unitCostAtTime;
                    $totalProductionCost += $lineCostAtTime;

                    DB::table('inventory_item_locations')
                        ->where('inventory_item_id',     $ing->inventory_item_id)
                        ->where('inventory_location_id', $validated['inventory_location_id'])
                        ->decrement('quantity', $rawQty);

                    InventoryTransaction::create([
                        'inventory_item_id'    => $ing->inventory_item_id,
                        'inventory_location_id'=> $validated['inventory_location_id'],
                        'type'                 => 'out',
                        'quantity'             => $rawQty,
                        'unit_cost'            => $unitCostAtTime,   // price snapshot
                        'total_cost'           => $lineCostAtTime,   // price snapshot
                        'reason'               => 'Production',
                        'notes'                => 'Recipe: ' . $recipe->menuItem->name . ' × ' . $validated['quantity_produced'],
                        'user_id'              => auth()->id(),
                        'reference_id'         => $refId,
                        'reference_type'       => 'production',
                    ]);
                }

                ProductionLog::create([
                    'recipe_id'             => $recipe->id,
                    'inventory_location_id' => $validated['inventory_location_id'],
                    'quantity_produced'     => $validated['quantity_produced'],
                    'unit_cost'             => $totalProductionCost / $validated['quantity_produced'],
                    'total_cost'            => $totalProductionCost,
                    'produced_by'           => auth()->id(),
                    'production_date'       => now(),
                    'notes'                 => $validated['notes'] ?? null,
                    'reference_id'          => $refId,
                ]);
            });

            foreach ($recipe->ingredients as $ing) {
                \App\Models\InventoryItem::syncStoredCurrentStockFromLocations($ing->inventory_item_id);
            }

        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'INSUFFICIENT_STOCK') {
                return response()->json([
                    'message' => 'Insufficient stock for one or more ingredients.',
                    'errors'  => $insufficient,
                ], 422);
            }
            throw $e; // unexpected error — let the default handler take over
        }

        return response()->json(['message' => 'Production logged successfully.', 'reference_id' => $refId]);
    }

    /**
     * Return ingredient breakdown for a single production run.
     */
    public function productionLogDetails(ProductionLog $log)
    {
        $ingredients = InventoryTransaction::with(['item.issueUom'])
            ->where('reference_id', $log->reference_id)
            ->where('reference_type', 'production')
            ->get()
            ->map(fn($tx) => [
                'name'       => $tx->item?->name ?? 'Unknown',
                'quantity'   => (float) $tx->quantity,
                'uom'        => $tx->item?->issueUom?->short_name ?? 'unit',
                'unit_cost'  => (float) $tx->unit_cost,
                'total_cost' => (float) $tx->total_cost,
            ]);

        $log->loadMissing(['recipe.menuItem', 'recipe.yieldUom', 'location', 'producer']);

        return response()->json([
            'log' => [
                'id'                => $log->id,
                'reference_id'      => $log->reference_id,
                'recipe_name'       => $log->recipe?->menuItem?->name ?? 'Unknown',
                'yield_uom'         => $log->recipe?->yieldUom?->short_name ?? 'unit',
                'quantity_produced' => (float) $log->quantity_produced,
                'unit_cost'         => (float) $log->unit_cost,
                'total_cost'        => (float) $log->total_cost,
                'location'          => $log->location?->name ?? '—',
                'produced_by'       => $log->producer?->name ?? '—',
                'production_date'   => $log->production_date,
                'notes'             => $log->notes,
            ],
            'ingredients' => $ingredients,
        ]);
    }

    /**
     * List recent production runs.
     */
    public function productionLogs()
    {
        $logs = ProductionLog::with([
                'recipe.menuItem',
                'recipe.yieldUom',
                'location',
                'producer',
            ])
            ->orderByDesc('production_date')
            ->limit(50)
            ->get()
            ->map(fn($log) => [
                'id'                => $log->id,
                'reference_id'      => $log->reference_id,
                'recipe_name'       => $log->recipe?->menuItem?->name ?? 'Unknown',
                'yield_uom'         => $log->recipe?->yieldUom?->short_name ?? 'unit',
                'quantity_produced' => $log->quantity_produced,
                'unit_cost'         => $log->unit_cost,
                'total_cost'        => $log->total_cost,
                'location'          => $log->location?->name ?? '—',
                'produced_by'       => $log->producer?->name ?? '—',
                'production_date'   => $log->production_date,
                'notes'             => $log->notes,
            ]);

        return response()->json($logs);
    }
}
