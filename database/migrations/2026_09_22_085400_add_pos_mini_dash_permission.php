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
            'name' => 'pos-mini-dash',
            'guard_name' => $guardName,
        ]);

        foreach (['Admin', 'Super Admin', 'Outlet Manager'] as $roleName) {
            $role = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', $guardName)
                ->first();

            if ($role && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        // Anyone who already has sales reports can open mini-dash.
        $salesPermissionExists = Permission::query()
            ->where('name', 'report-sales')
            ->where('guard_name', $guardName)
            ->exists();
        $rolesWithSales = $salesPermissionExists ? Role::permission('report-sales')->get() : collect();
        foreach ($rolesWithSales as $role) {
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()
            ->where('name', 'pos-mini-dash')
            ->where('guard_name', 'web')
            ->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
