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
            'manage-restaurants',
            'manage-tables',
            'manage-bar',
            'view-reports',
            'manage-users',
            'manage-settings',
            'create-requisition',
            'kitchen-production',
            'pos-order',
            'pos-settle',
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
        $restaurantStaff->syncPermissions(['manage-restaurant', 'manage-restaurants', 'manage-tables', 'pos-order', 'pos-settle', 'create-requisition']);

        $cashier = Role::firstOrCreate(['name' => 'Cashier']);
        $cashier->syncPermissions(['pos-order', 'pos-settle']);

        $barStaff = Role::firstOrCreate(['name' => 'Bar Staff']);
        $barStaff->syncPermissions(['manage-bar', 'create-requisition']);

        $kitchenStaff = Role::firstOrCreate(['name' => 'Kitchen Staff']);
        $kitchenStaff->syncPermissions(['kitchen-production', 'create-requisition']);

        $waiter = Role::firstOrCreate(['name' => 'Waiter']);
        $waiter->syncPermissions(['manage-restaurant', 'pos-order']);

        $seniorWaiter = Role::firstOrCreate(['name' => 'Senior Waiter']);
        $seniorWaiter->syncPermissions(['manage-restaurant', 'pos-order']);
    }
}
