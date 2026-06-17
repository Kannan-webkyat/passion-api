<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\MenuItem;
use App\Models\ProductionLog;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Services\InventoryCostService;
use App\Services\RecipeBomValidator;
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
        $this->authorizeRecipeRead();

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

        $items = $query->get()->map(fn ($item) => $this->formatMenuItemRecipeRow($item));

        return response()->json($items);
    }

    /**
     * Unified list for kitchen batch production (menu dishes + semi-finished prep).
     */
    public function productionList()
    {
        $this->checkPermission('kitchen-production');

        $menuRows = MenuItem::query()
            ->whereHas('recipe', fn ($q) => $q
                ->where('requires_production', true)
                ->where('is_active', true)
                ->where('recipe_kind', Recipe::KIND_MENU_ITEM))
            ->with([
                'category',
                'recipe.ingredients.inventoryItem.issueUom',
                'recipe.yieldUom',
            ])
            ->get()
            ->map(function ($item) {
                $recipe = $item->recipe;

                return [
                    'recipe_id' => $recipe->id,
                    'name' => $item->name,
                    'item_code' => $item->item_code,
                    'recipe_kind' => Recipe::KIND_MENU_ITEM,
                    'category' => $item->category,
                    'output_inventory_item_id' => $item->inventory_item_id,
                    'recipe' => $this->formatRecipePayload($recipe, $item->price),
                ];
            });

        $prepRows = Recipe::query()
            ->where('recipe_kind', Recipe::KIND_SEMI_FINISHED)
            ->where('requires_production', true)
            ->where('is_active', true)
            ->with([
                'outputInventoryItem.issueUom',
                'ingredients.inventoryItem.issueUom',
                'yieldUom',
            ])
            ->get()
            ->map(function ($recipe) {
                return [
                    'recipe_id' => $recipe->id,
                    'name' => $recipe->display_name,
                    'item_code' => $recipe->outputInventoryItem?->sku,
                    'recipe_kind' => Recipe::KIND_SEMI_FINISHED,
                    'category' => $recipe->outputInventoryItem?->category,
                    'output_inventory_item_id' => $recipe->output_inventory_item_id,
                    'recipe' => $this->formatRecipePayload($recipe),
                ];
            });

        return response()->json(
            $menuRows->concat($prepRows)->sortBy('name')->values()
        );
    }

    /**
     * Get prep BOM for an inventory item (semi-finished).
     */
    public function showInventoryRecipe(int $inventoryItemId)
    {
        $this->authorizeRecipeRead();

        $item = InventoryItem::findOrFail($inventoryItemId);
        $recipe = Recipe::with([
            'ingredients.inventoryItem.issueUom',
            'yieldUom',
            'outputInventoryItem',
        ])
            ->where('output_inventory_item_id', $inventoryItemId)
            ->where('recipe_kind', Recipe::KIND_SEMI_FINISHED)
            ->first();

        return response()->json([
            'inventory_item' => $item,
            'has_recipe' => (bool) $recipe,
            'recipe' => $recipe ? $this->formatRecipePayload($recipe) : null,
        ]);
    }

    /**
     * Save (create or update) a full recipe for a menu item.
     */
    public function upsert(Request $request, $menuItemId)
    {
        $this->authorizeRecipeWrite();
        MenuItem::findOrFail($menuItemId);

        $validated = $this->validateRecipePayload($request);

        $ingredientIds = collect($validated['ingredients'])->pluck('inventory_item_id')->map(fn ($id) => (int) $id)->all();
        app(RecipeBomValidator::class)->validateNoDoubleCounting($ingredientIds);

        $recipe = DB::transaction(function () use ($menuItemId, $validated) {
            $recipe = Recipe::updateOrCreate(
                ['menu_item_id' => $menuItemId],
                [
                    'output_inventory_item_id' => null,
                    'recipe_kind' => Recipe::KIND_MENU_ITEM,
                    'yield_quantity' => $validated['yield_quantity'],
                    'yield_uom_id' => $validated['yield_uom_id'] ?? null,
                    'food_cost_target' => $validated['food_cost_target'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'is_active' => $validated['is_active'] ?? true,
                    'requires_production' => $validated['requires_production'] ?? true,
                ]
            );

            $this->syncIngredients($recipe, $validated['ingredients'], null);

            return $recipe->load('ingredients.inventoryItem', 'yieldUom');
        });

        return response()->json($recipe, 201);
    }

    /**
     * Save prep BOM for a semi-finished inventory item.
     */
    public function upsertInventoryRecipe(Request $request, int $inventoryItemId)
    {
        $this->authorizeRecipeWrite();

        $item = InventoryItem::findOrFail($inventoryItemId);
        $validated = $this->validateRecipePayload($request);

        $ingredientIds = collect($validated['ingredients'])->pluck('inventory_item_id')->map(fn ($id) => (int) $id)->all();
        $bomValidator = app(RecipeBomValidator::class);
        $bomValidator->validateNoCircularReference($inventoryItemId, $ingredientIds);
        $bomValidator->validateNoDoubleCounting($ingredientIds);

        $recipe = DB::transaction(function () use ($inventoryItemId, $validated, $item) {
            if (! $item->is_prepared_item) {
                $item->update(['is_prepared_item' => true]);
            }

            $recipe = Recipe::updateOrCreate(
                [
                    'output_inventory_item_id' => $inventoryItemId,
                    'recipe_kind' => Recipe::KIND_SEMI_FINISHED,
                ],
                [
                    'menu_item_id' => null,
                    'yield_quantity' => $validated['yield_quantity'],
                    'yield_uom_id' => $validated['yield_uom_id'] ?? $item->issue_uom_id,
                    'food_cost_target' => $validated['food_cost_target'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'is_active' => $validated['is_active'] ?? true,
                    'requires_production' => true,
                ]
            );

            $this->syncIngredients($recipe, $validated['ingredients'], $inventoryItemId);

            return $recipe->load('ingredients.inventoryItem', 'yieldUom', 'outputInventoryItem');
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

        $recipe = Recipe::with(['ingredients.inventoryItem', 'menuItem', 'outputInventoryItem'])->findOrFail($recipeId);
        $multiplier = $validated['quantity_produced'] / $recipe->yield_quantity;
        $refId = (string) Str::uuid();
        $displayName = $recipe->display_name;

        $totalProductionCost = 0;
        $affectedItemIds = [];

        try {
            DB::transaction(function () use ($recipe, $multiplier, $validated, $refId, $displayName, &$totalProductionCost, &$affectedItemIds) {
                $shortfalls = [];
                foreach ($recipe->ingredients as $ing) {
                    $item = $ing->inventoryItem;
                    if (! $item) {
                        continue;
                    }

                    $rawQty = round($ing->raw_quantity * $multiplier, 3);
                    $locationStock = DB::table('inventory_item_locations')
                        ->where('inventory_item_id', $ing->inventory_item_id)
                        ->where('inventory_location_id', $validated['inventory_location_id'])
                        ->value('quantity') ?? 0;

                    if ((float) $locationStock < $rawQty) {
                        $shortfalls[] = [
                            'item' => $item->name,
                            'required' => $rawQty,
                            'available' => (float) $locationStock,
                            'uom' => $ing->uom?->short_name ?? 'unit',
                        ];
                    }
                }

                if (! empty($shortfalls)) {
                    throw new \Exception(json_encode(['__shortfall' => true, 'errors' => $shortfalls]));
                }

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
                        'notes' => 'Batch Production: '.$displayName.' × '.$validated['quantity_produced'],
                        'user_id' => auth()->id(),
                        'reference_id' => $refId,
                        'reference_type' => 'production',
                    ]);
                }

                $outputItemId = $recipe->resolveOutputInventoryItemId();
                if ($outputItemId) {
                    $qtyProduced = (float) $validated['quantity_produced'];
                    $affectedItemIds[] = $outputItemId;
                    $batchIssueUnitCost = $qtyProduced > 0 ? $totalProductionCost / $qtyProduced : 0;

                    DB::table('inventory_item_locations')->updateOrInsert(
                        ['inventory_item_id' => $outputItemId, 'inventory_location_id' => $validated['inventory_location_id']],
                        ['updated_at' => now(), 'created_at' => now()]
                    );
                    DB::table('inventory_item_locations')
                        ->where('inventory_item_id', $outputItemId)
                        ->where('inventory_location_id', $validated['inventory_location_id'])
                        ->increment('quantity', $qtyProduced);

                    InventoryTransaction::create([
                        'inventory_item_id' => $outputItemId,
                        'inventory_location_id' => $validated['inventory_location_id'],
                        'type' => 'in',
                        'quantity' => $qtyProduced,
                        'unit_cost' => $batchIssueUnitCost,
                        'total_cost' => $totalProductionCost,
                        'reason' => $recipe->isSemiFinished() ? 'Semi-Finished Production' : 'Finished Goods',
                        'notes' => 'Produced Batch: '.$displayName,
                        'user_id' => auth()->id(),
                        'reference_id' => $refId,
                        'reference_type' => 'production_finished',
                    ]);

                    InventoryCostService::applyWeightedAverageCost(
                        $outputItemId,
                        $qtyProduced,
                        $batchIssueUnitCost
                    );
                }

                ProductionLog::create([
                    'recipe_id' => $recipe->id,
                    'inventory_location_id' => $validated['inventory_location_id'],
                    'quantity_produced' => $validated['quantity_produced'],
                    'unit_cost' => $validated['quantity_produced'] > 0
                        ? $totalProductionCost / $validated['quantity_produced']
                        : 0,
                    'total_cost' => $totalProductionCost,
                    'produced_by' => auth()->id(),
                    'production_date' => now(),
                    'notes' => $validated['notes'] ?? null,
                    'reference_id' => $refId,
                ]);
            });

            foreach (array_unique($affectedItemIds) as $itemId) {
                InventoryItem::syncStoredCurrentStockFromLocations($itemId);
            }
        } catch (\Exception $e) {
            $data = json_decode($e->getMessage(), true);
            if (isset($data['__shortfall'])) {
                return response()->json([
                    'message' => 'Insufficient stock for production.',
                    'errors' => $data['errors'],
                ], 422);
            }
            throw $e;
        }

        return response()->json(['message' => 'Production logged successfully.', 'reference_id' => $refId]);
    }

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

        $log->loadMissing(['recipe.menuItem', 'recipe.outputInventoryItem', 'recipe.yieldUom', 'location', 'producer']);

        return response()->json([
            'log' => [
                'id' => $log->id,
                'reference_id' => $log->reference_id,
                'recipe_name' => $log->recipe?->display_name ?? 'Unknown',
                'recipe_kind' => $log->recipe?->recipe_kind ?? Recipe::KIND_MENU_ITEM,
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

    public function productionLogs()
    {
        $this->checkPermission('kitchen-production');
        $logs = ProductionLog::with([
            'recipe.menuItem',
            'recipe.outputInventoryItem',
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
                'recipe_name' => $log->recipe?->display_name ?? 'Unknown',
                'recipe_kind' => $log->recipe?->recipe_kind ?? Recipe::KIND_MENU_ITEM,
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

    private function authorizeRecipeRead(): void
    {
        $user = auth()->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }
        if (! $user->hasRole('Admin') && ! $user->hasRole('Super Admin')) {
            if (! $user->can('manage-menu') && ! $user->can('kitchen-production') && ! $user->can('manage-inventory')) {
                abort(403, 'Unauthorized action.');
            }
        }
    }

    private function authorizeRecipeWrite(): void
    {
        $user = auth()->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }
        if (! $user->hasRole('Admin') && ! $user->hasRole('Super Admin')) {
            if (! $user->can('manage-menu') && ! $user->can('kitchen-production') && ! $user->can('manage-inventory')) {
                abort(403, 'Unauthorized action.');
            }
        }
    }

    private function checkPermission(string $permission): void
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function validateRecipePayload(Request $request): array
    {
        return $request->validate([
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
    }

    private function syncIngredients(Recipe $recipe, array $ingredients, ?int $forbiddenOutputItemId): void
    {
        $recipe->ingredients()->delete();
        foreach ($ingredients as $ing) {
            $inventoryItemId = (int) $ing['inventory_item_id'];
            if ($forbiddenOutputItemId && $inventoryItemId === $forbiddenOutputItemId) {
                abort(422, 'A recipe cannot include its own output item as an ingredient.');
            }

            RecipeIngredient::create([
                'recipe_id' => $recipe->id,
                'inventory_item_id' => $inventoryItemId,
                'uom_id' => $ing['uom_id'] ?? null,
                'quantity' => $ing['quantity'],
                'yield_percentage' => $ing['yield_percentage'] ?? 100,
                'notes' => $ing['notes'] ?? null,
            ]);
        }
    }

    private function formatMenuItemRecipeRow(MenuItem $item): array
    {
        $recipe = $item->recipe;
        $costPerPortion = $recipe ? round($recipe->cost_per_portion, 2) : null;

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
            'recipe' => $recipe ? $this->formatRecipePayload($recipe, $fallbackPrice, $foodCostPct, $foodCostByRestaurant) : null,
        ];
    }

    private function formatRecipePayload(
        Recipe $recipe,
        ?float $menuPrice = null,
        ?float $foodCostPct = null,
        array $foodCostByRestaurant = []
    ): array {
        $costPerPortion = round($recipe->cost_per_portion, 2);

        if ($foodCostPct === null && $menuPrice && $costPerPortion > 0) {
            $foodCostPct = round(($costPerPortion / $menuPrice) * 100, 1);
        }

        return [
            'id' => $recipe->id,
            'recipe_kind' => $recipe->recipe_kind ?? Recipe::KIND_MENU_ITEM,
            'output_inventory_item_id' => $recipe->output_inventory_item_id,
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
        ];
    }
}
