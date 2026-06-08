<?php

namespace App\Http\Controllers;

use App\Models\DietaryType;
use Illuminate\Http\Request;

class DietaryTypeController extends Controller
{
    private function checkPermission(string $permission): void
    {
        $user = auth()->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }
        if ($user->hasRole('Admin') || $user->hasRole('Super Admin')) {
            return;
        }
        if (! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index()
    {
        return response()->json(DietaryType::all());
    }

    public function store(Request $request)
    {
        $this->checkPermission('manage-menu');
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_active' => 'boolean',
        ]);

        $dietaryType = DietaryType::create($validated);

        return response()->json($dietaryType, 201);
    }

    public function show(DietaryType $menuDietaryType)
    {
        return response()->json($menuDietaryType);
    }

    public function update(Request $request, DietaryType $menuDietaryType)
    {
        $this->checkPermission('manage-menu');
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'is_active' => 'boolean',
        ]);

        $menuDietaryType->update($validated);

        return response()->json($menuDietaryType);
    }

    public function destroy(DietaryType $menuDietaryType)
    {
        $this->checkPermission('manage-menu');
        $menuDietaryType->delete();

        return response()->json(null, 204);
    }
}
