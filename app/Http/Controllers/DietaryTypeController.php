<?php

namespace App\Http\Controllers;

use App\Models\DietaryType;
use Illuminate\Http\Request;

class DietaryTypeController extends Controller
{
    public function index()
    {
        return response()->json(DietaryType::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);

        $dietaryType = DietaryType::create($validated);

        return response()->json($dietaryType, 201);
    }

    public function show(DietaryType $dietaryType)
    {
        return response()->json($dietaryType);
    }

    public function update(Request $request, DietaryType $dietaryType)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'is_active' => 'boolean',
        ]);

        $dietaryType->update($validated);

        return response()->json($dietaryType);
    }

    public function destroy(DietaryType $dietaryType)
    {
        $dietaryType->delete();

        return response()->json(null, 204);
    }
}
