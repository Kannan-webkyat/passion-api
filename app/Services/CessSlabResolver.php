<?php

namespace App\Services;

use App\Models\CessSlab;
use App\Models\InventoryItem;

/**
 * Resolves per-bottle flat cess for liquor PO lines (BEVCO-style slabs or item override).
 */
final class CessSlabResolver
{
    public static function resolveUnitCess(InventoryItem $item, float $lineUnitPrice): float
    {
        if (! $item->is_cess_applicable) {
            return 0.0;
        }

        if ($item->cess_amount !== null && $item->cess_amount !== '') {
            return round(max(0, (float) $item->cess_amount), 2);
        }

        // Slab band uses the PO line rate (per bottle); no price yet → no cess until rate is entered.
        $mrp = max(0, $lineUnitPrice);

        $category = strtolower(trim((string) ($item->liquor_category ?? '')));
        if ($category === '') {
            return 0.0;
        }

        $slab = CessSlab::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(item_category) = ?', [$category])
            ->where('min_mrp', '<=', $mrp)
            ->where('max_mrp', '>=', $mrp)
            ->orderBy('min_mrp')
            ->first();

        return $slab ? round((float) $slab->flat_cess_amount, 2) : 0.0;
    }
}
