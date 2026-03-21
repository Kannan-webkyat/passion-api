<?php

namespace App\Http\Controllers;

use App\Models\MenuItem;
use App\Models\MenuItemVariant;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantMenuItemVariant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
    
    private function checkPermission(string $permission)
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function store(Request $request)
    {
        $this->checkPermission('manage-restaurant');
        $request->merge(['tax_id' => $request->input('tax_id') ?: null]);
        $validated = $request->validate([
            'item_code' => 'required|string|unique:menu_items',
            'name' => 'required|string|max:255',
            'menu_category_id' => 'required|exists:menu_categories,id',
            'menu_sub_category_id' => [
                'nullable',
                Rule::exists('menu_sub_categories', 'id')->where(function ($query) use ($request) {
                    $query->where('menu_category_id', $request->input('menu_category_id'));
                }),
            ],
            'price' => 'required|numeric|min:0',
            'tax_id' => 'nullable|exists:inventory_taxes,id',
            'fixed_ept' => 'nullable|integer|min:0',
            'type' => 'nullable|string',
            'is_active' => 'boolean',
            'is_direct_sale' => 'nullable|boolean',
            'inventory_item_id' => 'required_if:is_direct_sale,true|nullable|exists:inventory_items,id',
            'image' => 'nullable|image|max:2048',
            'restaurant_links' => 'nullable|string', // JSON string for FormData
            'variants' => 'nullable|string', // JSON: [{id?, size_label, price, ml_quantity?}]
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
        $item->load('restaurantMenuItems'); // Ensure RMI exists before variants
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
        $this->checkPermission('manage-restaurant');
        $request->merge(['tax_id' => $request->input('tax_id') ?: null]);
        $validated = $request->validate([
            'item_code' => 'sometimes|required|string|unique:menu_items,item_code,'.$menuItem->id,
            'name' => 'sometimes|required|string|max:255',
            'menu_category_id' => 'sometimes|required|exists:menu_categories,id',
            'menu_sub_category_id' => [
                'nullable',
                Rule::exists('menu_sub_categories', 'id')->where(function ($query) use ($request, $menuItem) {
                    $catId = $request->input('menu_category_id') ?: $menuItem->menu_category_id;
                    $query->where('menu_category_id', $catId);
                }),
            ],
            'price' => 'sometimes|required|numeric|min:0',
            'tax_id' => 'nullable|exists:inventory_taxes,id',
            'fixed_ept' => 'nullable|integer|min:0',
            'type' => 'nullable|string',
            'is_active' => 'boolean',
            'is_direct_sale' => 'nullable|boolean',
            'inventory_item_id' => 'required_if:is_direct_sale,true|nullable|exists:inventory_items,id',
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
        $this->checkPermission('manage-restaurant');
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

        $incoming = collect($variants)->filter(function ($row) {
            return is_array($row) && trim($row['size_label'] ?? '') !== '';
        });
        $incomingIds = $incoming->map(fn ($row) => is_array($row) ? ($row['id'] ?? null) : null)->filter()->values()->toArray();

        // 1. Delete variants that were removed
        $menuItem->variants()->whereNotIn('id', $incomingIds)->delete();

        foreach ($incoming as $i => $row) {
            if (!is_array($row)) continue;

            $basePrice = (float) ($row['price'] ?? 0);
            
            // 2. Update existing or create new variant (preserve ID)
            $v = MenuItemVariant::updateOrCreate(
                ['id' => $row['id'] ?? null, 'menu_item_id' => $menuItem->id],
                [
                    'size_label' => $row['size_label'],
                    'price' => $basePrice,
                    'ml_quantity' => isset($row['ml_quantity']) && $row['ml_quantity'] !== '' ? (float) $row['ml_quantity'] : null,
                    'sort_order' => $i,
                ]
            );

            // 3. Sync restaurant-specific prices for this variant
            foreach ($menuItem->restaurantMenuItems as $rmi) {
                $price = $basePrice;
                foreach ($row['restaurant_prices'] ?? [] as $rp) {
                    if (is_array($rp) && (int) ($rp['restaurant_master_id'] ?? 0) === (int) $rmi->restaurant_master_id) {
                        $price = (float) ($rp['price'] ?? $basePrice);
                        break;
                    }
                }

                RestaurantMenuItemVariant::updateOrCreate(
                    [
                        'restaurant_menu_item_id' => $rmi->id,
                        'menu_item_variant_id' => $v->id,
                    ],
                    ['price' => $price]
                );
            }
        }
    }

    private function syncRestaurantLinks(MenuItem $menuItem, ?array $links): void
    {
        if ($links === null) {
            return;
        }

        $incoming = collect($links)->filter(fn ($row) => is_array($row) && (int) ($row['restaurant_master_id'] ?? 0) > 0);
        $restaurantIds = $incoming->map(fn ($row) => is_array($row) ? ($row['restaurant_master_id'] ?? null) : null)->filter()->values()->toArray();

        // 1. Delete links that were removed (cascade will handle variants)
        $menuItem->restaurantMenuItems()->whereNotIn('restaurant_master_id', $restaurantIds)->delete();

        foreach ($incoming as $row) {
            if (!is_array($row)) continue;

            // 2. Update existing or create new restaurant link (preserve pivot ID)
            RestaurantMenuItem::updateOrCreate(
                [
                    'menu_item_id' => $menuItem->id,
                    'restaurant_master_id' => $row['restaurant_master_id'],
                ],
                [
                    'price' => (float) ($row['price'] ?? 0),
                    'fixed_ept' => isset($row['fixed_ept']) ? (int) $row['fixed_ept'] : null,
                    'is_active' => (bool) ($row['is_active'] ?? true),
                ]
            );
        }
    }
}
