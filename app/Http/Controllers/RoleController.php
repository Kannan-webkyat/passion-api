<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    private function checkPermission(string $permission)
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

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
        $this->checkPermission('manage-users');
        $validated = $request->validate([
            'name' => 'required|string|unique:roles,name',
            'permissions' => 'nullable|array',
        ]);

        if ($validated['name'] === 'Admin' && ! auth()->user()->hasRole('Admin')) {
            abort(403, 'Only Admins can create the Admin role.');
        }

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
        $this->checkPermission('manage-users');
        if ($role->name === 'Admin' && ! auth()->user()->hasRole('Admin')) {
            abort(403, 'Only Admins can modify the Admin role.');
        }
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
        $this->checkPermission('manage-users');
        if ($role->name === 'Admin') {
            return response()->json(['message' => 'Cannot delete the Admin role.'], 403);
        }

        $role->delete();

        return response()->json(null, 204);
    }
}
