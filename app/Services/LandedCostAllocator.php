<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

/**
 * APPROVE-TIME WRITE ONLY. Persists frozen values on grn_items via GrnService::approve().
 *
 * Tax-aware WAC: landed unit = merchandise + cess + freight + non_recoverable_tax.
 * Recoverable tax (GST ITC) is excluded from inventory and posted to input tax asset.
 *
 * For reads use {@see GrnItemCostSnapshot} / {@see GrnFrozenCostPolicy}.
 */
final class LandedCostAllocator
{
    /**
     * @return array{
     *     line_subtotal_accepted: float,
     *     line_cess_accepted: float,
     *     line_freight_allocated: float,
     *     line_recoverable_tax_accepted: float,
     *     line_non_recoverable_tax_accepted: float,
     *     merchandise_unit: float,
     *     cess_unit: float,
     *     freight_unit: float,
     *     non_recoverable_tax_unit: float,
     *     recoverable_tax_unit: float,
     *     landed_unit_purchase: float,
     *     landed_total: float,
     *     tax_input_credit_eligible: bool,
     * }
     */
    public static function forGrnLine(
        PurchaseOrder $po,
        PurchaseOrderItem $poItem,
        float $acceptedQty,
        float $grnMerchandiseSubtotalSum,
        bool $isInputCreditEligible
    ): array {
        $accepted = max(0, $acceptedQty);
        $ordered = max(0.000001, (float) $poItem->quantity_ordered);
        $share = $accepted > 0 ? $accepted / $ordered : 0.0;

        $lineSubtotalHigh = (float) ($poItem->subtotal ?? 0) * $share;
        $lineCessHigh = (float) ($poItem->total_cess ?? 0) * $share;
        $lineTaxHigh = round((float) ($poItem->tax_amount ?? 0) * $share, 2);

        $poSubtotal = max(0, (float) ($po->subtotal ?? 0));
        $headerCharges = max(0, (float) ($po->transportation_charge ?? 0))
            + max(0, (float) ($po->loading_unloading_charge ?? 0));

        $headerThisGrn = $poSubtotal > 0 && $grnMerchandiseSubtotalSum > 0
            ? $headerCharges * ($grnMerchandiseSubtotalSum / $poSubtotal)
            : 0.0;

        $lineFreightHigh = $grnMerchandiseSubtotalSum > 0 && $lineSubtotalHigh > 0
            ? $headerThisGrn * ($lineSubtotalHigh / $grnMerchandiseSubtotalSum)
            : 0.0;

        $merchandiseUnit = (float) ($poItem->subtotal ?? 0) / $ordered;
        $cessUnit = (float) ($poItem->total_cess ?? 0) / $ordered;
        $freightUnit = $accepted > 0 ? $lineFreightHigh / $accepted : 0.0;
        $taxUnit = (float) ($poItem->tax_amount ?? 0) / $ordered;

        $taxSplit = TaxCreditPolicy::splitLineTax($lineTaxHigh, $accepted, $isInputCreditEligible);
        $nonRecoverableTaxUnit = $isInputCreditEligible ? 0.0 : round($taxUnit, 4);
        $recoverableTaxUnit = $isInputCreditEligible ? round($taxUnit, 4) : 0.0;

        $landedUnitPurchase = round(
            $merchandiseUnit + $cessUnit + $freightUnit + $nonRecoverableTaxUnit,
            4
        );
        $landedTotal = round($landedUnitPurchase * $accepted, 2);

        return [
            'line_subtotal_accepted' => round($lineSubtotalHigh, 2),
            'line_cess_accepted' => round($lineCessHigh, 2),
            'line_freight_allocated' => round($lineFreightHigh, 2),
            'line_recoverable_tax_accepted' => $taxSplit['recoverable'],
            'line_non_recoverable_tax_accepted' => $taxSplit['non_recoverable'],
            'merchandise_unit' => $merchandiseUnit,
            'cess_unit' => $cessUnit,
            'freight_unit' => $freightUnit,
            'non_recoverable_tax_unit' => $nonRecoverableTaxUnit,
            'recoverable_tax_unit' => $recoverableTaxUnit,
            'landed_unit_purchase' => $landedUnitPurchase,
            'landed_total' => $landedTotal,
            'tax_input_credit_eligible' => $isInputCreditEligible,
        ];
    }

    /**
     * Sum of accepted exclusive merchandise for all GRN lines (high precision for allocation).
     *
     * @param  iterable<int, object{quantity_accepted: float|string, purchase_order_item_id: int}>  $grnLines
     */
    public static function grnMerchandiseSubtotalSum(iterable $grnLines, PurchaseOrder $po): float
    {
        $sum = 0.0;
        if (! $po->relationLoaded('items')) {
            $po->loadMissing('items');
        }

        foreach ($grnLines as $grnLine) {
            $accepted = (float) $grnLine->quantity_accepted;
            if ($accepted <= 0) {
                continue;
            }

            $poItem = $po->items->firstWhere('id', (int) $grnLine->purchase_order_item_id);
            if (! $poItem) {
                continue;
            }

            $ordered = max(0.000001, (float) $poItem->quantity_ordered);
            $sum += (float) ($poItem->subtotal ?? 0) * ($accepted / $ordered);
        }

        return $sum;
    }

    /**
     * Total freight allocated to a GRN (sum of line allocations). Used to verify multi-GRN splits.
     *
     * @param  iterable<int, object{quantity_accepted: float|string, purchase_order_item_id: int}>  $grnLines
     */
    public static function grnFreightAllocatedSum(iterable $grnLines, PurchaseOrder $po): float
    {
        $grnMerch = self::grnMerchandiseSubtotalSum($grnLines, $po);
        $total = 0.0;

        foreach ($grnLines as $grnLine) {
            $accepted = (float) $grnLine->quantity_accepted;
            if ($accepted <= 0) {
                continue;
            }

            $poItem = $po->items->firstWhere('id', (int) $grnLine->purchase_order_item_id);
            if (! $poItem) {
                continue;
            }

            $poItem->loadMissing('inventoryItem.tax');
            $eligible = TaxCreditPolicy::forInventoryItem($poItem->inventoryItem);

            $total += self::forGrnLine($po, $poItem, $accepted, $grnMerch, $eligible)['line_freight_allocated'];
        }

        return round($total, 2);
    }

    /**
     * Expected freight for this GRN's merchandise share of the PO (before line split rounding).
     */
    public static function grnFreightShareBeforeLineSplit(PurchaseOrder $po, float $grnMerchandiseSubtotalSum): float
    {
        $poSubtotal = max(0, (float) ($po->subtotal ?? 0));
        $headerCharges = max(0, (float) ($po->transportation_charge ?? 0))
            + max(0, (float) ($po->loading_unloading_charge ?? 0));

        if ($poSubtotal <= 0 || $grnMerchandiseSubtotalSum <= 0) {
            return 0.0;
        }

        return round($headerCharges * ($grnMerchandiseSubtotalSum / $poSubtotal), 2);
    }
}
