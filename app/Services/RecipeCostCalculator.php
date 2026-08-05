<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Recipe;

/**
 * BOM-aware recipe cost using the same flattening rules as POS deduction.
 */
final class RecipeCostCalculator
{
    public function __construct(
        private readonly RecipeBomExpander $bomExpander,
    ) {}

    public function totalBatchCost(Recipe $recipe): float
    {
        $requirements = $this->bomExpander->flattenedRequirements($recipe, 1.0);

        if ($requirements === []) {
            return 0.0;
        }

        $items = InventoryItem::query()
            ->whereIn('id', array_keys($requirements))
            ->get()
            ->keyBy('id');

        $total = 0.0;
        foreach ($requirements as $itemId => $qty) {
            $item = $items->get($itemId);
            if (! $item) {
                continue;
            }
            $conv = (float) ($item->conversion_factor ?? 1);
            if ($conv <= 0) {
                $conv = 1.0;
            }
            $unitCost = (float) ($item->cost_price ?? 0) / $conv;
            $total += (float) $qty * $unitCost;
        }

        return round($total, 2);
    }

    public function costPerPortion(Recipe $recipe): float
    {
        $yield = max(0.001, (float) $recipe->yield_quantity);

        return round($this->totalBatchCost($recipe) / $yield, 2);
    }
}
