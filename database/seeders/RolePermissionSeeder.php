<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * Canonical permission names (used by seeder and API so the admin UI always lists every flag).
     *
     * @return array<int, string>
     */
    public static function permissionNames(): array
    {
        return [
            'manage-rooms',
            'view-rooms',
            'reservation',
            // Granular front office / rooms (optional; legacy permissions still work)
            'rooms-view',
            'rooms-create',
            'rooms-edit',
            'rooms-delete',
            'room-types-view',
            'room-types-create',
            'room-types-edit',
            'room-types-delete',
            'reservation-view',
            'reservation-create',
            'reservation-create-group',
            'reservation-hold-room',
            'reservation-maintenance-room',
            'reservation-edit',
            'reservation-delete',
            'housekeeping-dirty-rooms',
            'housekeeping-checkout-inspection',
            'housekeeping-cleaning-tasks',
            'housekeeping-daily-room-cleaning',
            'housekeeping-clean-rooms',
            'housekeeping-laundry',
            'manage-inventory',
            // Inventory report permissions (granular)
            'inventory-report-summary',
            'inventory-report-status',
            'inventory-report-reorder',
            'inventory-report-overstock',
            'inventory-report-slow-moving',
            'inventory-report-ledger',
            'inventory-report-consumption',
            'inventory-report-adjustments',
            'inventory-report-purchase-history',
            'manage-tables',
            // Outlet / menu master configuration
            'manage-outlets',
            'manage-menu',
            // POS / Finance report permissions (granular)
            'report-sales',
            'report-day-closings',
            'report-refunds-adjustments',
            'report-voids-discounts',
            'report-order-type-mix',
            'report-menu-performance',
            'report-tax-gst-summary',
            'report-b2b-sales',
            'manage-users',
            'manage-settings',
            'create-requisition',
            'kitchen-production',
            'pos-order',
            'pos-settle',
            'pos-void-item',
            'pos-discount',
            'pos-reopen-order',
            'pos-day-closing',
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // API users and Spatie roles both use the "web" guard (not sanctum).
        $guardName = 'web';
        foreach (self::permissionNames() as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => $guardName,
            ]);
        }

        // Create Roles and Assign Permissions
        $admin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => $guardName]);
        $admin->syncPermissions(Permission::query()->where('guard_name', $guardName)->get());

        $receptionist = Role::firstOrCreate(['name' => 'Receptionist', 'guard_name' => $guardName]);
        $receptionist->syncPermissions([
            'manage-rooms',
            'view-rooms',
            'room-types-view',
            'room-types-create',
            'room-types-edit',
            'rooms-view',
            'rooms-create',
            'rooms-edit',
            'reservation-view',
            'reservation-create',
            'reservation-create-group',
            'reservation-edit',
            'reservation-delete',
            'reservation-hold-room',
            'reservation-maintenance-room',
            'create-requisition',
        ]);

        $inventoryManager = Role::firstOrCreate(['name' => 'Inventory Manager', 'guard_name' => $guardName]);
        $inventoryManager->syncPermissions(['manage-inventory']);

        $restaurantStaff = Role::firstOrCreate(['name' => 'Restaurant Staff', 'guard_name' => $guardName]);
        $restaurantStaff->syncPermissions(['manage-tables', 'pos-order', 'pos-settle', 'create-requisition']);

        $cashier = Role::firstOrCreate(['name' => 'Cashier']);
        $cashier->syncPermissions(['pos-order', 'pos-settle']);

        $barStaff = Role::firstOrCreate(['name' => 'Bar Staff', 'guard_name' => $guardName]);
        $barStaff->syncPermissions(['create-requisition']);

        $kitchenStaff = Role::firstOrCreate(['name' => 'Kitchen Staff', 'guard_name' => $guardName]);
        $kitchenStaff->syncPermissions(['kitchen-production', 'create-requisition']);

        $waiter = Role::firstOrCreate(['name' => 'Waiter']);
        $waiter->syncPermissions(['pos-order']);

        $seniorWaiter = Role::firstOrCreate(['name' => 'Senior Waiter', 'guard_name' => $guardName]);
        $seniorWaiter->syncPermissions(['pos-order', 'pos-void-item', 'pos-reopen-order']);

        $housekeeping = Role::firstOrCreate(['name' => 'Housekeeping', 'guard_name' => $guardName]);
        $housekeeping->syncPermissions([
            'housekeeping-dirty-rooms',
            'housekeeping-checkout-inspection',
            'housekeeping-cleaning-tasks',
            'housekeeping-daily-room-cleaning',
            'housekeeping-clean-rooms',
            'housekeeping-laundry',
        ]);
    }
}
