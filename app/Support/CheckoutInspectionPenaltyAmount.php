<?php

namespace App\Support;

use App\Models\InventoryItem;

/**
 * Checkout inspection asset damage charge = item cost (issue UOM) + inspection_penalty_charge.
 * Legacy penalty_key / settings map is only used when no inventory item is linked.
 */
final class CheckoutInspectionPenaltyAmount
{
    public static function issueUnitCost(float $costPricePerPurchaseUnit, float $conversionFactor): float
    {
        $cf = $conversionFactor > 0 ? $conversionFactor : 1.0;

        return round($costPricePerPurchaseUnit / $cf, 2);
    }

    /**
     * @param  array<string, mixed>  $penalties  Decoded checkout_inspection_penalties JSON (legacy)
     * @return array{0: float, 1: ?string, 2: float, 3: float} [unit_total, label, item_cost, additional_penalty]
     */
    public static function resolveForAsset(?int $inventoryItemId, string $legacyPenKey, array $penalties): array
    {
        if ($inventoryItemId > 0) {
            /** @var InventoryItem|null $item */
            $item = InventoryItem::query()->find($inventoryItemId, [
                'id',
                'name',
                'cost_price',
                'conversion_factor',
                'inspection_penalty_charge',
            ]);
            if ($item) {
                $itemCost = self::issueUnitCost(
                    (float) ($item->cost_price ?? 0),
                    (float) ($item->conversion_factor ?? 1),
                );
                $additional = round(max(0.0, (float) ($item->inspection_penalty_charge ?? 0)), 2);
                $unit = round($itemCost + $additional, 2);

                return [$unit, (string) $item->name, $itemCost, $additional];
            }
        }

        [$unit, $mapLabel] = self::resolveLegacyMap($penalties, $legacyPenKey);

        return [$unit, $mapLabel, 0.0, $unit];
    }

    /**
     * @param  array<string, mixed>  $penalties
     * @return array{0: float, 1: ?string}
     */
    public static function resolve(array $penalties, string $penKey): array
    {
        [$unit, $label] = self::resolveLegacyMap($penalties, $penKey);

        return [$unit, $label];
    }

    /**
     * @param  array<string, mixed>  $penalties
     * @return array{0: float, 1: ?string}
     */
    private static function resolveLegacyMap(array $penalties, string $penKey): array
    {
        $penKey = trim($penKey);
        if ($penKey === '') {
            return [0.0, null];
        }

        $mapped = $penalties[$penKey] ?? null;
        if (is_array($mapped)) {
            $unit = round(max(0.0, (float) ($mapped['amount'] ?? 0)), 2);
            $lbl = isset($mapped['label']) ? trim((string) $mapped['label']) : '';

            return [$unit, $lbl !== '' ? $lbl : null];
        }

        if (preg_match('/^\d+(\.\d{1,4})?$/', $penKey)) {
            return [round(max(0.0, (float) $penKey), 2), null];
        }

        return [0.0, null];
    }
}
