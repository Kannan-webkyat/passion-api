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
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    /**
     * All menu items with outlet prices, variant prices, and recipe cost per portion (for planning).
     */
    public function index()
    {
        $this->checkPermission('manage-menu');

        $items = MenuItem::with([
            'category',
            'subCategory',
            'tax',
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
     * Update outlet + variant prices only (same shapes as menu sync service).
     */
    public function update(Request $request, MenuItem $menuItem)
    {
        $this->checkPermission('manage-menu');

        $validated = $request->validate([
            'restaurant_links' => 'required|array|min:1',
            'restaurant_links.*.restaurant_master_id' => 'required|exists:restaurant_masters,id',
            'restaurant_links.*.price' => 'required|numeric|gt:0',
            'restaurant_links.*.fixed_ept' => 'nullable|integer|min:0',
            'restaurant_links.*.is_active' => 'boolean',
        ]);

        $this->menuItemSync->syncRestaurantLinks($menuItem, $validated['restaurant_links']);
        $menuItem->load('restaurantMenuItems');

        if ($request->has('variants')) {
            $request->validate([
                'variants' => 'present|array',
                'variants.*.price' => 'required|numeric|gt:0',
                'variants.*.restaurant_prices' => 'sometimes|array',
                'variants.*.restaurant_prices.*.price' => 'required|numeric|gt:0',
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
