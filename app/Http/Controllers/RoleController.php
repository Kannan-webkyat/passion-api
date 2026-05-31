<?php

namespace App\Http\Controllers;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

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
        $this->ensureCanonicalPermissionsExist();

        // Roles use guard "web" only — ignore legacy rows created under other guards (e.g. sanctum).
        return Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get();
    }

    /**
     * Create any permission rows defined in code but not yet in the DB (admin UI lists from GET /permissions).
     */
    private function ensureCanonicalPermissionsExist(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $created = false;
        // Roles use guard "web"; permissions must use the same guard for syncPermissions().
        $guardName = 'web';

        foreach (RolePermissionSeeder::permissionNames() as $name) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => $guardName,
            ]);
            if ($permission->wasRecentlyCreated) {
                $created = true;
            }
        }

        if ($created) {
            $registrar->forgetCachedPermissions();
        }
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
            $this->ensureCanonicalPermissionsExist();
            $role->syncPermissions($this->resolvePermissionNamesForGuard($validated['permissions'], $role->guard_name));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
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
            'name' => 'string|unique:roles,name,' . $role->id,
            'permissions' => 'nullable|array',
        ]);

        if (isset($validated['name'])) {
            $role->name = $validated['name'];
            $role->save();
        }

        if (isset($validated['permissions'])) {
            $this->ensureCanonicalPermissionsExist();
            $role->syncPermissions($this->resolvePermissionNamesForGuard($validated['permissions'], $role->guard_name));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return response()->json($role->load('permissions'));
    }

    /**
     * Keep only permission names that exist for the role guard (after canonical seed).
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private function resolvePermissionNamesForGuard(array $names, string $guardName): array
    {
        $existing = Permission::query()
            ->where('guard_name', $guardName)
            ->whereIn('name', $names)
            ->pluck('name')
            ->all();

        return array_values(array_intersect($names, $existing));
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
