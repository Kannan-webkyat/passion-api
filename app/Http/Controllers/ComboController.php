<?php

namespace App\Http\Controllers;

use App\Models\Combo;
use Illuminate\Http\Request;

class ComboController extends Controller
{
    public function index()
    {
        return response()->json(Combo::with(['menuItems', 'restaurantMaster'])->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'restaurant_master_id' => 'required|exists:restaurant_masters,id',
            'price' => 'required|numeric',
            'fixed_ept' => 'nullable|integer',
            'is_active' => 'boolean',
            'menu_item_ids' => 'required|array',
            'menu_item_ids.*' => 'exists:menu_items,id'
        ]);

        $combo = Combo::create($validated);
        $combo->menuItems()->sync($request->menu_item_ids);

        return response()->json($combo->load(['menuItems', 'restaurantMaster']), 201);
    }

    public function show(Combo $menuCombo)
    {
        return response()->json($menuCombo->load(['menuItems', 'restaurantMaster']));
    }

    public function update(Request $request, Combo $menuCombo)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'restaurant_master_id' => 'sometimes|required|exists:restaurant_masters,id',
            'price' => 'sometimes|required|numeric',
            'fixed_ept' => 'nullable|integer',
            'is_active' => 'boolean',
            'menu_item_ids' => 'sometimes|required|array',
            'menu_item_ids.*' => 'exists:menu_items,id'
        ]);

        $menuCombo->update($validated);
        
        if ($request->has('menu_item_ids')) {
            $menuCombo->menuItems()->sync($request->menu_item_ids);
        }

        return response()->json($menuCombo->load(['menuItems', 'restaurantMaster']));
    }

    public function destroy(Combo $menuCombo)
    {
        $menuCombo->delete();
        return response()->json(null, 204);
    }
}
