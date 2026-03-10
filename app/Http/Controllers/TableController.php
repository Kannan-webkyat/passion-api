<?php

namespace App\Http\Controllers;

use App\Models\RestaurantTable;
use Illuminate\Http\Request;

class TableController extends Controller
{
    public function index()
    {
        return response()->json(RestaurantTable::with('category')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'table_number' => 'required|string|max:255|unique:restaurant_tables,table_number',
            'category_id' => 'required|exists:table_categories,id',
            'capacity' => 'required|integer|min:1',
            'status' => 'nullable|in:available,occupied,reserved,cleaning,inactive',
            'location' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $table = RestaurantTable::create($validated);
        return response()->json($table->load('category'), 201);
    }

    public function show(RestaurantTable $table)
    {
        return response()->json($table->load('category'));
    }

    public function update(Request $request, RestaurantTable $table)
    {
        $validated = $request->validate([
            'table_number' => 'required|string|max:255|unique:restaurant_tables,table_number,' . $table->id,
            'category_id' => 'required|exists:table_categories,id',
            'capacity' => 'required|integer|min:1',
            'status' => 'nullable|in:available,occupied,reserved,cleaning,inactive',
            'location' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $table->update($validated);
        return response()->json($table->load('category'));
    }

    public function destroy(RestaurantTable $table)
    {
        $table->delete();
        return response()->json(null, 204);
    }

    public function changeStatus(Request $request, RestaurantTable $table)
    {
        $validated = $request->validate([
            'status' => 'required|in:available,occupied,reserved,cleaning,inactive',
        ]);

        $table->update(['status' => $validated['status']]);
        
        return response()->json([
            'message' => 'Table status updated successfully',
            'table' => $table->load('category')
        ]);
    }
}
