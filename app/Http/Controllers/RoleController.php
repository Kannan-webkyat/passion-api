<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index()
    {
        return Role::with('permissions')->get();
    }

    public function permissions()
    {
        return Permission::all();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:roles,name',
            'permissions' => 'nullable|array',
        ]);

        $role = Role::create(['name' => $validated['name'], 'guard_name' => 'web']);

        if (isset($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        return response()->json($role->load('permissions'), 201);
    }

    public function show(Role $role)
    {
        return $role->load('permissions');
    }

    public function update(Request $request, Role $role)
    {
        $validated = $request->validate([
            'name' => 'string|unique:roles,name,'.$role->id,
            'permissions' => 'nullable|array',
        ]);

        if (isset($validated['name'])) {
            $role->name = $validated['name'];
            $role->save();
        }

        if (isset($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        return response()->json($role->load('permissions'));
    }

    public function destroy(Role $role)
    {
        if ($role->name === 'Admin') {
            return response()->json(['message' => 'Cannot delete the Admin role.'], 403);
        }

        $role->delete();

        return response()->json(null, 204);
    }
}
