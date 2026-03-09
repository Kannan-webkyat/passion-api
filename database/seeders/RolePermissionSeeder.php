<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Permissions
        $permissions = [
            'manage-rooms',
            'view-rooms',
            'reservation',
            'manage-inventory',
            'manage-restaurant',
            'manage-bar',
            'view-reports',
            'manage-users',
            'manage-settings',
            'create-requisition',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Create Roles and Assign Permissions
        $admin = Role::firstOrCreate(['name' => 'Admin']);
        $admin->syncPermissions(Permission::all());

        $receptionist = Role::firstOrCreate(['name' => 'Receptionist']);
        $receptionist->syncPermissions(['manage-rooms', 'view-rooms', 'reservation', 'create-requisition']);

        $inventoryManager = Role::firstOrCreate(['name' => 'Inventory Manager']);
        $inventoryManager->syncPermissions(['manage-inventory']);

        $restaurantStaff = Role::firstOrCreate(['name' => 'Restaurant Staff']);
        $restaurantStaff->syncPermissions(['manage-restaurant', 'create-requisition']);

        $barStaff = Role::firstOrCreate(['name' => 'Bar Staff']);
        $barStaff->syncPermissions(['manage-bar', 'create-requisition']);

        $kitchenStaff = Role::firstOrCreate(['name' => 'Kitchen Staff']);
        $kitchenStaff->syncPermissions(['create-requisition']);
    }
}
