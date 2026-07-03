<?php

namespace Tests\Unit;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Services\BomDeductionConfig;
use App\Services\RecipeBomExpander;
use Illuminate\Support\Collection;
use Tests\TestCase;

class RecipeBomExpanderTest extends TestCase
{
    private RecipeBomExpander $expander;

    protected function setUp(): void
    {
        parent::setUp();
        $this->expander = new RecipeBomExpander;
    }

    protected function tearDown(): void
    {
        BomDeductionConfig::setModeForTesting(null);
        $this->expander->clearPrepRecipeCacheForTesting();
        parent::tearDown();
    }

    public function test_prep_stock_mode_keeps_semi_finished_line(): void
    {
        BomDeductionConfig::setModeForTesting(BomDeductionConfig::MODE_PREP_STOCK);

        $recipe = $this->menuRecipe([
            ['inventory_item_id' => 10, 'quantity' => 15, 'yield_percentage' => 100],
            ['inventory_item_id' => 20, 'quantity' => 60, 'yield_percentage' => 100],
        ]);

        $flat = $this->expander->flattenedRequirements($recipe, 2);

        $this->assertSame([
            10 => 30.0,
            20 => 120.0,
        ], $flat);
    }

    public function test_expand_raw_mode_expands_prep_item_to_raw_ingredients(): void
    {
        BomDeductionConfig::setModeForTesting(BomDeductionConfig::MODE_EXPAND_RAW);

        $syrupRecipe = $this->semiFinishedRecipe(10, [
            ['inventory_item_id' => 101, 'quantity' => 500, 'yield_percentage' => 100],
            ['inventory_item_id' => 102, 'quantity' => 500, 'yield_percentage' => 100],
        ], yieldQuantity: 1000);

        $this->expander->seedPrepRecipeCacheForTesting(collect([
            10 => $syrupRecipe,
        ]));

        $menuRecipe = $this->menuRecipe([
            ['inventory_item_id' => 10, 'quantity' => 15, 'yield_percentage' => 100],
            ['inventory_item_id' => 20, 'quantity' => 60, 'yield_percentage' => 100],
        ]);

        $flat = $this->expander->flattenedRequirements($menuRecipe, 1);

        $this->assertSame([
            101 => 7.5,
            102 => 7.5,
            20 => 60.0,
        ], $flat);
    }

    public function test_expand_raw_mode_scales_nested_quantities_with_multiplier(): void
    {
        BomDeductionConfig::setModeForTesting(BomDeductionConfig::MODE_EXPAND_RAW);

        $this->expander->seedPrepRecipeCacheForTesting(collect([
            10 => $this->semiFinishedRecipe(10, [
                ['inventory_item_id' => 101, 'quantity' => 100, 'yield_percentage' => 100],
            ], yieldQuantity: 100),
        ]));

        $menuRecipe = $this->menuRecipe([
            ['inventory_item_id' => 10, 'quantity' => 10, 'yield_percentage' => 100],
        ]);

        $flat = $this->expander->flattenedRequirements($menuRecipe, 3);

        $this->assertSame([
            101 => 30.0,
        ], $flat);
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
