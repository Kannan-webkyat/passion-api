<?php

namespace App\Http\Controllers;

use App\Models\MenuItem;
use App\Models\RestaurantMenuItem;
use Illuminate\Http\Request;

class MenuItemController extends Controller
{
    public function index()
    {
        return response()->json(
            MenuItem::with(['category', 'subCategory', 'tax', 'restaurantMenuItems.restaurant'])
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
            'image' => 'nullable|image|max:2048',
            'restaurant_links' => 'nullable|string', // JSON string for FormData
        ]);

        $restaurantLinks = $this->parseRestaurantLinks($request->input('restaurant_links'));
        unset($validated['restaurant_links']);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('menu-items', 'public');
            $validated['image'] = url('storage/' . $path);
        }

        $item = MenuItem::create($validated);
        $this->syncRestaurantLinks($item, $restaurantLinks);

        return response()->json($item->load(['category', 'subCategory', 'tax', 'restaurantMenuItems.restaurant']), 201);
    }

    public function show(MenuItem $menuItem)
    {
        return response()->json($menuItem->load(['category', 'subCategory', 'tax', 'restaurantMenuItems.restaurant']));
    }

    public function update(Request $request, MenuItem $menuItem)
    {
        $request->merge(['tax_id' => $request->input('tax_id') ?: null]);
        $validated = $request->validate([
            'item_code' => 'sometimes|required|string|unique:menu_items,item_code,' . $menuItem->id,
            'name' => 'sometimes|required|string|max:255',
            'menu_category_id' => 'sometimes|required|exists:menu_categories,id',
            'menu_sub_category_id' => 'nullable|exists:menu_sub_categories,id',
            'price' => 'sometimes|required|numeric|min:0',
            'tax_id' => 'nullable|exists:inventory_taxes,id',
            'fixed_ept' => 'nullable|integer|min:0',
            'type' => 'nullable|string',
            'is_active' => 'boolean',
            'image' => 'nullable|image|max:2048',
            'restaurant_links' => 'nullable|string',
        ]);

        $restaurantLinks = $this->parseRestaurantLinks($request->input('restaurant_links'));
        unset($validated['restaurant_links']);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('menu-items', 'public');
            $validated['image'] = url('storage/' . $path);
        }

        $menuItem->update($validated);
        if ($restaurantLinks !== null) {
            $this->syncRestaurantLinks($menuItem, $restaurantLinks);
        }

        return response()->json($menuItem->load(['category', 'subCategory', 'tax', 'restaurantMenuItems.restaurant']));
    }

    public function destroy(MenuItem $menuItem)
    {
        $menuItem->delete();
        return response()->json(null, 204);
    }

    private function parseRestaurantLinks(?string $input): ?array
    {
        if ($input === null || $input === '') {
            return null;
        }
        $decoded = json_decode($input, true);
        return is_array($decoded) ? $decoded : null;
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
