<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\MenuItem;

/**
 * Liquor classification uses is_alcohol on inventory (and linked menu items).
 * Legacy VAT tax master tags are still honoured for old records without is_alcohol.
 */
final class LiquorItemClassifier
{
    public static function inventoryItemIsLiquor(?InventoryItem $item): bool
    {
        return $item !== null && (bool) $item->is_alcohol;
    }

    /** @deprecated Prefer is_alcohol; kept for unmigrated catalog rows. */
    public static function inventoryItemLegacyVatTagged(?InventoryItem $item): bool
    {
        if ($item === null) {
            return false;
        }

        return PurchaseOrderLineAmounts::resolveTaxType($item->tax?->type) === 'vat';
    }

    public static function inventoryItemIsLiquorOrLegacy(?InventoryItem $item): bool
    {
        return self::inventoryItemIsLiquor($item) || self::inventoryItemLegacyVatTagged($item);
    }

    public static function menuItemIsLiquor(MenuItem $menuItem): bool
    {
        $menuItem->loadMissing(['inventoryItem.tax', 'tax']);

        if (self::inventoryItemIsLiquor($menuItem->inventoryItem)) {
            return true;
        }

        if (self::inventoryItemLegacyVatTagged($menuItem->inventoryItem)) {
            return true;
        }

        return PurchaseOrderLineAmounts::resolveTaxType($menuItem->tax?->type) === 'vat';
    }

    /** PO line regime label — not a charged tax amount for Bevco / non_taxable lines. */
    public static function resolvePoLineTaxType(?InventoryItem $item): string
    {
        if (self::inventoryItemIsLiquorOrLegacy($item)) {
            return 'vat';
        }

        return PurchaseOrderLineAmounts::resolveTaxType($item?->tax?->type);
    }
}
