<?php

namespace App\Console\Commands;

use App\Models\InventoryLocation;
use App\Models\MenuItem;
use App\Models\ProductionLog;
use App\Models\Recipe;
use Illuminate\Console\Command;

class AddChickenBiryaniStock extends Command
{
    protected $signature = 'pos:add-chicken-biryani-stock {quantity=10 : Number of portions to add}';

    protected $description = 'Add production stock for Chicken Biryani (bypasses ingredient deduction)';

    public function handle(): int
    {
        $quantity = (float) $this->argument('quantity');
        if ($quantity <= 0) {
            $this->error('Quantity must be positive.');

            return 1;
        }

        $item = MenuItem::where('name', 'Chicken Biryani')->first();
        if (! $item) {
            $this->error('Chicken Biryani menu item not found.');

            return 1;
        }

        $recipe = Recipe::where('menu_item_id', $item->id)->first();
        if (! $recipe) {
            $this->error('Chicken Biryani has no recipe.');

            return 1;
        }

        $location = InventoryLocation::where('name', 'like', '%Kitchen%')->first()
            ?? InventoryLocation::first();
        if (! $location) {
            $this->error('No inventory location found.');

            return 1;
        }

        ProductionLog::create([
            'recipe_id' => $recipe->id,
            'inventory_location_id' => $location->id,
            'quantity_produced' => $quantity,
            'unit_cost' => null,
            'total_cost' => null,
            'produced_by' => null,
            'production_date' => now(),
            'notes' => 'Manual stock add via artisan',
            'reference_id' => null,
        ]);

        $this->info("Added {$quantity} portions of Chicken Biryani to kitchen stock.");

        return 0;
    }
}
