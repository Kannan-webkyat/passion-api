<?php

namespace App\Http\Controllers;

use App\Models\TableCategory;
use Illuminate\Http\Request;

class TableCategoryController extends Controller
{
    private function checkPermission(string $permission)
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index()
    {
        return response()->json(TableCategory::all());
    }

    public function store(Request $request)
    {
        $this->checkPermission('manage-tables');
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'capacity' => 'required|integer|min:1',
            'description' => 'nullable|string',
        ]);

        $category = TableCategory::create($validated);

        return response()->json($category, 201);
    }

    public function show(TableCategory $tableCategory)
    {
        return response()->json($tableCategory);
    }

    public function update(Request $request, TableCategory $tableCategory)
    {
        $this->checkPermission('manage-tables');
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'capacity' => 'sometimes|required|integer|min:1',
            'description' => 'nullable|string',
        ]);

        $tableCategory->update($validated);

        return response()->json($tableCategory);
    }

    public function destroy(TableCategory $tableCategory)
    {
        $this->checkPermission('manage-tables');
        $tableCategory->delete();

        return response()->json(null, 204);
    }
}
