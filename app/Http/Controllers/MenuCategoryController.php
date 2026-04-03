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

    private function checkPermission(string $permission)
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function store(Request $request)
    {
        $this->checkPermission('manage-menu');
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'boolean',
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
        $this->checkPermission('manage-menu');
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'is_active' => 'boolean',
        ]);

        $menuCategory->update($validated);

        return response()->json($menuCategory);
    }

    public function destroy(MenuCategory $menuCategory)
    {
        $this->checkPermission('manage-menu');
        try {
            $menuCategory->delete();

            return response()->json(null, 204);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1451 || $e->getCode() == '23000') {
                return response()->json(['message' => 'Cannot delete menu category because it contains items that are referenced in existing orders or recipes. Please disable it instead.'], 409);
            }
            throw $e;
        }
    }
}
