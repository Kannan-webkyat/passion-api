<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryTax;

/**
 * Determines whether purchase tax is recoverable (input credit asset) or must be capitalized into inventory WAC.
 */
final class TaxCreditPolicy
{
    public static function isInputCreditEligible(?InventoryTax $tax, ?InventoryItem $item = null): bool
    {
        if ($tax !== null && $tax->is_input_credit_eligible !== null) {
            return (bool) $tax->is_input_credit_eligible;
        }

        $type = $tax?->type ?? $item?->tax?->type;

        return PurchaseOrderLineAmounts::resolveTaxType($type) !== 'vat';
    }

    public static function forInventoryItem(?InventoryItem $item): bool
    {
        if (! $item) {
            return true;
        }

        $item->loadMissing('tax');

        return self::isInputCreditEligible($item->tax, $item);
    }

    /**
     * @return array{recoverable: float, non_recoverable: float, unit_recoverable: float, unit_non_recoverable: float}
     */
    public static function splitLineTax(float $lineTaxAccepted, float $acceptedQty, bool $isInputCreditEligible): array
    {
        $accepted = max(0, $acceptedQty);
        $tax = round(max(0, $lineTaxAccepted), 2);

        if ($tax <= 0) {
            return [
                'recoverable' => 0.0,
                'non_recoverable' => 0.0,
                'unit_recoverable' => 0.0,
                'unit_non_recoverable' => 0.0,
            ];
        }

        if ($isInputCreditEligible) {
            return [
                'recoverable' => $tax,
                'non_recoverable' => 0.0,
                'unit_recoverable' => $accepted > 0 ? round($tax / $accepted, 4) : 0.0,
                'unit_non_recoverable' => 0.0,
            ];
        }

        return [
            'recoverable' => 0.0,
            'non_recoverable' => $tax,
            'unit_recoverable' => 0.0,
            'unit_non_recoverable' => $accepted > 0 ? round($tax / $accepted, 4) : 0.0,
        ];
    }
}
