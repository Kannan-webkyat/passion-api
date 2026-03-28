<?php

namespace App\Http\Controllers;

use App\Events\PosRestaurantUpdated;
use App\Models\RestaurantTable;
use Illuminate\Http\Request;

class TableController extends Controller
{
    private function checkPermission(string $permission)
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->hasRole('Super Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index()
    {
        $this->checkPermission('manage-tables');
        return response()->json(
            RestaurantTable::with(['category', 'restaurantMaster'])->get()
        );
    }

    public function store(Request $request)
    {
        $this->checkPermission('manage-tables');
        $validated = $request->validate([
            'table_number' => 'required|string|max:255',
            'restaurant_master_id' => 'required|exists:restaurant_masters,id',
            'category_id' => 'required|exists:table_categories,id',
            'capacity' => 'required|integer|min:1',
            'status' => 'in:available,occupied,reserved,cleaning,inactive',
            'location' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $table = RestaurantTable::create($validated);

        return response()->json($table->load(['category', 'restaurantMaster']), 201);
    }

    public function show(RestaurantTable $table)
    {
        $this->checkPermission('manage-tables');
        return response()->json($table->load(['category', 'restaurantMaster']));
    }

    public function update(Request $request, RestaurantTable $table)
    {
        $this->checkPermission('manage-tables');
        $oldStatus = $table->status;
        $validated = $request->validate([
            'table_number' => 'sometimes|required|string|max:255',
            'restaurant_master_id' => 'sometimes|required|exists:restaurant_masters,id',
            'category_id' => 'sometimes|required|exists:table_categories,id',
            'capacity' => 'sometimes|required|integer|min:1',
            'status' => 'in:available,occupied,reserved,cleaning,inactive',
            'location' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $table->update($validated);

        if (isset($validated['status']) && $validated['status'] !== $oldStatus && $table->restaurant_master_id) {
            if (config('broadcasting.default') !== 'null') {
                event(new PosRestaurantUpdated((int) $table->restaurant_master_id));
            }
        }

        return response()->json($table->load(['category', 'restaurantMaster']));
    }

    public function destroy(RestaurantTable $table)
    {
        $this->checkPermission('manage-tables');
        try {
            $table->delete();

            return response()->json(null, 204);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1451 || $e->getCode() == '23000') {
                return response()->json(['message' => 'Cannot delete this table because it has a billing history in the POS. Please mark it as Inactive instead.'], 409);
            }
            throw $e;
        }
    }
}
