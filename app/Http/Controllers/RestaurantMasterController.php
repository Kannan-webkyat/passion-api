<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\RestaurantMaster;

class RestaurantMasterController extends Controller
{
    public function index()
    {
        return response()->json(RestaurantMaster::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'floor' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $restaurantMaster = RestaurantMaster::create($validated);
        return response()->json($restaurantMaster, 201);
    }

    public function show($id)
    {
        $restaurantMaster = RestaurantMaster::findOrFail($id);
        return response()->json($restaurantMaster);
    }

    public function update(Request $request, $id)
    {
        $restaurantMaster = RestaurantMaster::findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'floor' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $restaurantMaster->update($validated);
        return response()->json($restaurantMaster);
    }

    public function destroy($id)
    {
        $restaurantMaster = RestaurantMaster::findOrFail($id);
        $restaurantMaster->delete();
        return response()->json(null, 204);
    }
}
