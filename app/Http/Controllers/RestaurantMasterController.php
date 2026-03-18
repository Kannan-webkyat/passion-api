<?php

namespace App\Http\Controllers;

use App\Models\RestaurantMaster;
use Illuminate\Http\Request;

class RestaurantMasterController extends Controller
{
    public function index()
    {
        return response()->json(RestaurantMaster::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'floor'       => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_active'   => 'boolean',
        ]);

        $restaurant = RestaurantMaster::create($validated);
        return response()->json($restaurant, 201);
    }

    public function show(RestaurantMaster $restaurantMaster)
    {
        return response()->json($restaurantMaster);
    }

    public function update(Request $request, RestaurantMaster $restaurantMaster)
    {
        $validated = $request->validate([
            'name'        => 'sometimes|required|string|max:255',
            'floor'       => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_active'   => 'boolean',
        ]);

        $restaurantMaster->update($validated);
        return response()->json($restaurantMaster);
    }

    public function destroy(RestaurantMaster $restaurantMaster)
    {
        $restaurantMaster->delete();
        return response()->json(null, 204);
    }
}
