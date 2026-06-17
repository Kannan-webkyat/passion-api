<?php

namespace App\Services;

use App\Models\Recipe;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

class RecipeBomValidator
{
    /**
     * Block listing a semi-finished item and its sub-recipe raw ingredients in the same BOM.
     *
     * @param  array<int>  $ingredientItemIds
     */
    public function validateNoDoubleCounting(array $ingredientItemIds): void
    {
        $ingredientItemIds = array_values(array_unique(array_map('intval', $ingredientItemIds)));

        if (count($ingredientItemIds) < 2) {
            return;
        }

        $prepRecipes = Recipe::query()
            ->where('recipe_kind', Recipe::KIND_SEMI_FINISHED)
            ->whereIn('output_inventory_item_id', $ingredientItemIds)
            ->where('is_active', true)
            ->with(['ingredients.inventoryItem', 'outputInventoryItem'])
            ->get()
            ->keyBy('output_inventory_item_id');

        if ($prepRecipes->isEmpty()) {
            return;
        }

        $ingredientSet = array_flip($ingredientItemIds);
        $conflicts = [];

        foreach ($prepRecipes as $outputItemId => $prepRecipe) {
            foreach ($prepRecipe->ingredients as $subIng) {
                $subId = (int) $subIng->inventory_item_id;
                if ($subId === (int) $outputItemId) {
                    continue;
                }
                if (isset($ingredientSet[$subId])) {
                    $conflicts[] = [
                        'semi_finished' => $prepRecipe->outputInventoryItem?->name ?? "Item #{$outputItemId}",
                        'duplicate_raw' => $subIng->inventoryItem?->name ?? "Item #{$subId}",
                    ];
                }
            }
        }

        if (empty($conflicts)) {
            return;
        }

        $lines = Collection::make($conflicts)
            ->unique(fn ($c) => $c['semi_finished'].'|'.$c['duplicate_raw'])
            ->map(fn ($c) => "\"{$c['duplicate_raw']}\" is already inside prep item \"{$c['semi_finished']}\" — remove one or the other.")
            ->values()
            ->all();

        throw new HttpResponseException(response()->json([
            'message' => 'BOM double-counting detected: a prep item and its raw ingredients cannot both appear in the same recipe.',
            'errors' => $lines,
        ], 422));
    }

    public function validateNoCircularReference(int $outputItemId, array $ingredientItemIds): void
    {
        if (in_array($outputItemId, $ingredientItemIds, true)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Recipe cannot include its own output item as an ingredient.',
            ], 422));
        }

        foreach ($ingredientItemIds as $ingredientId) {
            $subRecipe = Recipe::where('output_inventory_item_id', $ingredientId)
                ->where('recipe_kind', Recipe::KIND_SEMI_FINISHED)
                ->first();

            if ($subRecipe && $subRecipe->ingredients()->where('inventory_item_id', $outputItemId)->exists()) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Circular BOM reference detected between prep items.',
                ], 422));
            }
        }
    }
}
