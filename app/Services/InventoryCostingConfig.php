<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Controls whether GRN approval posts exclusive merchandise or full landed unit cost to WAC.
 */
final class InventoryCostingConfig
{
    public const SETTING_KEY = 'inventory_costing_mode';

    public const MODE_EXCLUSIVE_ONLY = 'exclusive_only';

    public const MODE_LANDED_COST = 'landed_cost';

    public static function mode(): string
    {
        $raw = strtolower(trim((string) Setting::get(self::SETTING_KEY, self::MODE_EXCLUSIVE_ONLY)));

        return $raw === self::MODE_LANDED_COST ? self::MODE_LANDED_COST : self::MODE_EXCLUSIVE_ONLY;
    }

    public static function usesLandedCost(): bool
    {
        return self::mode() === self::MODE_LANDED_COST;
    }

    /**
     * Unit cost to persist on GRN line and post to inventory WAC.
     *
     * @param  array{merchandise_unit: float, landed_unit_purchase: float}  $allocation
     */
    public static function postedUnitCostFromAllocation(array $allocation): float
    {
        return self::postedUnitCostForMode(self::mode(), $allocation);
    }

    /**
     * @param  array{merchandise_unit: float, landed_unit_purchase: float}  $allocation
     */
    public static function postedUnitCostForMode(string $mode, array $allocation): float
    {
        $usesLanded = strtolower(trim($mode)) === self::MODE_LANDED_COST;
        $unit = $usesLanded
            ? (float) ($allocation['landed_unit_purchase'] ?? 0)
            : (float) ($allocation['merchandise_unit'] ?? 0);

        return round(max(0, $unit), 4);
    }

    public static function setMode(string $mode): void
    {
        $normalized = strtolower(trim($mode)) === self::MODE_LANDED_COST
            ? self::MODE_LANDED_COST
            : self::MODE_EXCLUSIVE_ONLY;

        Setting::set(self::SETTING_KEY, $normalized);
    }

    /** @return array<string, mixed> */
    public static function publicMeta(): array
    {
        $mode = self::mode();
        $usesLanded = $mode === self::MODE_LANDED_COST;

        return [
            'inventory_costing_mode' => $mode,
            'label' => $usesLanded ? 'Landed Cost' : 'Exclusive',
            'badge' => $usesLanded ? 'Costing Mode: Landed Cost' : 'Costing Mode: Exclusive',
            'description' => $usesLanded
                ? 'Stock WAC includes exclusive base price, liquor cess, and allocated freight from the PO. GST/VAT is excluded.'
                : 'Stock WAC uses exclusive merchandise price only. Cess and freight are excluded from inventory valuation.',
            'includes_cess' => $usesLanded,
            'includes_freight' => $usesLanded,
        ];
    }
}
