<?php

namespace App\Http\Controllers;

use App\Models\Combo;
use App\Models\RestaurantCombo;
use Illuminate\Http\Request;

class ComboController extends Controller
{
    public function index()
    {
        return response()->json(Combo::with(['menuItems', 'restaurantCombos'])->get());
    }

    private function checkPermission(string $permission)
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function store(Request $request)
    {
        $this->checkPermission('manage-restaurant');
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'fixed_ept' => 'nullable|integer',
            'is_active' => 'boolean',
            'menu_item_ids' => 'required|array',
            'menu_item_ids.*' => 'exists:menu_items,id',
            'restaurant_prices' => 'nullable|array',
            'restaurant_prices.*.restaurant_id' => 'required|exists:restaurant_masters,id',
            'restaurant_prices.*.price' => 'required|numeric|min:0',
        ]);

        if (! isset($validated['price']) || $validated['price'] === null) {
            $validated['price'] = 0;
        }
        $combo = Combo::create($validated);
        $combo->menuItems()->sync($request->menu_item_ids);

        if (! empty($validated['restaurant_prices'] ?? [])) {
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
        $this->checkPermission('manage-restaurant');
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'fixed_ept' => 'nullable|integer',
            'is_active' => 'boolean',
            'menu_item_ids' => 'sometimes|required|array',
            'menu_item_ids.*' => 'exists:menu_items,id',
            'restaurant_prices' => 'nullable|array',
            'restaurant_prices.*.restaurant_id' => 'required|exists:restaurant_masters,id',
            'restaurant_prices.*.price' => 'required|numeric|min:0',
        ]);

        if (array_key_exists('price', $validated) && $validated['price'] === null) {
            $validated['price'] = 0;
        }

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
        $this->checkPermission('manage-restaurant');
        try {
            $menuCombo->delete();

            return response()->json(null, 204);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1451 || $e->getCode() == '23000') {
                return response()->json(['message' => 'Cannot delete combo because it is referenced in existing POS orders. Please mark it as Inactive.'], 409);
            }
            throw $e;
        }
    }

    private function syncRestaurantPrices(Combo $combo, array $restaurantPrices): void
    {
        $byRestaurant = collect($restaurantPrices)->keyBy('restaurant_id');
        $restaurantIds = $byRestaurant->keys()->filter(fn ($id) => $id > 0)->values();
        $combo->restaurantCombos()->whereNotIn('restaurant_master_id', $restaurantIds)->delete();

        foreach ($byRestaurant as $restaurantId => $rp) {
            if ($restaurantId <= 0) {
                continue;
            }
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
