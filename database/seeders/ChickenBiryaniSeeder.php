<?php

namespace Database\Seeders;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTax;
use App\Models\InventoryTransaction;
use App\Models\InventoryUom;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RestaurantMaster;
use App\Models\RestaurantMenuItem;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Chicken Biryani with semi-finished Chicken Biryani Masala (1 kg batch).
 *
 * Requires: LocationSeeder, InventoryUomSeeder, InventoryTaxSeeder.
 * Works best after RestaurantInventoryCatalogSeeder (reuses RST-* items where present).
 *
 * Seeds opening stock at Main Store + Kitchen for all BOM ingredients.
 */
class ChickenBiryaniSeeder extends Seeder
{
    private const MENU_CODE = 'MENU-RCE-CHICKEN_BIRYANI';

    private const MASALA_SKU = 'RST-SPI-CHICKEN_BIRYANI_MASALA';

    private const PORTION_SKU = 'RST-DRG-CHICKEN_BIRYANI_PORTION';

    /** Portions produced per 1 kg chicken batch. */
    private const BATCH_YIELD_PORTIONS = 10;

    /** @var array<string, InventoryItem> */
    private array $items = [];

    public function run(): void
    {
        $mainStore = InventoryLocation::where('name', 'Main Store')->first();
        $kitchen = InventoryLocation::where('name', 'Kitchen')->first();

        if (! $mainStore || ! $kitchen) {
            $this->command?->error('Main Store and Kitchen locations required. Run LocationSeeder first.');

            return;
        }

        $this->wireOttaalKitchen($kitchen);

        $gst5 = InventoryTax::where('name', 'GST 5% (Local)')->first()
            ?? InventoryTax::firstOrCreate(['name' => 'GST 5% (Local)'], ['rate' => 5, 'type' => 'local']);

        $vendor = Vendor::firstOrCreate(
            ['name' => 'Restaurant Supplies'],
            [
                'contact_person' => 'Store Manager',
                'phone' => null,
                'email' => null,
                'address' => 'Local wholesale market',
                'is_liquor_supplier' => false,
            ]
        );

        $this->bootstrapInventory($vendor, $gst5);

        $masalaItem = $this->items['Chicken Biryani Masala'];
        $portionItem = $this->items['Chicken Biryani (Portion)'];

        $this->seedMasalaRecipe($masalaItem);
        $menuItem = $this->seedMenuItem($portionItem, $gst5);
        $this->seedBiryaniRecipe($menuItem, $portionItem, $masalaItem);

        $this->seedStock($mainStore, $kitchen, $masalaItem);

        $this->command?->info('Chicken Biryani seeder complete.');
        $this->command?->info('  Semi-finished: Chicken Biryani Masala (1 kg batch)');
        $this->command?->info('  Menu item: Chicken Biryani · yield '.self::BATCH_YIELD_PORTIONS.' portions / 1 kg chicken batch');
        $this->command?->info('  Stock: Main Store + Kitchen (raw + 1.5 kg pre-made masala at kitchen)');
        $this->command?->info('  Outlet: OTTAAL · produce masala first, then biryani batch in Kitchen Production');
    }

    private function wireOttaalKitchen(InventoryLocation $kitchen): void
    {
        RestaurantMaster::query()
            ->where('name', 'OTTAAL')
            ->where('is_active', true)
            ->each(function (RestaurantMaster $outlet) use ($kitchen) {
                if ((int) ($outlet->kitchen_location_id ?? 0) !== (int) $kitchen->id) {
                    $outlet->update(['kitchen_location_id' => $kitchen->id]);
                }
            });
    }

    private function bootstrapInventory(Vendor $vendor, InventoryTax $gst5): void
    {
        $freshProduce = $this->mainCategory('Fresh Produce');
        $dryGoods = $this->mainCategory('Dry Goods');
        $spicesMain = $this->mainCategory('Spices');

        $spices = $this->subCategory($spicesMain, 'Spices');
        $dry = $this->subCategory($dryGoods, 'Rice');
        $produce = $this->subCategory($freshProduce, 'Vegetables');
        $greens = $this->subCategory($freshProduce, 'Greens');
        $meat = $this->mainCategory('Meat & Fish');
        $meatSub = $this->subCategory($meat, 'Poultry');
        $dairyMain = $this->mainCategory('Dairy & Oils');
        $dairy = $this->subCategory($dairyMain, 'Dairy');
        $oils = $this->subCategory($dairyMain, 'Oils');
        $condiments = $this->mainCategory('Condiments');
        $paste = $this->subCategory($condiments, 'Paste & Puree');
        $prepared = $this->subCategory($dryGoods, 'Prepared Items');

        $defs = [
            // Masala raw spices
            ['Coriander powder', $spices, 'Grams', 'Grams', 1, 0.35, 500, false],
            ['Kashmiri chilli powder', $spices, 'Grams', 'Grams', 1, 0.45, 250, false],
            ['Chilli powder', $spices, 'Grams', 'Grams', 1, 0.40, 200, false],
            ['Turmeric powder', $spices, 'Grams', 'Grams', 1, 0.30, 200, false],
            ['Fennel powder', $spices, 'Grams', 'Grams', 1, 0.50, 150, false],
            ['Garam masala', $spices, 'Grams', 'Grams', 1, 0.55, 150, false],
            ['Black pepper powder', $spices, 'Grams', 'Grams', 1, 0.60, 100, false],
            ['Cumin powder', $spices, 'Grams', 'Grams', 1, 0.40, 100, false],
            ['Shahi jeera powder', $spices, 'Grams', 'Grams', 1, 0.80, 50, false],
            ['Nutmeg powder', $spices, 'Grams', 'Grams', 1, 2.50, 25, false],
            ['Mace powder', $spices, 'Grams', 'Grams', 1, 3.00, 25, false],
            // Biryani batch ingredients
            ['Chicken', $meatSub, 'Kg', 'Grams', 1000, 220, 5, false],
            ['Jeerakasala rice', $dry, 'Kg', 'Grams', 1000, 95, 10, false],
            ['Onion', $produce, 'Kg', 'Grams', 1000, 30, 5, false],
            ['Tomato', $produce, 'Kg', 'Grams', 1000, 25, 5, false],
            ['Ginger-garlic paste', $paste, 'Kg', 'Grams', 1000, 80, 2, false],
            ['Green chilli', $produce, 'Kg', 'Grams', 1000, 60, 2, false],
            ['Curd', $dairy, 'Kg', 'Grams', 1000, 65, 3, false],
            ['Mint leaves', $greens, 'Kg', 'Grams', 1000, 80, 2, false],
            ['Coriander leaves', $greens, 'Kg', 'Grams', 1000, 60, 2, false],
            ['Ghee', $oils, 'Kg', 'Grams', 1000, 500, 1, false],
            ['Oil', $oils, 'Liter', 'Ml', 1000, 120, 2, false],
            ['Lemon', $produce, 'Kg', 'Pcs', 12, 5, 2, false],
            ['Salt', $spices, 'Kg', 'Grams', 1000, 20, 2, false],
            // Prepared outputs
            ['Chicken Biryani Masala', $spices, 'Kg', 'Grams', 1000, 350, 1, true],
            ['Chicken Biryani (Portion)', $prepared, 'Pcs', 'Pcs', 1, 120, 0, true],
        ];

        foreach ($defs as [$name, $category, $purchaseUom, $issueUom, $conv, $cost, $reorder, $prepared]) {
            $this->items[$name] = $this->findOrCreateItem(
                $name,
                $category,
                $vendor,
                $gst5,
                $purchaseUom,
                $issueUom,
                (float) $conv,
                (float) $cost,
                (float) $reorder,
                (bool) $prepared
            );
        }
    }

    private function seedMasalaRecipe(InventoryItem $masalaItem): void
    {
        $gm = $this->uom('Grams');

        $recipe = Recipe::updateOrCreate(
            [
                'output_inventory_item_id' => $masalaItem->id,
                'recipe_kind' => Recipe::KIND_SEMI_FINISHED,
            ],
            [
                'menu_item_id' => null,
                'yield_quantity' => 1000,
                'yield_uom_id' => $gm->id,
                'food_cost_target' => 25,
                'notes' => 'Chicken Biryani Masala — 1 kg dry spice blend batch',
                'is_active' => true,
                'requires_production' => true,
            ]
        );

        RecipeIngredient::where('recipe_id', $recipe->id)->delete();

        foreach ($this->masalaIngredients() as [$name, $qty]) {
            $item = $this->items[$name];
            RecipeIngredient::create([
                'recipe_id' => $recipe->id,
                'inventory_item_id' => $item->id,
                'uom_id' => $item->issue_uom_id,
                'quantity' => $qty,
                'yield_percentage' => 100,
            ]);
        }
    }

    private function seedMenuItem(InventoryItem $portionItem, InventoryTax $gst5): MenuItem
    {
        $category = MenuCategory::firstOrCreate(
            ['name' => 'RICE ITEMS'],
            ['is_active' => true]
        );

        $menuItem = MenuItem::updateOrCreate(
            ['item_code' => self::MENU_CODE],
            [
                'name' => 'Chicken Biryani',
                'menu_category_id' => $category->id,
                'menu_sub_category_id' => null,
                'price' => 450,
                'tax_id' => $gst5->id,
                'fixed_ept' => 0,
                'type' => 'non-veg',
                'is_active' => true,
                'is_direct_sale' => false,
                'requires_production' => true,
                'inventory_item_id' => $portionItem->id,
            ]
        );

        $outlet = RestaurantMaster::where('name', 'OTTAAL')->where('is_active', true)->first();
        if ($outlet) {
            RestaurantMenuItem::updateOrCreate(
                [
                    'menu_item_id' => $menuItem->id,
                    'restaurant_master_id' => $outlet->id,
                ],
                [
                    'price' => 450,
                    'fixed_ept' => 0,
                    'is_active' => true,
                    'price_tax_inclusive' => true,
                ]
            );
        }

        return $menuItem;
    }

    private function seedBiryaniRecipe(MenuItem $menuItem, InventoryItem $portionItem, InventoryItem $masalaItem): void
    {
        $pcs = $this->uom('Pcs');

        $recipe = Recipe::updateOrCreate(
            ['menu_item_id' => $menuItem->id],
            [
                'output_inventory_item_id' => $portionItem->id,
                'recipe_kind' => Recipe::KIND_MENU_ITEM,
                'yield_quantity' => self::BATCH_YIELD_PORTIONS,
                'yield_uom_id' => $pcs->id,
                'food_cost_target' => 32,
                'notes' => 'Per 1 kg chicken batch — yields '.self::BATCH_YIELD_PORTIONS.' portions',
                'is_active' => true,
                'requires_production' => true,
            ]
        );

        RecipeIngredient::where('recipe_id', $recipe->id)->delete();

        foreach ($this->biryaniIngredients($masalaItem) as [$name, $qty, $yield]) {
            $item = $this->items[$name];
            RecipeIngredient::create([
                'recipe_id' => $recipe->id,
                'inventory_item_id' => $item->id,
                'uom_id' => $item->issue_uom_id,
                'quantity' => $qty,
                'yield_percentage' => $yield,
            ]);
        }
    }

    /** @return array<int, array{0: string, 1: float}> */
    private function masalaIngredients(): array
    {
        return [
            ['Coriander powder', 500],
            ['Kashmiri chilli powder', 250],
            ['Chilli powder', 100],
            ['Turmeric powder', 40],
            ['Fennel powder', 80],
            ['Garam masala', 120],
            ['Black pepper powder', 30],
            ['Cumin powder', 50],
            ['Shahi jeera powder', 30],
            ['Nutmeg powder', 5],
            ['Mace powder', 10],
        ];
    }

    /**
     * @return array<int, array{0: string, 1: float, 2: float}>
     */
    private function biryaniIngredients(InventoryItem $masalaItem): array
    {
        $this->items['Chicken Biryani Masala'] = $masalaItem;

        return [
            ['Chicken', 1000, 75],
            ['Jeerakasala rice', 1000, 100],
            ['Chicken Biryani Masala', 90, 100],
            ['Onion', 500, 85],
            ['Tomato', 200, 90],
            ['Ginger-garlic paste', 120, 100],
            ['Green chilli', 50, 95],
            ['Curd', 150, 100],
            ['Mint leaves', 80, 80],
            ['Coriander leaves', 80, 80],
            ['Ghee', 80, 100],
            ['Oil', 120, 100],
            ['Lemon', 1, 100],
            ['Salt', 38, 100],
        ];
    }

    private function seedStock(
        InventoryLocation $mainStore,
        InventoryLocation $kitchen,
        InventoryItem $masalaItem
    ): void {
        $kitchenOnly = ['Chicken Biryani Masala', 'Chicken Biryani (Portion)'];

        foreach ($this->items as $name => $item) {
            if (in_array($name, $kitchenOnly, true)) {
                continue;
            }

            $mainQty = $this->mainStoreQty($name);
            $kitchenQty = $this->kitchenQty($name);

            $this->seedLocationQty($item, $mainStore, $mainQty, 'Opening Stock (Chicken Biryani seeder)');
            $this->seedLocationQty($item, $kitchen, $kitchenQty, 'Kitchen transfer (Chicken Biryani seeder)');
        }

        // Pre-made masala at kitchen so biryani production can run without making masala first.
        $this->seedLocationQty($masalaItem, $kitchen, 1500, 'Pre-made masala (Chicken Biryani seeder)', force: true);
    }

    private function mainStoreQty(string $name): float
    {
        return match ($name) {
            'Coriander powder', 'Kashmiri chilli powder' => 10000,
            'Chilli powder', 'Turmeric powder', 'Fennel powder', 'Garam masala' => 5000,
            'Black pepper powder', 'Cumin powder', 'Shahi jeera powder' => 3000,
            'Nutmeg powder', 'Mace powder' => 500,
            'Chicken', 'Jeerakasala rice', 'Onion' => 20000,
            'Tomato', 'Ginger-garlic paste', 'Green chilli', 'Curd' => 10000,
            'Mint leaves', 'Coriander leaves' => 4000,
            'Ghee' => 8000,
            'Oil' => 20000,
            'Lemon' => 120,
            'Salt' => 10000,
            default => 5000,
        };
    }

    private function kitchenQty(string $name): float
    {
        return match ($name) {
            'Coriander powder', 'Kashmiri chilli powder' => 4000,
            'Chilli powder', 'Turmeric powder', 'Fennel powder', 'Garam masala' => 2500,
            'Black pepper powder', 'Cumin powder', 'Shahi jeera powder' => 1500,
            'Nutmeg powder', 'Mace powder' => 250,
            'Chicken' => 8000,
            'Jeerakasala rice' => 10000,
            'Onion' => 6000,
            'Tomato', 'Ginger-garlic paste', 'Green chilli', 'Curd' => 4000,
            'Mint leaves', 'Coriander leaves' => 1500,
            'Ghee' => 3000,
            'Oil' => 8000,
            'Lemon' => 48,
            'Salt' => 4000,
            default => 2000,
        };
    }

    private function seedLocationQty(
        InventoryItem $item,
        InventoryLocation $location,
        float $quantity,
        string $reason,
        bool $force = false
    ): void {
        if ($quantity <= 0) {
            return;
        }

        $existing = DB::table('inventory_item_locations')
            ->where('inventory_item_id', $item->id)
            ->where('inventory_location_id', $location->id)
            ->first();

        if (! $force && $existing && (float) $existing->quantity > 0) {
            return;
        }

        DB::table('inventory_item_locations')->updateOrInsert(
            [
                'inventory_item_id' => $item->id,
                'inventory_location_id' => $location->id,
            ],
            [
                'quantity' => $quantity,
                'reorder_level' => $item->reorder_level ?? 0,
                'updated_at' => now(),
                'created_at' => $existing->created_at ?? now(),
            ]
        );

        InventoryTransaction::create([
            'inventory_item_id' => $item->id,
            'inventory_location_id' => $location->id,
            'type' => 'in',
            'quantity' => $quantity,
            'unit_cost' => (float) ($item->cost_price ?? 0),
            'total_cost' => round($quantity * (float) ($item->cost_price ?? 0), 2),
            'reason' => $reason,
            'notes' => "Seeded into {$location->name}.",
            'user_id' => null,
        ]);

        InventoryItem::syncStoredCurrentStockFromLocations($item->id);
    }

    private function findOrCreateItem(
        string $name,
        InventoryCategory $category,
        Vendor $vendor,
        InventoryTax $tax,
        string $purchaseUom,
        string $issueUom,
        float $conversionFactor,
        float $costPrice,
        float $reorderLevel,
        bool $isPrepared
    ): InventoryItem {
        $existing = InventoryItem::where('name', $name)->first();
        if ($existing) {
            if ($isPrepared && ! $existing->is_prepared_item) {
                $existing->update(['is_prepared_item' => true]);
            }

            return $existing;
        }

        $sku = match ($name) {
            'Chicken Biryani Masala' => self::MASALA_SKU,
            'Chicken Biryani (Portion)' => self::PORTION_SKU,
            default => $this->catalogSku($name),
        };

        return InventoryItem::create([
            'name' => $name,
            'sku' => $sku,
            'category_id' => $category->id,
            'vendor_id' => $vendor->id,
            'purchase_uom_id' => $this->uom($purchaseUom)->id,
            'issue_uom_id' => $this->uom($issueUom)->id,
            'conversion_factor' => $conversionFactor,
            'cost_price' => $costPrice,
            'reorder_level' => $reorderLevel,
            'current_stock' => 0,
            'tax_id' => $tax->id,
            'is_direct_sale' => false,
            'is_prepared_item' => $isPrepared,
            'is_alcohol' => false,
        ]);
    }

    private function catalogSku(string $item): string
    {
        $slug = Str::upper(Str::slug(Str::limit($item, 32, ''), '_'));

        return 'RST-SEED-'.$slug;
    }

    private function mainCategory(string $name): InventoryCategory
    {
        return InventoryCategory::firstOrCreate(
            ['name' => $name],
            ['parent_id' => null, 'description' => "{$name} — restaurant inventory"]
        );
    }

    private function subCategory(InventoryCategory $main, string $sub): InventoryCategory
    {
        $subName = $sub === $main->name ? "{$main->name} Items" : $sub;

        return InventoryCategory::firstOrCreate(
            ['name' => $subName, 'parent_id' => $main->id],
            ['description' => "{$sub} — {$main->name}"]
        );
    }

    private function uom(string $label): InventoryUom
    {
        $aliases = match ($label) {
            'Kg' => ['KG', 'Kilogram'],
            'Grams' => ['GM', 'Gram'],
            'Liter' => ['LTR', 'Litre'],
            'Ml' => ['ML', 'Millilitre'],
            'Pcs' => ['PCS', 'Piece'],
            default => [strtoupper($label), $label],
        };

        $uom = InventoryUom::where('short_name', $aliases[0])->first()
            ?? InventoryUom::where('name', $aliases[1])->first()
            ?? InventoryUom::whereRaw('LOWER(short_name) = ?', [strtolower($aliases[0])])->first();

        if ($uom) {
            return $uom;
        }

        return InventoryUom::create([
            'short_name' => $aliases[0],
            'name' => $aliases[1],
        ]);
    }
}
