<?php

namespace App\Http\Controllers;

use App\Models\InventoryCategory;
use Illuminate\Http\Request;

class InventoryCategoryController extends Controller
{
    public function index()
    {
        return response()->json(InventoryCategory::with('parent')->orderBy('name')->get());
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
        $this->checkPermission('manage-inventory');
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:inventory_categories,name',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:inventory_categories,id',
        ]);
        $cat = InventoryCategory::create($validated);

        return response()->json($cat, 201);
    }

    public function show(InventoryCategory $category)
    {
        return response()->json($category->load('items'));
    }

    public function update(Request $request, InventoryCategory $category)
    {
        $this->checkPermission('manage-inventory');
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:inventory_categories,name,'.$category->id,
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:inventory_categories,id',
        ]);
        $category->update($validated);

        return response()->json($category);
    }

    public function destroy(InventoryCategory $category)
    {
        $this->checkPermission('manage-inventory');
        try {
            $category->delete();

            return response()->json(null, 204);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1451 || $e->getCode() == '23000') {
                return response()->json(['message' => 'Cannot delete inventory category because it contains items that have existing transactions or recipes.'], 409);
            }
            throw $e;
        }
    }
}
