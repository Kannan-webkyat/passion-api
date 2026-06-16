<?php

namespace App\Services;

use App\Models\InventoryItem;

class InventoryCostService
{
    /**
     * Update purchase cost_price using weighted average after a production batch is added.
     *
     * @param  float  $batchQty  Quantity added, in issue UOM
     * @param  float  $batchIssueUnitCost  Cost per issue UOM for this batch
     */
    public static function applyWeightedAverageCost(
        int $inventoryItemId,
        float $batchQty,
        float $batchIssueUnitCost
    ): void {
        if ($batchQty <= 0) {
            return;
        }

        $item = InventoryItem::find($inventoryItemId);
        if (! $item) {
            return;
        }

        $totalQty = InventoryItem::sumQuantityAcrossLocations($inventoryItemId);
        $existingQty = max(0, $totalQty - $batchQty);
        $conv = max(1.0, (float) ($item->conversion_factor ?? 1));

        $existingIssueUnitCost = ((float) ($item->cost_price ?? 0)) / $conv;
        $existingValue = $existingQty * $existingIssueUnitCost;
        $batchValue = $batchQty * $batchIssueUnitCost;

        $newIssueUnitCost = ($existingValue + $batchValue) / max($totalQty, $batchQty);
        $newPurchaseCost = $newIssueUnitCost * $conv;

        $item->update(['cost_price' => round($newPurchaseCost, 4)]);
    }
}
