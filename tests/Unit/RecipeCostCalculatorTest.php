<?php

namespace Tests\Unit;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Services\BomDeductionConfig;
use App\Services\RecipeBomExpander;
use Illuminate\Support\Collection;
use Tests\TestCase;

class RecipeCostCalculatorTest extends TestCase
{
    protected function tearDown(): void
    {
        BomDeductionConfig::setModeForTesting(null);
        parent::tearDown();
    }

    public function test_calculator_uses_same_flattened_requirements_as_pos(): void
    {
        BomDeductionConfig::setModeForTesting(BomDeductionConfig::MODE_EXPAND_RAW);

        $expander = new RecipeBomExpander;
        $expander->seedPrepRecipeCacheForTesting(collect([
            10 => $this->semiFinishedRecipe(10, [
                ['inventory_item_id' => 101, 'quantity' => 100, 'yield_percentage' => 100],
            ], 100),
        ]));

        $menuRecipe = $this->menuRecipe([
            ['inventory_item_id' => 10, 'quantity' => 10, 'yield_percentage' => 100],
        ], yieldQuantity: 1);

        $this->assertSame([101 => 10.0], $expander->flattenedRequirements($menuRecipe, 1.0));
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function menuRecipe(array $lines, float $yieldQuantity = 1): Recipe
    {
        $recipe = new Recipe([
            'recipe_kind' => Recipe::KIND_MENU_ITEM,
            'yield_quantity' => $yieldQuantity,
            'is_active' => true,
        ]);
        $recipe->setRelation('ingredients', $this->ingredientLines($lines));

        return $recipe;
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function semiFinishedRecipe(int $outputId, array $lines, float $yieldQuantity): Recipe
    {
        $recipe = new Recipe([
            'recipe_kind' => Recipe::KIND_SEMI_FINISHED,
            'output_inventory_item_id' => $outputId,
            'yield_quantity' => $yieldQuantity,
            'is_active' => true,
        ]);
        $recipe->setRelation('ingredients', $this->ingredientLines($lines));

        return $recipe;
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function ingredientLines(array $lines): Collection
    {
        return collect($lines)->map(fn (array $line) => new RecipeIngredient([
            'inventory_item_id' => $line['inventory_item_id'],
            'quantity' => $line['quantity'],
            'yield_percentage' => $line['yield_percentage'],
        ]));
    }
}
