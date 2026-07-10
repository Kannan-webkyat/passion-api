<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Standard hotel role templates. Idempotent — safe to re-run after new permissions are added.
 *
 * Run after RolePermissionSeeder so every permission row exists.
 */
class DefaultHotelRolesSeeder extends Seeder
{
    /**
     * Role name => permission names. Admin is synced to all permissions at seed time.
     *
     * @return array<string, array<int, string>|null>
     */
    public static function rolePermissionMap(): array
    {
        $inventoryReports = RolePermissionSeeder::inventoryReportPermissionNames();

        return [
            'Admin' => null,
            'Waiter' => [
                'pos-order',
            ],
            'Cashier' => [
                'pos-order',
                'pos-settle',
            ],
            'Kitchen Staff' => [
                'kitchen-production',
                'create-requisition',
                'view-dashboard',
            ],
            'Store Keeper' => [
                'inventory-view',
                'grn-inspect',
                'create-requisition',
            ],
            'Store Manager' => array_merge([
                'inventory-view',
                'manage-inventory',
                'manage-grn',
                'create-requisition',
                'view-dashboard',
            ], $inventoryReports),
            'Outlet Manager' => [
                'pos-order',
                'pos-settle',
                'pos-day-closing',
                'pos-void-item',
                'pos-discount',
                'pos-reopen-order',
                'report-sales',
                'report-day-closings',
                'report-refunds-adjustments',
                'report-voids-discounts',
                'report-order-type-mix',
                'report-menu-performance',
                'report-tax-gst-summary',
                'view-dashboard',
            ],
            'Accounts' => [
                'inventory-view',
                'accounting-vendor-pay',
                'accounting-view-trial-balance',
                'inventory-report-purchase-history',
                'view-dashboard',
            ],
        ];
    }

    public function run(): void
    {
        $guardName = 'web';
        $allPermissions = Permission::query()->where('guard_name', $guardName)->get();

        foreach (self::rolePermissionMap() as $roleName => $permissionNames) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => $guardName]);

            if ($permissionNames === null) {
                $role->syncPermissions($allPermissions);

                continue;
            }

            $role->syncPermissions(
                Permission::query()
                    ->where('guard_name', $guardName)
                    ->whereIn('name', $permissionNames)
                    ->get()
            );
        }
    }
}
