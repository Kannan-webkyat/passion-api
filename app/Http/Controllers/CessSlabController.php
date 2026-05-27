<?php

namespace App\Http\Controllers;

use App\Models\CessSlab;
use Illuminate\Http\Request;

class CessSlabController extends Controller
{
    private function checkPermission(): void
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can('manage-settings')) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index()
    {
        $this->checkPermission();

        return response()->json(CessSlab::orderBy('item_category')->orderBy('min_mrp')->get());
    }

    public function store(Request $request)
    {
        $this->checkPermission();
        $validated = $request->validate([
            'item_category' => 'required|string|max:32',
            'min_mrp' => 'required|numeric|min:0',
            'max_mrp' => 'required|numeric|gte:min_mrp',
            'flat_cess_amount' => 'required|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $slab = CessSlab::create([
            ...$validated,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return response()->json($slab, 201);
    }

    public function update(Request $request, CessSlab $cessSlab)
    {
        $this->checkPermission();
        $validated = $request->validate([
            'item_category' => 'sometimes|string|max:32',
            'min_mrp' => 'sometimes|numeric|min:0',
            'max_mrp' => 'sometimes|numeric|min:0',
            'flat_cess_amount' => 'sometimes|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if (isset($validated['min_mrp'], $validated['max_mrp']) && $validated['max_mrp'] < $validated['min_mrp']) {
            return response()->json(['message' => 'max_mrp must be >= min_mrp'], 422);
        }

        $cessSlab->update($validated);

        return response()->json($cessSlab->fresh());
    }

    public function destroy(CessSlab $cessSlab)
    {
        $this->checkPermission();
        $cessSlab->delete();

        return response()->json(null, 204);
    }
}
