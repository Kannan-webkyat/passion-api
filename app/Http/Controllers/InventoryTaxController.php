<?php

namespace App\Http\Controllers;

use App\Models\InventoryTax;
use Illuminate\Http\Request;

class InventoryTaxController extends Controller
{
    public function index()
    {
        return response()->json(InventoryTax::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'rate' => 'required|numeric|min:0',
            'type' => 'required|string|in:local,inter-state,vat',
        ]);

        $tax = InventoryTax::create($validated);
        return response()->json($tax, 201);
    }

    public function update(Request $request, InventoryTax $tax)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'rate' => 'required|numeric|min:0',
            'type' => 'required|string|in:local,inter-state,vat',
        ]);

        $tax->update($validated);
        return response()->json($tax);
    }

    public function destroy(InventoryTax $tax)
    {
        if ($tax->items()->count() > 0) {
            return response()->json(['message' => 'Cannot delete tax rate that is currently assigned to items.'], 422);
        }
        $tax->delete();
        return response()->json(null, 204);
    }
}
