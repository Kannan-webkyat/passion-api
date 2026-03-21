<?php

namespace App\Http\Controllers;

use App\Models\InventoryLocation;
use Illuminate\Http\Request;

class InventoryLocationController extends Controller
{
    public function index(Request $request)
    {
        $query = InventoryLocation::with('department');
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:inventory_locations,name',
            'type' => 'required|string|in:main_store,kitchen_store,sub_store,satellite',
            'department_id' => 'nullable|exists:departments,id',
            'is_active' => 'boolean',
        ]);

        $location = InventoryLocation::create($validated);

        return response()->json($location->load('department'), 201);
    }

    public function show(InventoryLocation $location)
    {
        return response()->json($location->load(['items', 'department']));
    }

    public function update(Request $request, InventoryLocation $location)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:inventory_locations,name,'.$location->id,
            'type' => 'required|string|in:main_store,kitchen_store,sub_store,satellite',
            'department_id' => 'nullable|exists:departments,id',
            'is_active' => 'boolean',
        ]);

        if ($location->type === 'main_store' && isset($validated['is_active']) && $validated['is_active'] == false) {
            return response()->json(['message' => 'The Main Store cannot be blocked.'], 422);
        }

        $location->update($validated);

        return response()->json($location->load('department'));
    }

    public function destroy(InventoryLocation $location)
    {
        if ($location->type === 'main_store') {
            return response()->json(['message' => 'The Main Store cannot be deleted.'], 422);
        }
        try {
            $location->delete();

            return response()->json(null, 204);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1451 || $e->getCode() == '23000') {
                return response()->json(['message' => 'Cannot delete location as it has existing stock or historical transactions.'], 409);
            }
            throw $e;
        }
    }
}
