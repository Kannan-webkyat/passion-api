<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Tax-aware inventory WAC: posted unit = merchandise + cess + freight + non-recoverable tax.
 * Recoverable GST is excluded and posted to Input GST asset at GRN approve.
 */
final class InventoryCostingConfig
{
    public const SETTING_KEY = 'inventory_costing_mode';

    /** @deprecated Legacy — new GRNs always use tax-aware landed cost. */
    public const MODE_EXCLUSIVE_ONLY = 'exclusive_only';

    /** @deprecated Legacy — superseded by tax-aware landed cost. */
    public const MODE_LANDED_COST = 'landed_cost';

    public const MODE_TAX_AWARE = 'tax_aware';

    public static function mode(): string
    {
        $raw = strtolower(trim((string) Setting::get(self::SETTING_KEY, self::MODE_TAX_AWARE)));

        return match ($raw) {
            self::MODE_EXCLUSIVE_ONLY => self::MODE_EXCLUSIVE_ONLY,
            self::MODE_LANDED_COST => self::MODE_LANDED_COST,
            default => self::MODE_TAX_AWARE,
        };
    }

    public static function usesLandedCost(): bool
    {
        return self::mode() !== self::MODE_EXCLUSIVE_ONLY;
    }

    /**
     * Unit cost to persist on GRN line and post to inventory WAC.
     *
     * @param  array{
     *     merchandise_unit: float,
     *     landed_unit_purchase: float,
     *     non_recoverable_tax_unit?: float,
     * }  $allocation
     */
    public static function postedUnitCostFromAllocation(array $allocation): float
    {
        return self::postedUnitCostForMode(self::mode(), $allocation);
    }

    /**
     * @param  array{
     *     merchandise_unit: float,
     *     landed_unit_purchase: float,
     *     non_recoverable_tax_unit?: float,
     * }  $allocation
     */
    public static function postedUnitCostForMode(string $mode, array $allocation): float
    {
        $mode = strtolower(trim($mode));

        if ($mode === self::MODE_TAX_AWARE) {
            return round(max(0, (float) ($allocation['landed_unit_purchase'] ?? 0)), 4);
        }

        $usesLanded = $mode === self::MODE_LANDED_COST;
        $unit = $usesLanded
            ? (float) ($allocation['landed_unit_purchase'] ?? 0)
            : (float) ($allocation['merchandise_unit'] ?? 0);

        return round(max(0, $unit), 4);
    }

    public static function setMode(string $mode): void
    {
        $normalized = match (strtolower(trim($mode))) {
            self::MODE_EXCLUSIVE_ONLY => self::MODE_EXCLUSIVE_ONLY,
            self::MODE_LANDED_COST => self::MODE_LANDED_COST,
            default => self::MODE_TAX_AWARE,
        };

        Setting::set(self::SETTING_KEY, $normalized);
    }

    /** @return array<string, mixed> */
    public static function publicMeta(): array
    {
        $mode = self::mode();

        if ($mode === self::MODE_TAX_AWARE) {
            return [
                'inventory_costing_mode' => $mode,
                'label' => 'Tax-Aware Landed',
                'badge' => 'Costing Mode: Tax-Aware Landed',
                'description' => 'Stock WAC = exclusive base + cess + freight + non-recoverable tax (e.g. liquor VAT). Recoverable GST posts to Input GST asset.',
                'includes_cess' => true,
                'includes_freight' => true,
                'includes_non_recoverable_tax' => true,
            ];
        }

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
            'includes_non_recoverable_tax' => false,
        ];
    }
}
