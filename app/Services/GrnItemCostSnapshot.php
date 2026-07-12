<?php

namespace App\Services;

use App\Models\GrnItem;

/**
 * Read-only cost truth from frozen GRN fields.
 *
 * Allowed sources: {@see GrnFrozenCostPolicy}
 * Forbidden: purchase_order_items, inventory_items.cost_price, LandedCostAllocator at read time.
 */
final class GrnItemCostSnapshot
{
    /**
     * @return array{
     *     quantity_accepted: float,
     *     line_subtotal_accepted: float,
     *     line_cess_accepted: float,
     *     line_freight_allocated: float,
     *     line_recoverable_tax_accepted: float,
     *     line_non_recoverable_tax_accepted: float,
     *     merchandise_unit_cost: float,
     *     cess_unit_cost: float,
     *     freight_unit_cost: float,
     *     non_recoverable_tax_unit_cost: float,
     *     posted_unit_cost: float,
     *     line_posted_total: float,
     *     uses_landed_cost: bool,
     *     tax_input_credit_eligible: ?bool,
     * }
     */
    public static function fromGrnItem(GrnItem $line, ?string $grnCostingMode = null): array
    {
        $accepted = max(0, (float) $line->quantity_accepted);
        $lineSubtotal = (float) ($line->line_subtotal_accepted ?? 0);
        $lineCess = (float) ($line->line_cess_accepted ?? 0);
        $lineFreight = (float) ($line->line_freight_allocated ?? 0);
        $lineRecoverableTax = (float) ($line->line_recoverable_tax_accepted ?? 0);
        $lineNonRecoverableTax = (float) ($line->line_non_recoverable_tax_accepted ?? 0);
        $postedUnit = (float) ($line->landed_unit_cost ?? 0);

        $merchandiseUnit = self::frozenUnit(
            $line->merchandise_unit_cost,
            $accepted > 0 ? $lineSubtotal / $accepted : 0.0
        );
        $cessUnit = self::frozenUnit(
            $line->cess_unit_cost,
            $accepted > 0 ? $lineCess / $accepted : 0.0
        );
        $freightUnit = self::frozenUnit(
            $line->freight_unit_cost,
            $accepted > 0 ? $lineFreight / $accepted : 0.0
        );
        $nonRecoverableTaxUnit = self::frozenUnit(
            $line->non_recoverable_tax_unit_cost,
            $accepted > 0 ? $lineNonRecoverableTax / $accepted : 0.0
        );

        $mode = $grnCostingMode ?? InventoryCostingConfig::MODE_TAX_AWARE;
        $usesLanded = $mode === InventoryCostingConfig::MODE_LANDED_COST
            || $mode === InventoryCostingConfig::MODE_TAX_AWARE;

        return [
            'quantity_accepted' => $accepted,
            'line_subtotal_accepted' => $lineSubtotal,
            'line_cess_accepted' => $lineCess,
            'line_freight_allocated' => $lineFreight,
            'line_recoverable_tax_accepted' => $lineRecoverableTax,
            'line_non_recoverable_tax_accepted' => $lineNonRecoverableTax,
            'merchandise_unit_cost' => $merchandiseUnit,
            'cess_unit_cost' => $cessUnit,
            'freight_unit_cost' => $freightUnit,
            'non_recoverable_tax_unit_cost' => $nonRecoverableTaxUnit,
            'posted_unit_cost' => $postedUnit,
            'line_posted_total' => round($postedUnit * $accepted, 2),
            'uses_landed_cost' => $usesLanded,
            'tax_input_credit_eligible' => $line->tax_input_credit_eligible,
        ];
    }

    private static function frozenUnit(mixed $storedUnit, float $fromLineTotals): float
    {
        $stored = $storedUnit !== null && $storedUnit !== '' ? (float) $storedUnit : 0.0;

        return $stored > 0 ? $stored : $fromLineTotals;
    }
}
