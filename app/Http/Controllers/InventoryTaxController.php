<?php

namespace App\Http\Controllers;

use App\Models\InventoryTax;
use Illuminate\Http\Request;

class InventoryTaxController extends Controller
{
    private function checkPermission(string $permission): void
    {
        $user = auth()->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }
        if ($user->hasRole('Admin') || $user->hasRole('Super Admin')) {
            return;
        }
        if (! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index()
    {
        return response()->json(InventoryTax::all());
    }

    public function store(Request $request)
    {
        $this->checkPermission('manage-settings');
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
        $this->checkPermission('manage-settings');
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
        $this->checkPermission('manage-settings');
        try {
            $tax->delete();

            return response()->json(null, 204);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1451 || $e->getCode() == '23000') {
                return response()->json(['message' => 'Cannot delete tax rule as it is assigned to active items or historical orders.'], 409);
            }
            throw $e;
        }
    }
}
