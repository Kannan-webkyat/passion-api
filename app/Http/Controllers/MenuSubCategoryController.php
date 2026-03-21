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
        $this->checkPermission('manage-restaurant');
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
        $this->checkPermission('manage-restaurant');
        
        try {
            $menuSubCategory->delete();

            return response()->json(null, 204);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1451 || $e->getCode() == '23000') {
                return response()->json(['message' => 'Cannot delete sub-category because it contains menu items. Please remove or reassign them first.'], 409);
            }
            throw $e;
        }
    }
}
