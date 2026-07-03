<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Central RBAC for inventory → procurement → GRN → accounts payable.
 *
 * Segregation of duties:
 * - grn-inspect: receive, submit, quality inspect (no stock post)
 * - grn-approve: post stock to ledger (separate from inspect)
 * - manage-grn: legacy umbrella (inspect + approve) for small teams
 * - manage-inventory: master data, PO, issue, adjust — inspect GRN but not approve alone
 */
final class InventoryAuthorization
{
    public const VIEW = 'inventory-view';

    public const MANAGE = 'manage-inventory';

    public const GRN_LEGACY = 'manage-grn';

    public const GRN_INSPECT = 'grn-inspect';

    public const GRN_APPROVE = 'grn-approve';

    public const REQUISITION = 'create-requisition';

    public const VENDOR_PAY = 'accounting-vendor-pay';

    public const TRIAL_BALANCE = 'accounting-view-trial-balance';

  /** @var array<int, string> */
    private const INVENTORY_REPORT_PERMISSIONS = [
        'inventory-report-summary',
        'inventory-report-status',
        'inventory-report-reorder',
        'inventory-report-overstock',
        'inventory-report-slow-moving',
        'inventory-report-ledger',
        'inventory-report-consumption',
        'inventory-report-adjustments',
        'inventory-report-purchase-history',
    ];

    public static function user(): ?User
    {
        /** @var User|null */
        return Auth::user();
    }

    public static function isPrivileged(?User $user = null): bool
    {
        $user ??= self::user();
        if (! $user) {
            return false;
        }

        return $user->hasRole('Admin') || $user->hasRole('Super Admin');
    }

    /** @param  array<int, string>  $permissions */
    public static function canAny(?User $user, array $permissions): bool
    {
        $user ??= self::user();
        if (! $user) {
            return false;
        }
        if (self::isPrivileged($user)) {
            return true;
        }
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<int, string>  $permissions */
    public static function assertAny(array $permissions, string $message = 'Unauthorized action.'): void
    {
        if (! self::canAny(self::user(), $permissions)) {
            abort(403, $message);
        }
    }

    public static function assertManage(string $message = 'Unauthorized action.'): void
    {
        self::assertAny([self::MANAGE], $message);
    }

    /** @return array<int, string> */
    public static function catalogViewPermissions(): array
    {
        return array_merge(
            [
                self::VIEW,
                self::MANAGE,
                self::REQUISITION,
                self::GRN_LEGACY,
                self::GRN_INSPECT,
                self::GRN_APPROVE,
                self::VENDOR_PAY,
                'manage-settings',
            ],
            self::INVENTORY_REPORT_PERMISSIONS,
        );
    }

    public static function canViewCatalog(?User $user = null): bool
    {
        return self::canAny($user, self::catalogViewPermissions());
    }

    public static function assertViewCatalog(string $message = 'Unauthorized action.'): void
    {
        self::assertAny(self::catalogViewPermissions(), $message);
    }

    /** @return array<int, string> */
    public static function procurementViewPermissions(): array
    {
        return [
            self::VIEW,
            self::MANAGE,
            self::GRN_LEGACY,
            self::GRN_INSPECT,
            self::GRN_APPROVE,
            self::VENDOR_PAY,
            'inventory-report-purchase-history',
        ];
    }

    public static function canViewProcurement(?User $user = null): bool
    {
        return self::canAny($user, self::procurementViewPermissions());
    }

    public static function assertViewProcurement(string $message = 'Unauthorized action.'): void
    {
        self::assertAny(self::procurementViewPermissions(), $message);
    }

    public static function canInspectGrn(?User $user = null): bool
    {
        return self::canAny($user, [self::GRN_LEGACY, self::GRN_INSPECT, self::MANAGE]);
    }

    public static function assertInspectGrn(string $message = 'Unauthorized action.'): void
    {
        self::assertAny([self::GRN_LEGACY, self::GRN_INSPECT, self::MANAGE], $message);
    }

    public static function canApproveGrn(?User $user = null): bool
    {
        return self::canAny($user, [self::GRN_LEGACY, self::GRN_APPROVE]);
    }

    public static function assertApproveGrn(string $message = 'Unauthorized action.'): void
    {
        self::assertAny([self::GRN_LEGACY, self::GRN_APPROVE], $message);
    }

    public static function assertViewGrn(string $message = 'Unauthorized action.'): void
    {
        self::assertAny([
            self::VIEW,
            self::MANAGE,
            self::GRN_LEGACY,
            self::GRN_INSPECT,
            self::GRN_APPROVE,
            self::VENDOR_PAY,
        ], $message);
    }

    public static function canPayVendor(?User $user = null): bool
    {
        return self::canAny($user, [self::VENDOR_PAY, 'manage-settings']);
    }

    public static function assertPayVendor(string $message = 'Unauthorized action.'): void
    {
        self::assertAny([self::VENDOR_PAY, 'manage-settings'], $message);
    }

    /** @return array<int, string> */
    public static function movementViewPermissions(): array
    {
        return array_merge(
            [self::VIEW, self::MANAGE, 'inventory-report-ledger', 'inventory-report-summary'],
            self::INVENTORY_REPORT_PERMISSIONS,
        );
    }

    public static function assertViewMovements(string $message = 'Unauthorized action.'): void
    {
        self::assertAny(self::movementViewPermissions(), $message);
    }
}
