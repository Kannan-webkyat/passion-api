<?php

namespace App\Services;

use App\Exceptions\LiquorTaxValidationException;
use App\Models\InventoryItem;
use Illuminate\Support\Collection;

/**
 * Enforces alcohol ↔ VAT and non-alcohol ↔ GST mapping on PO lines.
 */
final class LiquorTaxValidator
{
    public const MSG_LIQUOR_REQUIRES_VAT = 'Liquor items must use VAT. Please select a VAT tax rate.';

    public const MSG_NON_LIQUOR_NO_VAT = 'Non-liquor items cannot use VAT. Please select a GST tax rate.';

    public static function validateLine(InventoryItem $item, string $taxType): void
    {
        $normalized = strtolower(trim($taxType));
        $isAlcohol = (bool) $item->is_alcohol;

        if ($isAlcohol && $normalized !== 'vat') {
            throw new LiquorTaxValidationException(self::MSG_LIQUOR_REQUIRES_VAT, $item->name);
        }

        if (! $isAlcohol && $normalized === 'vat') {
            throw new LiquorTaxValidationException(self::MSG_NON_LIQUOR_NO_VAT, $item->name);
        }
    }

    /** Validate item master tax_id against is_alcohol before save. */
    public static function validateItemMasterTax(bool $isAlcohol, ?string $inventoryTaxType, ?string $itemName = null): void
    {
        $item = new InventoryItem(['name' => $itemName ?? 'Item']);
        $item->is_alcohol = $isAlcohol;

        self::validateLine($item, PurchaseOrderLineAmounts::resolveTaxType($inventoryTaxType));
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines  Must include inventory_item_id and tax_type
     * @param  Collection<int, InventoryItem>|null  $inventoryById
     */
    public static function validatePoLines(array $lines, ?Collection $inventoryById = null): void
    {
        if ($inventoryById === null) {
            $ids = array_values(array_unique(array_filter(array_map(
                fn ($line) => (int) ($line['inventory_item_id'] ?? 0),
                $lines
            ))));
            $inventoryById = InventoryItem::query()
                ->with('tax')
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id');
        }

        foreach ($lines as $line) {
            $itemId = (int) ($line['inventory_item_id'] ?? 0);
            /** @var InventoryItem|null $item */
            $item = $inventoryById->get($itemId);
            if (! $item) {
                continue;
            }

            $taxType = (string) ($line['tax_type'] ?? PurchaseOrderLineAmounts::resolveTaxType($item->tax?->type));
            self::validateLine($item, $taxType);
        }
    }
}
