<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\MenuItem;
use App\Models\Recipe;
use App\Models\RestaurantMaster;
use Illuminate\Support\Facades\DB;

class InventoryDeductionStoreResolver
{
    public function __construct(
        private readonly RecipeBomExpander $bomExpander,
    ) {}
    /**
     * Which location stock is consumed from for a POS line (Kitchen → Transfer → Bar model).
     */
    public function resolve(
        ?MenuItem $menuItem,
        ?InventoryLocation $kitchenStore,
        ?InventoryLocation $barStore,
        ?RestaurantMaster $restaurant = null,
        ?Recipe $recipe = null
    ): ?InventoryLocation {
        if (! $menuItem) {
            return $kitchenStore ?? $barStore;
        }

        if ($recipe === null) {
            $recipe = Recipe::query()
                ->where('menu_item_id', $menuItem->id)
                ->where('is_active', true)
                ->first();
        }

        if ($recipe && ! ($recipe->requires_production ?? true)) {
            return $this->usesBarStore($menuItem, $restaurant, $barStore, $kitchenStore)
                ? ($barStore ?? $kitchenStore)
                : ($kitchenStore ?? $barStore);
        }

        if ($menuItem->inventory_item_id) {
            return $this->resolveFinishedGoodStore(
                $menuItem,
                $restaurant,
                $kitchenStore,
                $barStore
            );
        }

        return $kitchenStore ?? $barStore;
    }

    /**
     * Batch / direct inventory SKU — prefer bar rules, then stock location for prepared goods.
     */
    private function resolveFinishedGoodStore(
        MenuItem $menuItem,
        ?RestaurantMaster $restaurant,
        ?InventoryLocation $kitchenStore,
        ?InventoryLocation $barStore
    ): ?InventoryLocation {
        if ($this->usesBarStore($menuItem, $restaurant, $barStore, $kitchenStore)) {
            return $barStore ?? $kitchenStore;
        }

        $menuItem->loadMissing('inventoryItem');
        if ($menuItem->inventoryItem?->is_prepared_item && $kitchenStore && $barStore) {
            $barQty = $this->quantityAt($menuItem->inventory_item_id, $barStore->id);
            $kitchenQty = $this->quantityAt($menuItem->inventory_item_id, $kitchenStore->id);

            if ($barQty > 0.0001 && $kitchenQty <= 0.0001) {
                return $barStore;
            }
            if ($kitchenQty > 0.0001 && $barQty <= 0.0001) {
                return $kitchenStore;
            }
        }

        if ($menuItem->is_direct_sale && $barStore) {
            return $barStore;
        }

        return $kitchenStore ?? $barStore;
    }

    /**
     * Bar POS / bar-menu items consume bar-shelf stock only (post-transfer).
     */
    public function usesBarStore(
        MenuItem $menuItem,
        ?RestaurantMaster $restaurant,
        ?InventoryLocation $barStore,
        ?InventoryLocation $kitchenStore
    ): bool {
        if (! $barStore || ! $restaurant?->bar_location_id) {
            return false;
        }
        if ((int) $restaurant->bar_location_id !== (int) $barStore->id) {
            return false;
        }

        if ($menuItem->is_direct_sale) {
            return true;
        }

        if (! $restaurant->kitchen_location_id
            || (int) $restaurant->kitchen_location_id === (int) $barStore->id) {
            return true;
        }

        $menuItem->loadMissing('category', 'inventoryItem');
        if ($menuItem->inventoryItem?->is_alcohol) {
            return true;
        }
        $categoryName = strtolower($menuItem->category?->name ?? '');

        return str_contains($categoryName, 'alcohol') || str_contains($categoryName, 'bar');
    }

    public function quantityAt(int $inventoryItemId, int $locationId): float
    {
        return (float) (DB::table('inventory_item_locations')
            ->where('inventory_item_id', $inventoryItemId)
            ->where('inventory_location_id', $locationId)
            ->value('quantity') ?? 0);
    }

    /**
     * @return array<int, float> inventory_item_id => quantity
     */
    public function stockMapAtLocation(?InventoryLocation $location): array
    {
        if (! $location) {
            return [];
        }

        return DB::table('inventory_item_locations')
            ->where('inventory_location_id', $location->id)
            ->pluck('quantity', 'inventory_item_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * Adjust stock map for open orders not yet deducted (MTO ingredient commitment).
     *
     * @param  array<int, float>  $stockMap
     * @return array<int, float>
     */
    public function adjustStockMapForCommittedMto(
        array $stockMap,
        iterable $recipes,
        iterable $committedPortionsByMenuItemId,
        callable $usesBarStoreForMenuItemId
    ): array {
        $adjusted = $stockMap;

        foreach ($recipes as $recipe) {
            $portions = (float) ($committedPortionsByMenuItemId[$recipe->menu_item_id] ?? 0);
            if ($portions <= 0) {
                continue;
            }
            $multiplier = $portions / max(0.001, (float) $recipe->yield_quantity);
            foreach ($this->bomExpander->flattenedRequirements($recipe, $multiplier) as $id => $used) {
                $adjusted[$id] = max(0, ($adjusted[$id] ?? 0) - $used);
            }
        }

        return $adjusted;
    }

    /**
     * @param  array<int, float>  $stockMap
     */
    public function mtoRecipeIsSoldOut(Recipe $recipe, MenuItem $menuItem, array $stockMap): bool
    {
        $multiplier = 1 / max(0.001, (float) $recipe->yield_quantity);
        $requirements = $this->bomExpander->flattenedRequirements($recipe, $multiplier);

        if ($requirements === []) {
            return true;
        }

        foreach ($requirements as $itemId => $needQty) {
            if (($stockMap[$itemId] ?? 0) < $needQty) {
                return true;
            }
        }

        return false;
    }
}
