<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Support\Collection;

/**
 * Flattens recipe ingredient lines for stock deduction, optionally expanding semi-finished prep BOMs.
 */
class RecipeBomExpander
{
    /** @var Collection<int, Recipe>|null */
    private ?Collection $prepRecipeCache = null;

    private bool $cacheOnlyMode = false;

    /**
     * @internal For unit tests only.
     *
     * @param  Collection<int, Recipe|null>  $prepRecipesByOutputId
     */
    public function seedPrepRecipeCacheForTesting(Collection $prepRecipesByOutputId): void
    {
        $this->prepRecipeCache = $prepRecipesByOutputId;
        $this->cacheOnlyMode = true;
    }

    public function clearPrepRecipeCacheForTesting(): void
    {
        $this->prepRecipeCache = null;
        $this->cacheOnlyMode = false;
    }

    /**
     * @return array<int, float> inventory_item_id => quantity to deduct
     */
    public function flattenedRequirements(Recipe $recipe, float $multiplier): array
    {
        $recipe->loadMissing('ingredients');

        if (! BomDeductionConfig::expandsNested()) {
            return $this->sumIngredientLines($recipe->ingredients, $multiplier, expandNested: false);
        }

        return $this->sumIngredientLines($recipe->ingredients, $multiplier, expandNested: true);
    }

    /**
     * @param  Collection<int, RecipeIngredient>  $ingredients
     * @return array<int, float>
     */
    private function sumIngredientLines(
        Collection $ingredients,
        float $multiplier,
        bool $expandNested,
        array $visitedOutputIds = []
    ): array {
        $out = [];

        if ($expandNested && $ingredients->isNotEmpty()) {
            $this->warmPrepRecipeCache(
                $ingredients->pluck('inventory_item_id')->map(fn ($id) => (int) $id)->all()
            );
        }

        foreach ($ingredients as $ing) {
            $needQty = (float) $ing->raw_quantity * $multiplier;
            if ($needQty <= 0) {
                continue;
            }

            $itemId = (int) $ing->inventory_item_id;

            if ($expandNested) {
                $subRecipe = $this->prepRecipeCache?->get($itemId);
                if ($subRecipe && ! isset($visitedOutputIds[$itemId])) {
                    $subRecipe->loadMissing('ingredients');
                    $subMultiplier = $needQty / max(0.001, (float) $subRecipe->yield_quantity);
                    $visitedOutputIds[$itemId] = true;
                    $nested = $this->sumIngredientLines(
                        $subRecipe->ingredients,
                        $subMultiplier,
                        true,
                        $visitedOutputIds
                    );
                    unset($visitedOutputIds[$itemId]);
                    foreach ($nested as $nestedId => $nestedQty) {
                        $out[$nestedId] = ($out[$nestedId] ?? 0) + $nestedQty;
                    }

                    continue;
                }
            }

            $out[$itemId] = ($out[$itemId] ?? 0) + $needQty;
        }

        return array_map(fn (float $qty) => round($qty, 3), $out);
    }

    /**
     * @param  array<int>  $outputItemIds
     */
    private function warmPrepRecipeCache(array $outputItemIds): void
    {
        $outputItemIds = array_values(array_unique(array_filter($outputItemIds)));
        if ($outputItemIds === []) {
            return;
        }

        if ($this->prepRecipeCache === null) {
            $this->prepRecipeCache = collect();
        }

        $missing = array_values(array_filter(
            $outputItemIds,
            fn (int $id) => ! $this->prepRecipeCache->has($id)
        ));

        if ($missing === []) {
            return;
        }

        if ($this->cacheOnlyMode) {
            foreach ($missing as $id) {
                $this->prepRecipeCache->put($id, null);
            }

            return;
        }

        $loaded = Recipe::query()
            ->where('recipe_kind', Recipe::KIND_SEMI_FINISHED)
            ->where('is_active', true)
            ->whereIn('output_inventory_item_id', $missing)
            ->with('ingredients')
            ->get()
            ->keyBy('output_inventory_item_id');

        foreach ($missing as $id) {
            $this->prepRecipeCache->put($id, $loaded->get($id));
        }
    }
}
