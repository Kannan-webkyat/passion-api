<?php

namespace App\Http\Controllers;

use App\Models\InventoryLocation;
use Illuminate\Http\Request;

class InventoryLocationController extends Controller
{
    public function index()
    {
        return response()->json(InventoryLocation::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:inventory_locations,name',
            'type' => 'required|string|in:main_store,department,satellite',
            'is_active' => 'boolean'
        ]);

        $location = InventoryLocation::create($validated);
        return response()->json($location, 201);
    }

    public function show(InventoryLocation $location)
    {
        return response()->json($location->load('items'));
    }

    public function update(Request $request, InventoryLocation $location)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:inventory_locations,name,' . $location->id,
            'type' => 'required|string|in:main_store,department,satellite',
            'is_active' => 'boolean'
        ]);

        $location->update($validated);
        return response()->json($location);
    }

    public function destroy(InventoryLocation $location)
    {
        $location->delete();
        return response()->json(null, 204);
    }
}
