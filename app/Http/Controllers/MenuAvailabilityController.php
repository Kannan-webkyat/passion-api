<?php

namespace App\Http\Controllers;

use App\Models\MenuItem;
use App\Models\Recipe;
use App\Models\RestaurantMaster;
use App\Models\RestaurantMenuItem;
use App\Services\BatchProductionPoolService;
use App\Services\BomEnforcementConfig;
use App\Services\BusinessDateService;
use App\Services\InventoryDeductionStoreResolver;
use Illuminate\Http\Request;

class MenuAvailabilityController extends Controller
{
    public function __construct(
        private InventoryDeductionStoreResolver $storeResolver,
        private BatchProductionPoolService $batchPool,
    ) {}

    private function checkPermission(string $permission): void
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->hasRole('Super Admin') && ! $user->can($permission)
            && ! ($permission === 'menu-availability' && $user->can('manage-menu'))) {
            abort(403, 'Unauthorized action.');
        }
    }

    /**
     * Outlet-scoped menu for chefs: Available toggle + availability reason (not pricing).
     */
    public function index(Request $request)
    {
        $this->checkPermission('menu-availability');

        $validated = $request->validate([
            'restaurant_id' => 'required|exists:restaurant_masters,id',
        ]);

        $restaurantId = (int) $validated['restaurant_id'];
        $restaurant = RestaurantMaster::findOrFail($restaurantId);

        $rmis = RestaurantMenuItem::with([
            'menuItem.category',
            'menuItem.variants',
            'menuItem.recipe',
            'menuItem.inventoryItem',
            'variantOverrides',
        ])
            ->where('restaurant_master_id', $restaurantId)
            ->get()
            ->sortBy(fn (RestaurantMenuItem $rmi) => strtolower($rmi->menuItem?->name ?? ''))
            ->values();

        $menuItemIds = $rmis->pluck('menu_item_id')->map(fn ($id) => (int) $id)->all();

        $kitchenStore = $restaurant->kitchen_location_id
            ? \App\Models\InventoryLocation::find($restaurant->kitchen_location_id)
            : null;
        $barStore = $restaurant->bar_location_id
            ? \App\Models\InventoryLocation::find($restaurant->bar_location_id)
            : null;

        $batchMenuItemIds = Recipe::where('is_active', true)
            ->where('requires_production', true)
            ->whereIn('menu_item_id', $menuItemIds)
            ->pluck('menu_item_id')
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();

        $businessDate = BusinessDateService::resolve($restaurant);
        $produced = $batchMenuItemIds
            ? $this->batchPool->producedByMenuItem($businessDate, $batchMenuItemIds)
            : collect();
        $committed = $batchMenuItemIds
            ? $this->batchPool->committedSalesByMenuItem(
                $batchMenuItemIds,
                $businessDate,
                null,
                false,
                $restaurantId,
            )
            : collect();

        $mtoRecipes = Recipe::where('is_active', true)
            ->where('requires_production', false)
            ->whereIn('menu_item_id', $menuItemIds)
            ->with(['ingredients', 'menuItem.category', 'menuItem.inventoryItem'])
            ->get()
            ->keyBy('menu_item_id');

        $kitchenStock = collect($this->storeResolver->stockMapAtLocation($kitchenStore));
        $barStock = collect($this->storeResolver->stockMapAtLocation($barStore));
        $bomEnforcementOn = BomEnforcementConfig::isEnabled();

        $rows = $rmis->map(function (RestaurantMenuItem $rmi) use (
            $restaurant,
            $kitchenStore,
            $barStore,
            $produced,
            $committed,
            $mtoRecipes,
            $kitchenStock,
            $barStock,
            $businessDate,
            $bomEnforcementOn,
        ) {
            $item = $rmi->menuItem;
            if (! $item) {
                return null;
            }

            $variants = $item->variants ?? collect();
            $hasVariants = $variants->isNotEmpty();
            $priced = false;
            $sellHint = null;

            if ($hasVariants) {
                foreach ($variants as $v) {
                    $ov = $rmi->variantOverrides->firstWhere('menu_item_variant_id', $v->id);
                    $price = $ov ? (float) $ov->price : (float) ($v->price ?? 0);
                    if ($price > 0) {
                        $priced = true;
                    }
                }
                $sellHint = $priced ? null : 'No variant sell price > 0 at this outlet';
            } else {
                $priced = (float) ($rmi->price ?? 0) > 0;
                $sellHint = $priced ? null : 'Outlet sell price is 0';
            }

            $stock = $this->stockSnapshot(
                $item,
                $restaurant,
                $kitchenStore,
                $barStore,
                $produced,
                $committed,
                $mtoRecipes,
                $kitchenStock,
                $barStock,
                $businessDate,
                $bomEnforcementOn,
            );

            $turnedOff = ! (bool) $rmi->is_active;
            $itemInactive = ! (bool) $item->is_active;
            $soldOut = (bool) ($stock['sold_out'] ?? false);

            $status = 'available';
            $statusLabel = 'Available';
            $reason = null;

            if ($itemInactive) {
                $status = 'item_inactive';
                $statusLabel = 'Item inactive';
                $reason = 'Menu item is inactive in Menu Configuration.';
            } elseif ($turnedOff) {
                $status = 'turned_off';
                $statusLabel = 'Turned off';
                $reason = 'Chef / manager turned this item off for this outlet.';
            } elseif (! $priced) {
                $status = 'not_priced';
                $statusLabel = 'Not priced';
                $reason = $sellHint.' — set sell price in Menu Pricing.';
            } elseif ($soldOut) {
                $status = 'sold_out';
                $statusLabel = 'Sold out';
                $reason = $stock['fix'] ?? $stock['summary'] ?? 'Out of stock for POS rules.';
            }

            return [
                'restaurant_menu_item_id' => $rmi->id,
                'menu_item_id' => $item->id,
                'item_code' => $item->item_code,
                'name' => $item->name,
                'type' => $item->type,
                'category' => $item->category?->name,
                'menu_category_id' => $item->menu_category_id,
                'item_kind' => $this->itemKind($item),
                'item_active' => ! $itemInactive,
                'outlet_enabled' => ! $turnedOff,
                'is_priced' => $priced,
                'sold_out' => $soldOut,
                'available_qty' => $stock['available_qty'],
                'stock_mode' => $stock['mode'],
                'status' => $status,
                'status_label' => $statusLabel,
                'reason' => $reason,
                'stock_summary' => $stock['summary'] ?? null,
                'stock_fix' => $stock['fix'] ?? null,
            ];
        })->filter()->values();

        return response()->json([
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
            ],
            'business_date' => $businessDate,
            'bom_stock_enforcement' => $bomEnforcementOn,
            'enforcement_note' => $bomEnforcementOn
                ? null
                : 'Recipe stock rules are off. Use the toggle to hide items from POS. Liquor / bottle stock still applies.',
            'items' => $rows,
        ]);
    }

    /**
     * Toggle outlet availability (restaurant_menu_items.is_active) for chefs.
     */
    public function update(Request $request)
    {
        $this->checkPermission('menu-availability');

        $validated = $request->validate([
            'restaurant_id' => 'required|exists:restaurant_masters,id',
            'menu_item_id' => 'required|exists:menu_items,id',
            'is_active' => 'required|boolean',
        ]);

        $rmi = RestaurantMenuItem::where('restaurant_master_id', (int) $validated['restaurant_id'])
            ->where('menu_item_id', (int) $validated['menu_item_id'])
            ->first();

        if (! $rmi) {
            return response()->json([
                'message' => 'Item is not linked to this outlet. Link it under Menu Configuration first.',
            ], 422);
        }

        $rmi->is_active = (bool) $validated['is_active'];
        $rmi->save();

        return response()->json([
            'restaurant_menu_item_id' => $rmi->id,
            'menu_item_id' => $rmi->menu_item_id,
            'restaurant_id' => $rmi->restaurant_master_id,
            'outlet_enabled' => (bool) $rmi->is_active,
        ]);
    }

    private function stockSnapshot(
        MenuItem $item,
        RestaurantMaster $restaurant,
        $kitchenStore,
        $barStore,
        $produced,
        $committed,
        $mtoRecipes,
        $kitchenStock,
        $barStock,
        string $businessDate,
        bool $bomEnforcementOn = true,
    ): array {
        $recipe = $item->recipe;

        if ($recipe && (bool) ($recipe->requires_production ?? true)) {
            if (! $bomEnforcementOn) {
                return [
                    'mode' => 'manual_only',
                    'sold_out' => false,
                    'available_qty' => null,
                    'summary' => 'Recipe enforcement off — chef toggle controls POS visibility.',
                    'fix' => null,
                ];
            }

            $prod = (float) $produced->get($item->id, 0);
            $comm = (float) $committed->get($item->id, 0);
            $avail = max(0, $prod - $comm);

            return [
                'mode' => 'batch_production',
                'sold_out' => $avail <= 0,
                'available_qty' => $avail,
                'summary' => 'Batch recipe: uses today’s production pool, not raw kitchen qty.',
                'fix' => $avail > 0
                    ? null
                    : 'Produce portions for business date '.$businessDate.' (or switch recipe to made-to-order).',
            ];
        }

        if ($item->inventory_item_id) {
            $item->loadMissing('inventoryItem');
            $isLiquorShelf = (bool) ($item->is_direct_sale ?? false)
                || (bool) ($item->inventoryItem?->is_alcohol ?? false);

            if (! $bomEnforcementOn && ! $isLiquorShelf) {
                return [
                    'mode' => 'manual_only',
                    'sold_out' => false,
                    'available_qty' => null,
                    'summary' => 'Recipe enforcement off — chef toggle controls POS visibility.',
                    'fix' => null,
                ];
            }

            $targetStore = $this->storeResolver->resolve($item, $kitchenStore, $barStore, $restaurant, $recipe);
            $phys = $targetStore
                ? $this->storeResolver->quantityAt((int) $item->inventory_item_id, (int) $targetStore->id)
                : 0.0;

            return [
                'mode' => 'finished_good',
                'sold_out' => $phys <= 0,
                'available_qty' => max(0, $phys),
                'summary' => 'Finished-good SKU at '.($targetStore?->name ?? 'mapped store').'.',
                'fix' => $phys > 0
                    ? null
                    : 'Transfer stock into '.($targetStore?->name ?? 'kitchen/bar store').'.',
            ];
        }

        if ($recipe && ! (bool) ($recipe->requires_production ?? true)) {
            if (! $bomEnforcementOn) {
                return [
                    'mode' => 'manual_only',
                    'sold_out' => false,
                    'available_qty' => null,
                    'summary' => 'Recipe enforcement off — chef toggle controls POS visibility.',
                    'fix' => null,
                ];
            }

            $mto = $mtoRecipes->get($item->id) ?? $recipe;
            $usesBar = $this->storeResolver->usesBarStore($item, $restaurant, $barStore, $kitchenStore);
            $stockMap = ($usesBar ? $barStock : $kitchenStock)->all();
            $soldOut = $this->storeResolver->mtoRecipeIsSoldOut($mto, $item, $stockMap);

            return [
                'mode' => 'made_to_order',
                'sold_out' => $soldOut,
                'available_qty' => $soldOut ? 0 : null,
                'summary' => 'Made-to-order: checks recipe ingredients at kitchen/bar.',
                'fix' => $soldOut
                    ? 'Short ingredients at '.(($usesBar ? $barStore : $kitchenStore)?->name ?? 'mapped store').'.'
                    : null,
            ];
        }

        return [
            'mode' => 'untracked',
            'sold_out' => false,
            'available_qty' => null,
            'summary' => 'Not stock-tracked on POS.',
            'fix' => null,
        ];
    }

    private function itemKind(MenuItem $item): string
    {
        $item->loadMissing('category', 'inventoryItem');

        if ($item->is_direct_sale || (bool) ($item->inventoryItem?->is_alcohol ?? false)) {
            return 'alcohol';
        }

        $categoryName = strtolower($item->category?->name ?? '');
        if (str_contains($categoryName, 'alcohol')
            || str_contains($categoryName, 'liquor')
            || str_contains($categoryName, 'bar')) {
            return 'alcohol';
        }

        return 'food';
    }
}
