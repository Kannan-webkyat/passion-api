<?php

namespace App\Http\Controllers;

use App\Models\RestaurantMaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RestaurantMasterController extends Controller
{
    private function checkPermission(string $permission)
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index(Request $request)
    {
        $query = RestaurantMaster::with(['kitchenLocation', 'barLocation', 'department']);
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $this->checkPermission('pos-manages');
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
        $this->checkPermission('pos-manages');
        $validated = $this->validateRestaurant($request);
        $restaurantMaster->update($validated);

        return response()->json($restaurantMaster);
    }

    public function destroy(RestaurantMaster $restaurantMaster)
    {
        $this->checkPermission('pos-manages');
        try {
            $restaurantMaster->delete();

            return response()->json(null, 204);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1451 || $e->getCode() == '23000') {
                return response()->json(['message' => 'Cannot delete this restaurant because it has active tables, orders, or menu mappings. Please mark as Inactive instead.'], 409);
            }
            throw $e;
        }
    }

    private function validateRestaurant(Request $request): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'floor' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'department_id' => 'nullable|exists:departments,id',
            'kitchen_location_id' => 'required|exists:inventory_locations,id',
            'bar_location_id' => 'nullable|exists:inventory_locations,id',
            'address' => 'nullable|string|max:1000',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'gstin' => 'nullable|string|max:50',
            'fssai' => 'nullable|string|max:50',
            'business_day_cutoff_time' => 'nullable|date_format:H:i:s',
            'receipt_show_tax_breakdown' => 'boolean',
        ];

        $validated = $request->validate($rules);
        if (array_key_exists('business_day_cutoff_time', $validated) && $validated['business_day_cutoff_time'] === null) {
            $validated['business_day_cutoff_time'] = '04:00:00';
        }

        return $validated;
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
