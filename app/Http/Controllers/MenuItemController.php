<?php

namespace App\Http\Controllers;

use App\Models\MenuItem;
use App\Models\MenuItemVariant;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantMenuItemVariant;
use Illuminate\Http\Request;

class MenuItemController extends Controller
{
    public function index()
    {
        return response()->json(
            MenuItem::with(['category', 'subCategory', 'tax', 'restaurantMenuItems.restaurant', 'variants'])
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $request->merge(['tax_id' => $request->input('tax_id') ?: null]);
        $validated = $request->validate([
            'item_code' => 'required|string|unique:menu_items',
            'name' => 'required|string|max:255',
            'menu_category_id' => 'required|exists:menu_categories,id',
            'menu_sub_category_id' => 'nullable|exists:menu_sub_categories,id',
            'price' => 'required|numeric|min:0',
            'tax_id' => 'nullable|exists:inventory_taxes,id',
            'fixed_ept' => 'nullable|integer|min:0',
            'type' => 'nullable|string',
            'is_active' => 'boolean',
            'is_direct_sale' => 'nullable|boolean',
            'inventory_item_id' => 'nullable|exists:inventory_items,id',
            'image' => 'nullable|image|max:2048',
            'restaurant_links' => 'nullable|string', // JSON string for FormData
            'variants' => 'nullable|string', // JSON: [{size_label, price, ml_quantity?}]
        ]);

        $restaurantLinks = $this->parseRestaurantLinks($request->input('restaurant_links'));
        $variants = $this->parseVariants($request->input('variants'));
        unset($validated['restaurant_links'], $validated['variants']);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('menu-items', 'public');
            $validated['image'] = url('storage/'.$path);
        }

        $item = MenuItem::create($validated);
        $this->syncRestaurantLinks($item, $restaurantLinks);
        $item->load('restaurantMenuItems');
        $this->syncVariants($item, $variants);

        return response()->json($item->load(['category', 'subCategory', 'tax', 'restaurantMenuItems.restaurant', 'variants']), 201);
    }

    public function show(MenuItem $menuItem)
    {
        return response()->json($menuItem->load([
            'category', 'subCategory', 'tax',
            'restaurantMenuItems.restaurant',
            'restaurantMenuItems.variantOverrides',
            'variants',
        ]));
    }

    public function update(Request $request, MenuItem $menuItem)
    {
        $request->merge(['tax_id' => $request->input('tax_id') ?: null]);
        $validated = $request->validate([
            'item_code' => 'sometimes|required|string|unique:menu_items,item_code,'.$menuItem->id,
            'name' => 'sometimes|required|string|max:255',
            'menu_category_id' => 'sometimes|required|exists:menu_categories,id',
            'menu_sub_category_id' => 'nullable|exists:menu_sub_categories,id',
            'price' => 'sometimes|required|numeric|min:0',
            'tax_id' => 'nullable|exists:inventory_taxes,id',
            'fixed_ept' => 'nullable|integer|min:0',
            'type' => 'nullable|string',
            'is_active' => 'boolean',
            'is_direct_sale' => 'nullable|boolean',
            'inventory_item_id' => 'nullable|exists:inventory_items,id',
            'image' => 'nullable|image|max:2048',
            'restaurant_links' => 'nullable|string',
            'variants' => 'nullable|string',
        ]);

        $restaurantLinks = $this->parseRestaurantLinks($request->input('restaurant_links'));
        $variants = $this->parseVariants($request->input('variants'));
        unset($validated['restaurant_links'], $validated['variants']);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('menu-items', 'public');
            $validated['image'] = url('storage/'.$path);
        }

        $menuItem->update($validated);
        if ($restaurantLinks !== null) {
            $this->syncRestaurantLinks($menuItem, $restaurantLinks);
            $menuItem->load('restaurantMenuItems');
        }
        if ($variants !== null) {
            $this->syncVariants($menuItem, $variants);
        }

        return response()->json($menuItem->load(['category', 'subCategory', 'tax', 'restaurantMenuItems.restaurant', 'variants']));
    }

    public function destroy(MenuItem $menuItem)
    {
        try {
            $menuItem->delete();

            return response()->json(null, 204);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1451 || $e->getCode() == '23000') {
                return response()->json(['message' => 'Cannot delete menu item because it is referenced in existing orders or recipes. Please disable it instead.'], 409);
            }
            throw $e;
        }
    }

    private function parseRestaurantLinks(?string $input): ?array
    {
        if ($input === null || $input === '') {
            return null;
        }
        $decoded = json_decode($input, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function parseVariants(?string $input): ?array
    {
        if ($input === null || $input === '') {
            return null;
        }
        $decoded = json_decode($input, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function syncVariants(MenuItem $menuItem, ?array $variants): void
    {
        if ($variants === null) {
            return;
        }

        $validated = collect($variants)->map(fn ($row, $i) => [
            'size_label' => trim($row['size_label'] ?? ''),
            'price' => (float) ($row['price'] ?? 0),
            'ml_quantity' => isset($row['ml_quantity']) && $row['ml_quantity'] !== '' ? (float) $row['ml_quantity'] : null,
            'sort_order' => $i,
            'restaurant_prices' => $row['restaurant_prices'] ?? [],
        ])->filter(fn ($row) => $row['size_label'] !== '')->values();

        $menuItem->variants()->delete();

        foreach ($validated as $i => $row) {
            $basePrice = (float) ($row['price'] ?? 0);
            $v = MenuItemVariant::create([
                'menu_item_id' => $menuItem->id,
                'size_label' => $row['size_label'],
                'price' => $basePrice,
                'ml_quantity' => $row['ml_quantity'],
                'sort_order' => $i,
            ]);

            foreach ($menuItem->restaurantMenuItems as $rmi) {
                $price = $basePrice;
                foreach ($row['restaurant_prices'] as $rp) {
                    if ((int) ($rp['restaurant_master_id'] ?? 0) === (int) $rmi->restaurant_master_id) {
                        $price = (float) ($rp['price'] ?? $basePrice);
                        break;
                    }
                }
                RestaurantMenuItemVariant::create([
                    'restaurant_menu_item_id' => $rmi->id,
                    'menu_item_variant_id' => $v->id,
                    'price' => $price,
                ]);
            }
        }
    }

    private function syncRestaurantLinks(MenuItem $menuItem, ?array $links): void
    {
        if ($links === null) {
            return;
        }

        $validated = collect($links)->map(fn ($row) => [
            'restaurant_master_id' => (int) ($row['restaurant_master_id'] ?? 0),
            'price' => (float) ($row['price'] ?? 0),
            'fixed_ept' => isset($row['fixed_ept']) ? (int) $row['fixed_ept'] : null,
            'is_active' => (bool) ($row['is_active'] ?? true),
        ])->filter(fn ($row) => $row['restaurant_master_id'] > 0)->values();

        $menuItem->restaurantMenuItems()->delete();

        foreach ($validated as $row) {
            RestaurantMenuItem::create([
                'menu_item_id' => $menuItem->id,
                'restaurant_master_id' => $row['restaurant_master_id'],
                'price' => $row['price'],
                'fixed_ept' => $row['fixed_ept'],
                'is_active' => $row['is_active'],
            ]);
        }
    }
}
