<?php

namespace App\Http\Controllers;

use App\Models\TableCategorie;
use Illuminate\Http\Request;

class TableCategorieController extends Controller
{
    public function index()
    {
        return response()->json(TableCategorie::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'capacity' => 'required|integer|min:1',
            'description' => 'nullable|string',
        ]);

        $category = TableCategorie::create($validated);
        return response()->json($category, 201);
    }

    public function show(TableCategorie $tableCategory)
    {
        return response()->json($tableCategory->load('tables'));
    }

    public function update(Request $request, TableCategorie $tableCategory)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'capacity' => 'required|integer|min:1',
            'description' => 'nullable|string',
        ]);

        $tableCategory->update($validated);
        return response()->json($tableCategory);
    }

    public function destroy(TableCategorie $tableCategory)
    {
        $tableCategory->delete();
        return response()->json(null, 204);
    }
}
