<?php

namespace App\Http\Controllers;

use App\Models\TableCategory;
use Illuminate\Http\Request;

class TableCategoryController extends Controller
{
    public function index()
    {
        return response()->json(TableCategory::all());
    }

    public function store(Request $request)
    {
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
        $tableCategory->delete();

        return response()->json(null, 204);
    }
}
