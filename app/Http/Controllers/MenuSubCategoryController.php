<?php

namespace App\Http\Controllers;

use App\Models\MenuSubCategory;
use Illuminate\Http\Request;

class MenuSubCategoryController extends Controller
{
    public function index()
    {
        return response()->json(MenuSubCategory::with('category')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'menu_category_id' => 'required|exists:menu_categories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $subCategory = MenuSubCategory::create($validated);

        return response()->json($subCategory, 201);
    }

    public function show(MenuSubCategory $menuSubCategory)
    {
        return response()->json($menuSubCategory->load('category'));
    }

    public function update(Request $request, MenuSubCategory $menuSubCategory)
    {
        $validated = $request->validate([
            'menu_category_id' => 'sometimes|required|exists:menu_categories,id',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $menuSubCategory->update($validated);

        return response()->json($menuSubCategory);
    }

    public function destroy(MenuSubCategory $menuSubCategory)
    {
        $menuSubCategory->delete();

        return response()->json(null, 204);
    }
}
