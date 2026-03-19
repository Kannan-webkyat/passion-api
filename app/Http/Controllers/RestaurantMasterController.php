<?php

namespace App\Http\Controllers;

use App\Models\RestaurantMaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RestaurantMasterController extends Controller
{
    public function index()
    {
        return response()->json(RestaurantMaster::all());
    }

    public function store(Request $request)
    {
        $validated = $this->validateRestaurant($request);
        $restaurant = RestaurantMaster::create($validated);
        return response()->json($restaurant, 201);
    }

    public function show(RestaurantMaster $restaurantMaster)
    {
        return response()->json($restaurantMaster);
    }

    public function update(Request $request, RestaurantMaster $restaurantMaster)
    {
        $validated = $this->validateRestaurant($request);
        $restaurantMaster->update($validated);
        return response()->json($restaurantMaster);
    }

    public function destroy(RestaurantMaster $restaurantMaster)
    {
        $restaurantMaster->delete();
        return response()->json(null, 204);
    }

    private function validateRestaurant(Request $request): array
    {
        $rules = [
            'name'        => 'required|string|max:255',
            'floor'       => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_active'   => 'boolean',
            'address'     => 'nullable|string|max:1000',
            'email'       => 'nullable|email|max:255',
            'phone'       => 'nullable|string|max:50',
            'gstin'       => 'nullable|string|max:50',
            'fssai'       => 'nullable|string|max:50',
        ];
        return $request->validate($rules);
    }

    public function uploadLogo(Request $request, RestaurantMaster $restaurantMaster)
    {
        $request->validate(['logo' => 'required|image|mimes:png,jpg,jpeg|max:512']);
        if ($restaurantMaster->logo_path && Storage::disk('public')->exists($restaurantMaster->logo_path)) {
            Storage::disk('public')->delete($restaurantMaster->logo_path);
        }
        $path = $request->file('logo')->store('restaurant-logos', 'public');
        $restaurantMaster->update(['logo_path' => $path]);
        return response()->json($restaurantMaster->fresh());
    }
}
