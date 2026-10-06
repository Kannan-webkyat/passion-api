<?php

namespace App\Http\Controllers;

use App\Models\MenuItem;
use App\Services\MenuItemSyncService;
use Illuminate\Http\Request;

class MenuPricingController extends Controller
{
    public function __construct(
        private MenuItemSyncService $menuItemSync
    ) {}

    private function checkPermission(string $permission)
    {
        $user = auth()->user();
        if (
            $user
            && ! $user->hasRole('Admin')
            && ! $user->can($permission)
            && ! ($permission === 'menu-pricing' && $user->can('manage-menu'))
        ) {
            abort(403, 'Unauthorized action.');
        }
    }

    /**
     * All menu items with outlet prices, variant prices, and recipe cost per portion (for planning).
     */
    public function index()
    {
        $this->checkPermission('menu-pricing');

        $items = MenuItem::with([
            'category',
            'subCategory',
            'tax',
            'inventoryItem:id,is_minibar',
            'restaurantMenuItems.restaurant',
            'restaurantMenuItems.variantOverrides',
            'variants',
            'recipe.ingredients.inventoryItem',
        ])
            ->orderBy('name')
            ->get();

        $payload = $items->map(function (MenuItem $item) {
            $recipe = $item->recipe;
            $costPerPortion = null;
            $foodCostPct = null;
            if ($recipe) {
                $recipe->loadMissing('ingredients.inventoryItem');
                $costPerPortion = $recipe->cost_per_portion;
                $sellRef = (float) ($item->price ?? 0);
                if ($sellRef <= 0 && $item->restaurantMenuItems->isNotEmpty()) {
                    $sellRef = (float) ($item->restaurantMenuItems->first()->price ?? 0);
                }
                if ($costPerPortion > 0 && $sellRef > 0) {
                    $foodCostPct = round(((float) $costPerPortion / $sellRef) * 100, 1);
                }
            }

            return [
                'id' => $item->id,
                'item_code' => $item->item_code,
                'name' => $item->name,
                'type' => $item->type,
                'is_active' => (bool) $item->is_active,
                'is_direct_sale' => (bool) $item->is_direct_sale,
                'is_minibar' => (bool) ($item->inventoryItem?->is_minibar),
                'menu_category_id' => $item->menu_category_id,
                'category' => $item->category,
                'base_price' => (float) ($item->price ?? 0),
                'cost_per_portion' => $costPerPortion !== null ? round((float) $costPerPortion, 2) : null,
                'food_cost_pct_vs_base' => $foodCostPct,
                'restaurant_menu_items' => $item->restaurantMenuItems,
                'variants' => $item->variants,
            ];
        });

        return response()->json($payload);
    }

    /**
     * Update outlet prices, variant prices, and the minibar guest price (menu_items.price).
     */
    public function update(Request $request, MenuItem $menuItem)
    {
        $this->checkPermission('menu-pricing');

        $validated = $request->validate([
            'restaurant_links' => 'present|array',
            'restaurant_links.*.restaurant_master_id' => 'required|exists:restaurant_masters,id',
            // 0 = not priced at this outlet yet (POS skips until set).
            'restaurant_links.*.price' => 'nullable|numeric|min:0',
            'restaurant_links.*.fixed_ept' => 'nullable|integer|min:0',
            'restaurant_links.*.is_active' => 'boolean',
            // Guest minibar price (menu_items.price). Ignored unless the linked inventory item is minibar.
            'price' => 'nullable|numeric|min:0',
        ]);

        $menuItem->loadMissing('inventoryItem:id,is_minibar');
        $isMinibar = (bool) ($menuItem->inventoryItem?->is_minibar);
        $links = $validated['restaurant_links'];

        if ($links === [] && ! $isMinibar) {
            return response()->json([
                'message' => 'Add at least one linked outlet under Menu Configuration.',
            ], 422);
        }

        if ($isMinibar && array_key_exists('price', $validated) && $validated['price'] !== null) {
            $nextPrice = (float) $validated['price'];
            if ($links === [] && $nextPrice <= 0) {
                return response()->json([
                    'message' => 'Enter a minibar selling price greater than zero.',
                ], 422);
            }
            $menuItem->price = $nextPrice;
            $menuItem->save();
        } elseif ($isMinibar && $links === [] && (float) $menuItem->price <= 0) {
            return response()->json([
                'message' => 'Enter a minibar selling price greater than zero.',
            ], 422);
        }

        $this->menuItemSync->syncRestaurantLinks($menuItem, $links);
        $menuItem->load('restaurantMenuItems');

        if ($request->has('variants')) {
            $request->validate([
                'variants' => 'present|array',
                'variants.*.price' => 'nullable|numeric|min:0',
                'variants.*.restaurant_prices' => 'required|array|min:1',
                'variants.*.restaurant_prices.*.restaurant_master_id' => 'required|exists:restaurant_masters,id',
                'variants.*.restaurant_prices.*.price' => 'nullable|numeric|min:0',
            ]);
            $this->menuItemSync->syncVariants($menuItem, $request->input('variants'));
        }

        return response()->json($menuItem->load([
            'category', 'subCategory', 'tax',
            'restaurantMenuItems.restaurant',
            'restaurantMenuItems.variantOverrides',
            'variants',
        ]));
    }
}
