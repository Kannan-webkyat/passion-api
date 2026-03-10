<?php

namespace App\Http\Controllers;

use App\Models\MenuCategory;
use Illuminate\Http\Request;

class MenuCategoryController extends Controller
{
    public function index()
    {
        return response()->json(MenuCategory::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'boolean'
        ]);
        
        $category = MenuCategory::create($validated);
        return response()->json($category, 201);
    }

    public function show(MenuCategory $menuCategory)
    {
        return response()->json($menuCategory);
    }

    public function update(Request $request, MenuCategory $menuCategory)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'is_active' => 'boolean'
        ]);

        $menuCategory->update($validated);
        return response()->json($menuCategory);
    }

    public function destroy(MenuCategory $menuCategory)
    {
        $menuCategory->delete();
        return response()->json(null, 204);
    }
}
