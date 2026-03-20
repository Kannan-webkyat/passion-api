<?php

namespace App\Http\Controllers;

use App\Models\Combo;
use App\Models\RestaurantCombo;
use App\Models\RestaurantMaster;
use Illuminate\Http\Request;

class ComboController extends Controller
{
    public function index()
    {
        return response()->json(Combo::with(['menuItems', 'restaurantCombos'])->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric',
            'fixed_ept' => 'nullable|integer',
            'is_active' => 'boolean',
            'menu_item_ids' => 'required|array',
            'menu_item_ids.*' => 'exists:menu_items,id',
            'restaurant_prices' => 'nullable|array',
            'restaurant_prices.*.restaurant_id' => 'required|exists:restaurant_masters,id',
            'restaurant_prices.*.price' => 'required|numeric|min:0',
        ]);

        $combo = Combo::create($validated);
        $combo->menuItems()->sync($request->menu_item_ids);

        if (!empty($validated['restaurant_prices'] ?? [])) {
            $this->syncRestaurantPrices($combo, $validated['restaurant_prices']);
        }

        return response()->json($combo->load(['menuItems', 'restaurantCombos']), 201);
    }

    public function show(Combo $menuCombo)
    {
        return response()->json($menuCombo->load(['menuItems', 'restaurantCombos.restaurant']));
    }

    public function update(Request $request, Combo $menuCombo)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'price' => 'sometimes|required|numeric',
            'fixed_ept' => 'nullable|integer',
            'is_active' => 'boolean',
            'menu_item_ids' => 'sometimes|required|array',
            'menu_item_ids.*' => 'exists:menu_items,id',
            'restaurant_prices' => 'nullable|array',
            'restaurant_prices.*.restaurant_id' => 'required|exists:restaurant_masters,id',
            'restaurant_prices.*.price' => 'required|numeric|min:0',
        ]);

        $menuCombo->update($validated);

        if ($request->has('menu_item_ids')) {
            $menuCombo->menuItems()->sync($request->menu_item_ids);
        }

        if (array_key_exists('restaurant_prices', $validated)) {
            $this->syncRestaurantPrices($menuCombo, $validated['restaurant_prices']);
        }

        return response()->json($menuCombo->load(['menuItems', 'restaurantCombos']));
    }

    public function destroy(Combo $menuCombo)
    {
        $menuCombo->delete();
        return response()->json(null, 204);
    }

    private function syncRestaurantPrices(Combo $combo, array $restaurantPrices): void
    {
        $byRestaurant = collect($restaurantPrices)->keyBy('restaurant_id');
        $restaurantIds = $byRestaurant->keys()->filter(fn ($id) => $id > 0)->values();
        $combo->restaurantCombos()->whereNotIn('restaurant_master_id', $restaurantIds)->delete();

        foreach ($byRestaurant as $restaurantId => $rp) {
            if ($restaurantId <= 0) continue;
            RestaurantCombo::updateOrCreate(
                [
                    'combo_id' => $combo->id,
                    'restaurant_master_id' => $restaurantId,
                ],
                [
                    'price' => $rp['price'],
                    'is_active' => true,
                ]
            );
        }
    }
}
