<?php

namespace App\Http\Controllers;

use App\Models\RoomType;
use Illuminate\Http\Request;

class RoomTypeController extends Controller
{
    public function index()
    {
        return RoomType::all();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'base_price' => 'required|numeric|min:0',
            'extra_bed_cost' => 'required|numeric|min:0',
            'base_occupancy' => 'nullable|integer|min:1',
            'capacity' => 'required|integer|min:1',
            'extra_bed_capacity' => 'nullable|integer|min:0',
            'child_sharing_limit' => 'nullable|integer|min:0',
            'bed_config' => 'nullable|string|max:255',
            'amenities' => 'nullable|array',
        ]);

        $roomType = RoomType::create($validated);

        return response()->json($roomType, 201);
    }

    public function show(RoomType $roomType)
    {
        return $roomType;
    }

    public function update(Request $request, RoomType $roomType)
    {
        $validated = $request->validate([
            'name' => 'string|max:255',
            'description' => 'nullable|string',
            'base_price' => 'numeric|min:0',
            'extra_bed_cost' => 'numeric|min:0',
            'base_occupancy' => 'nullable|integer|min:1',
            'capacity' => 'integer|min:1',
            'extra_bed_capacity' => 'nullable|integer|min:0',
            'child_sharing_limit' => 'nullable|integer|min:0',
            'bed_config' => 'nullable|string|max:255',
            'amenities' => 'nullable|array',
        ]);

        $roomType->update($validated);

        return response()->json($roomType);
    }

    public function destroy(RoomType $roomType)
    {
        $roomType->delete();
        return response()->json(null, 204);
    }
}
