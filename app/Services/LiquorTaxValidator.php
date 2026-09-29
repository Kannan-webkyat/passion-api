<?php

namespace App\Services;

use App\Exceptions\LiquorTaxValidationException;
use App\Models\InventoryItem;
use Illuminate\Support\Collection;

/**
 * Ensures non-liquor items are not tagged with legacy VAT tax master records.
 * Liquor is identified by is_alcohol — no VAT tax assignment required.
 */
final class LiquorTaxValidator
{
    public const MSG_NON_LIQUOR_NO_VAT = 'Non-liquor items cannot use VAT. Please select a GST tax rate.';

    public static function validateLine(InventoryItem $item, string $taxType): void
    {
        $normalized = strtolower(trim($taxType));
        $isAlcohol = (bool) $item->is_alcohol;

        if ($isAlcohol) {
            return;
        }

        if ($normalized === 'vat') {
            throw new LiquorTaxValidationException(self::MSG_NON_LIQUOR_NO_VAT, $item->name);
        }
    }

    /** Validate item master tax_id — liquor items may omit tax; food must not use VAT tax master. */
    public static function validateItemMasterTax(bool $isAlcohol, ?string $inventoryTaxType, ?string $itemName = null): void
    {
        if ($isAlcohol) {
            return;
        }

        $item = new InventoryItem(['name' => $itemName ?? 'Item']);
        $item->is_alcohol = false;

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

            if ((bool) $item->is_alcohol) {
                continue;
            }

            $taxType = (string) ($line['tax_type'] ?? PurchaseOrderLineAmounts::resolveTaxType($item->tax?->type));
            self::validateLine($item, $taxType);
        }
    }
}
