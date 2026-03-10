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

    public function show(TableCategorie $tableCategorie)
    {
        return response()->json($tableCategorie->load('tables'));
    }

    public function update(Request $request, TableCategorie $tableCategorie)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'capacity' => 'required|integer|min:1',
            'description' => 'nullable|string',
        ]);

        $tableCategorie->update($validated);
        return response()->json($tableCategorie);
    }

    public function destroy(TableCategorie $tableCategorie)
    {
        $tableCategorie->delete();
        return response()->json(null, 204);
    }
}
