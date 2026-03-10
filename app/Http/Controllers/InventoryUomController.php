<?php

namespace App\Http\Controllers;

use App\Models\InventoryUom;
use Illuminate\Http\Request;

class InventoryUomController extends Controller
{
    public function index()
    {
        return response()->json(InventoryUom::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255|unique:inventory_uoms,name',
            'short_name' => 'required|string|max:50|unique:inventory_uoms,short_name',
        ]);
        $uom = InventoryUom::create($validated);
        return response()->json($uom, 201);
    }

    public function update(Request $request, InventoryUom $uom)
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255|unique:inventory_uoms,name,' . $uom->id,
            'short_name' => 'required|string|max:50|unique:inventory_uoms,short_name,' . $uom->id,
        ]);
        $uom->update($validated);
        return response()->json($uom);
    }

    public function destroy(InventoryUom $uom)
    {
        // Check if items are using this UOM before deleting
        if ($uom->purchaseItems()->exists() || $uom->issueItems()->exists()) {
            return response()->json(['message' => 'Units of measurement in use cannot be deleted.'], 422);
        }
        $uom->delete();
        return response()->json(null, 204);
    }
}
