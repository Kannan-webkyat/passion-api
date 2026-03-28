<?php

namespace App\Http\Controllers;

use App\Models\MenuItem;
use App\Services\MenuItemSyncService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MenuItemController extends Controller
{
    public function __construct(
        private MenuItemSyncService $menuItemSync
    ) {}

    public function index()
    {
        return response()->json(
            MenuItem::with([
                'category',
                'subCategory',
                'tax',
                'restaurantMenuItems.restaurant',
                'restaurantMenuItems.variantOverrides',
                'variants',
            ])
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
            'price' => 'nullable|numeric|min:0',
            'tax_id' => 'nullable|exists:inventory_taxes,id',
            'fixed_ept' => 'nullable|integer|min:0',
            'type' => 'nullable|string',
            'is_active' => 'boolean',
            'is_direct_sale' => 'nullable|boolean',
            'requires_production' => 'nullable|boolean',
            'inventory_item_id' => 'nullable|exists:inventory_items,id',
            'image' => 'nullable|image|max:2048',
            'restaurant_links' => 'nullable|string',
            'variants' => 'nullable|string',
        ]);

        $restaurantLinks = $this->parseRestaurantLinks($request->input('restaurant_links'));
        $variants = $this->parseVariants($request->input('variants'));
        unset($validated['restaurant_links'], $validated['variants']);

        if (! array_key_exists('price', $validated) || $validated['price'] === null) {
            $validated['price'] = 0;
        }

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('menu-items', 'public');
            $validated['image'] = url('storage/'.$path);
        }

        $item = MenuItem::create($validated);
        $this->menuItemSync->syncRestaurantLinks($item, $restaurantLinks);
        $item->load('restaurantMenuItems');
        $this->menuItemSync->syncVariants($item, $variants);

        return response()->json($item->load([
            'category', 'subCategory', 'tax',
            'restaurantMenuItems.restaurant',
            'restaurantMenuItems.variantOverrides',
            'variants',
        ]), 201);
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
            'price' => 'nullable|numeric|min:0',
            'tax_id' => 'nullable|exists:inventory_taxes,id',
            'fixed_ept' => 'nullable|integer|min:0',
            'type' => 'nullable|string',
            'is_active' => 'boolean',
            'is_direct_sale' => 'nullable|boolean',
            'requires_production' => 'nullable|boolean',
            'inventory_item_id' => 'nullable|exists:inventory_items,id',
            'image' => 'nullable|image|max:2048',
            'restaurant_links' => 'nullable|string',
            'variants' => 'nullable|string',
        ]);

        $restaurantLinks = $this->parseRestaurantLinks($request->input('restaurant_links'));
        $variants = $this->parseVariants($request->input('variants'));
        unset($validated['restaurant_links'], $validated['variants']);

        if (array_key_exists('price', $validated) && $validated['price'] === null) {
            $validated['price'] = 0;
        }

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('menu-items', 'public');
            $validated['image'] = url('storage/'.$path);
        }

        $menuItem->update($validated);
        if ($restaurantLinks !== null) {
            $this->menuItemSync->syncRestaurantLinks($menuItem, $restaurantLinks);
            $menuItem->load('restaurantMenuItems');
        }
        if ($variants !== null) {
            $this->menuItemSync->syncVariants($menuItem, $variants);
        }

        return response()->json($menuItem->load([
            'category', 'subCategory', 'tax',
            'restaurantMenuItems.restaurant',
            'restaurantMenuItems.variantOverrides',
            'variants',
        ]));
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
}
