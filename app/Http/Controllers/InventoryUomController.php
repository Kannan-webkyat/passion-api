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
            'name' => 'required|string|max:255|unique:inventory_uoms,name',
            'short_name' => 'required|string|max:50|unique:inventory_uoms,short_name',
        ]);
        $uom = InventoryUom::create($validated);

        return response()->json($uom, 201);
    }

    public function update(Request $request, InventoryUom $uom)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:inventory_uoms,name,'.$uom->id,
            'short_name' => 'required|string|max:50|unique:inventory_uoms,short_name,'.$uom->id,
        ]);
        $uom->update($validated);

        return response()->json($uom);
    }

    public function destroy(InventoryUom $uom)
    {
        try {
            $uom->delete();

            return response()->json(null, 204);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1451 || $e->getCode() == '23000') {
                return response()->json(['message' => 'Cannot delete unit as it is referenced in inventory items, recipes or transactions.'], 409);
            }
            throw $e;
        }
    }
}
