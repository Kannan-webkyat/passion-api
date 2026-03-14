<?php

namespace App\Http\Controllers;

use App\Models\MenuItem;
use Illuminate\Http\Request;

class MenuItemController extends Controller
{
    public function index()
    {
        return response()->json(
            MenuItem::with(['category', 'subCategory'])->orderBy('name')->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'item_code' => 'required|string|unique:menu_items',
            'name' => 'required|string|max:255',
            'menu_category_id' => 'required|exists:menu_categories,id',
            'menu_sub_category_id' => 'nullable|exists:menu_sub_categories,id',
            'price' => 'required|numeric|min:0',
            'fixed_ept' => 'nullable|integer|min:0',
            'type' => 'nullable|string',
            'is_active' => 'boolean',
            'image' => 'nullable|image|max:2048'
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('menu-items', 'public');
            $validated['image'] = url('storage/' . $path);
        }

        $item = MenuItem::create($validated);
        return response()->json($item, 201);
    }

    public function show(MenuItem $menuItem)
    {
        return response()->json($menuItem->load(['category', 'subCategory']));
    }

    public function update(Request $request, MenuItem $menuItem)
    {
        $validated = $request->validate([
            'item_code' => 'sometimes|required|string|unique:menu_items,item_code,' . $menuItem->id,
            'name' => 'sometimes|required|string|max:255',
            'menu_category_id' => 'sometimes|required|exists:menu_categories,id',
            'menu_sub_category_id' => 'nullable|exists:menu_sub_categories,id',
            'price' => 'sometimes|required|numeric|min:0',
            'fixed_ept' => 'nullable|integer|min:0',
            'type' => 'nullable|string',
            'is_active' => 'boolean',
            'image' => 'nullable|image|max:2048'
        ]);

        if ($request->hasFile('image')) {
            // Optional: delete old image
            $path = $request->file('image')->store('menu-items', 'public');
            $validated['image'] = url('storage/' . $path);
        }

        $menuItem->update($validated);
        return response()->json($menuItem);
    }

    public function destroy(MenuItem $menuItem)
    {
        $menuItem->delete();
        return response()->json(null, 204);
    }
}
