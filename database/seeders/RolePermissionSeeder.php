<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

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
            'housekeeping-supervisor-inspection',
            'housekeeping-laundry',
            'housekeeping-room-stock',
            'housekeeping-checklist-master',
            'housekeeping-cleaning-availability',
            'inventory-view',
            'manage-inventory',
            'manage-grn',
            'grn-inspect',
            'grn-approve',
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
            'pos-day-closing-override',
            'accounting-view-trial-balance',
            'accounting-vendor-pay',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function inventoryReportPermissionNames(): array
    {
        return array_values(array_filter(
            self::permissionNames(),
            fn (string $p) => str_starts_with($p, 'inventory-report-'),
        ));
    }

    /**
     * Seed permission rows only. Roles are seeded by DefaultHotelRolesSeeder.
     */
    public function run(): void
    {
        $guardName = 'web';
        foreach (self::permissionNames() as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => $guardName,
            ]);
        }
    }
}
