<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $guardName = 'web';
        $permission = Permission::firstOrCreate([
            'name' => 'housekeeping-checkout-inspection-assign',
            'guard_name' => $guardName,
        ]);

        foreach (['Admin', 'Super Admin'] as $roleName) {
            $role = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', $guardName)
                ->first();

            if ($role && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        // Roles that could already assign pending inspections via housekeeping-assignable keep that ability.
        $assignableExists = Permission::query()
            ->where('name', 'housekeeping-assignable')
            ->where('guard_name', $guardName)
            ->exists();
        $rolesWithAssignable = $assignableExists ? Role::permission('housekeeping-assignable')->get() : collect();
        foreach ($rolesWithAssignable as $role) {
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()
            ->where('name', 'housekeeping-checkout-inspection-assign')
            ->where('guard_name', 'web')
            ->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
