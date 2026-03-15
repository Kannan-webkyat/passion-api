<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\InventoryUom;
use App\Models\InventoryCategory;
use App\Models\InventoryTax;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Recipe;
use App\Models\RecipeIngredient;

class ChickenBiryaniSeeder extends Seeder
{
    public function run(): void
    {
        // ─── 0. Clear previous inventory & menu data ──────────────────────
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('recipe_ingredients')->truncate();
        DB::table('recipes')->truncate();
        DB::table('menu_items')->truncate();
        DB::table('menu_sub_categories')->truncate();
        DB::table('menu_categories')->truncate();
        DB::table('inventory_item_locations')->truncate();
        DB::table('inventory_transactions')->truncate();
        DB::table('grns')->truncate();
        DB::table('purchase_order_items')->truncate();
        DB::table('purchase_orders')->truncate();
        DB::table('production_logs')->truncate();
        DB::table('inventory_items')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // ─── 1. UOMs (keep or create) ─────────────────────────────────────
        $kg  = InventoryUom::firstOrCreate(['short_name' => 'Kg'],  ['name' => 'Kilogram']);
        $gm  = InventoryUom::firstOrCreate(['short_name' => 'Gm'],  ['name' => 'Gram']);
        $ltr = InventoryUom::firstOrCreate(['short_name' => 'Ltr'], ['name' => 'Litre']);
        $ml  = InventoryUom::firstOrCreate(['short_name' => 'Ml'],  ['name' => 'Millilitre']);
        $pcs = InventoryUom::firstOrCreate(['short_name' => 'Pcs'], ['name' => 'Piece']);

        // ─── 2. Inventory categories ──────────────────────────────────────
        $fb = InventoryCategory::firstOrCreate(
            ['name' => 'F&B', 'parent_id' => null],
            ['description' => 'Food & Beverage']
        );

        $catMeat  = InventoryCategory::firstOrCreate(['name' => 'Meat & Seafood', 'parent_id' => $fb->id], ['description' => 'Chicken, mutton, fish']);
        $catDry   = InventoryCategory::firstOrCreate(['name' => 'Dry Provisions',  'parent_id' => $fb->id], ['description' => 'Rice, flour, sugar, spices']);
        $catVeg   = InventoryCategory::firstOrCreate(['name' => 'Vegetables',       'parent_id' => $fb->id], ['description' => 'Fresh vegetables and herbs']);
        $catOils  = InventoryCategory::firstOrCreate(['name' => 'Oils & Fats',      'parent_id' => $fb->id], ['description' => 'Cooking oils and ghee']);
        $catDairy = InventoryCategory::firstOrCreate(['name' => 'Dairy & Eggs',     'parent_id' => $fb->id], ['description' => 'Milk, cream, curd, eggs']);

        // ─── 3. Tax ───────────────────────────────────────────────────────
        $gst5 = InventoryTax::where('rate', 5)->first();

        // ─── 4. Inventory items ───────────────────────────────────────────
        // [name, sku, category, purchase_uom, issue_uom, conv_factor, cost_per_pur_uom, reorder]
        $itemDefs = [
            // Meat
            ['Chicken (Bone-in)',    'FB-MT-CBN1', $catMeat,  $kg,  $gm,  1000, 220,   2],

            // Grains
            ['Basmati Rice',         'FB-DP-BR1K', $catDry,   $kg,  $gm,  1000, 90,    3],

            // Vegetables & Herbs
            ['Onion',                'FB-VG-ON1K', $catVeg,   $kg,  $gm,  1000, 30,    2],
            ['Tomato',               'FB-VG-TM1K', $catVeg,   $kg,  $gm,  1000, 25,    2],
            ['Mint Leaves',          'FB-VG-MNT1', $catVeg,   $kg,  $gm,  1000, 80,    1],
            ['Coriander Leaves',     'FB-VG-COR1', $catVeg,   $kg,  $gm,  1000, 60,    1],

            // Dairy
            ['Curd (Yogurt)',         'FB-DE-CRD1', $catDairy, $kg,  $gm,  1000, 65,    2],

            // Oils
            ['Desi Ghee',            'FB-OF-DG1K', $catOils,  $kg,  $gm,  1000, 500,   1],
            ['Sunflower Oil',        'FB-OF-SO1L', $catOils,  $ltr, $ml,  1000, 120,   2],

            // Spices & Dry Masalas
            ['Ginger-Garlic Paste',  'FB-DP-GGP1', $catDry,   $kg,  $gm,  1000, 80,    1],
            ['Biryani Masala',       'FB-DP-BRM1', $catDry,   $kg,  $gm,  1000, 400,   1],
            ['Red Chilli Powder',    'FB-DP-RCP1', $catDry,   $kg,  $gm,  1000, 200,   1],
            ['Turmeric Powder',      'FB-DP-TMP1', $catDry,   $kg,  $gm,  1000, 180,   1],
            ['Garam Masala',         'FB-DP-GMP1', $catDry,   $kg,  $gm,  1000, 320,   1],
            ['Salt',                 'FB-DP-SLT1', $catDry,   $kg,  $gm,  1000, 20,    1],
            ['Saffron',              'FB-DP-SAF1', $catDry,   $kg,  $gm,  1000, 50000, 1],
        ];

        $itemMap = [];
        foreach ($itemDefs as [$name, $sku, $cat, $purUom, $issUom, $conv, $cost, $reorder]) {
            $itemMap[$name] = InventoryItem::create([
                'name'              => $name,
                'sku'               => $sku,
                'category_id'       => $cat->id,
                'purchase_uom_id'   => $purUom->id,
                'issue_uom_id'      => $issUom->id,
                'conversion_factor' => $conv,
                'cost_price'        => $cost,
                'reorder_level'     => $reorder,
                'current_stock'     => $reorder * 10,
                'tax_id'            => $gst5?->id,
            ]);
        }

        // ─── 5. Stock quantities (exact raw needed for 5 portions) ──────────
        // net ÷ yield% × 5 portions
        $stock5Portions = [
            'Chicken (Bone-in)'   => 1334,  // 200g / 75% × 5
            'Basmati Rice'        => 600,   // 120g / 100% × 5
            'Onion'               => 471,   // 80g  / 85%  × 5
            'Tomato'              => 278,   // 50g  / 90%  × 5
            'Mint Leaves'         => 63,    // 10g  / 80%  × 5
            'Coriander Leaves'    => 63,    // 10g  / 80%  × 5
            'Curd (Yogurt)'       => 200,   // 40g  / 100% × 5
            'Desi Ghee'           => 50,    // 10g  / 100% × 5
            'Sunflower Oil'       => 75,    // 15ml / 100% × 5
            'Ginger-Garlic Paste' => 75,    // 15g  / 100% × 5
            'Biryani Masala'      => 25,    // 5g   / 100% × 5
            'Red Chilli Powder'   => 15,    // 3g   / 100% × 5
            'Turmeric Powder'     => 5,     // 1g   / 100% × 5
            'Garam Masala'        => 10,    // 2g   / 100% × 5
            'Salt'                => 15,    // 3g   / 100% × 5
            'Saffron'             => 1,     // 0.1g / 100% × 5 (rounded up)
        ];

        $locations = [
            'Kitchen Store' => $stock5Portions,
            'Main Store'    => $stock5Portions,
        ];

        foreach ($locations as $locationName => $stockMap) {
            $location = InventoryLocation::where('name', $locationName)->first();
            if (!$location) continue;
            foreach ($stockMap as $itemName => $qty) {
                if (!isset($itemMap[$itemName])) continue;
                DB::table('inventory_item_locations')->insert([
                    'inventory_item_id'     => $itemMap[$itemName]->id,
                    'inventory_location_id' => $location->id,
                    'quantity'              => $qty,
                    'reorder_level'         => 0,
                    'created_at'            => now(),
                    'updated_at'            => now(),
                ]);
            }
        }

        // ─── 6. Menu category ─────────────────────────────────────────────
        $biryaniCat = MenuCategory::create(['name' => 'Biryani', 'is_active' => true]);

        // ─── 7. Menu item ─────────────────────────────────────────────────
        $chickenBiryani = MenuItem::create([
            'item_code'          => 'MENU-CB-001',
            'name'               => 'Chicken Biryani',
            'menu_category_id'   => $biryaniCat->id,
            'menu_sub_category_id' => null,
            'price'              => 350.00,
            'fixed_ept'          => null,
            'type'               => 'non-veg',
            'is_active'          => true,
        ]);

        // ─── 8. Recipe (yields 1 portion) ────────────────────────────────
        $recipe = Recipe::create([
            'menu_item_id'      => $chickenBiryani->id,
            'yield_quantity'    => 1,
            'yield_uom_id'      => $pcs->id,
            'food_cost_target'  => 30.00,
            'notes'             => 'Classic Hyderabadi-style dum chicken biryani. Marinate chicken overnight for best results.',
            'is_active'         => true,
        ]);

        // ─── 9. Recipe ingredients ────────────────────────────────────────
        // [name, net_qty (in issue UOM), yield_pct, notes]
        $ingredients = [
            ['Chicken (Bone-in)',    200,  75,  'Clean, cut into medium pieces. 75% yield after cleaning.'],
            ['Basmati Rice',         120, 100,  'Soaked in water for 30 minutes before use.'],
            ['Onion',                 80,  85,  'Thinly sliced. Half fried for garnish, half for gravy.'],
            ['Tomato',                50,  90,  'Roughly chopped. Yield accounts for core waste.'],
            ['Ginger-Garlic Paste',   15, 100,  null],
            ['Curd (Yogurt)',          40, 100,  'Whisked smooth before adding to marinade.'],
            ['Desi Ghee',             10, 100,  'For dum cooking and final drizzle.'],
            ['Sunflower Oil',         15, 100,  'For frying onions.'],
            ['Biryani Masala',         5, 100,  null],
            ['Red Chilli Powder',      3, 100,  null],
            ['Turmeric Powder',        1, 100,  null],
            ['Garam Masala',           2, 100,  null],
            ['Salt',                   3, 100,  'To taste.'],
            ['Saffron',              0.1, 100,  'Dissolved in 2 tbsp warm milk before layering.'],
            ['Mint Leaves',           10,  80,  'Fresh. Yield accounts for stem removal.'],
            ['Coriander Leaves',      10,  80,  'Fresh, roughly chopped for garnish.'],
        ];

        foreach ($ingredients as [$name, $qty, $yieldPct, $notes]) {
            $item = $itemMap[$name];
            RecipeIngredient::create([
                'recipe_id'          => $recipe->id,
                'inventory_item_id'  => $item->id,
                'uom_id'             => $item->issue_uom_id,
                'quantity'           => $qty,
                'yield_percentage'   => $yieldPct,
                'notes'              => $notes,
            ]);
        }

        $this->command->info('Chicken Biryani seeder complete.');
        $this->command->info('  Inventory items : ' . count($itemMap));
        $this->command->info('  Menu item       : Chicken Biryani (₹350)');
        $this->command->info('  Recipe yields   : 1 portion | 16 ingredients');

        $totalCost = collect($ingredients)->map(function ($ing) use ($itemMap) {
            [$name, $qty, $yieldPct] = $ing;
            $item = $itemMap[$name];
            $rawQty = $qty / ($yieldPct / 100);
            $unitCost = $item->cost_price / $item->conversion_factor;
            return $rawQty * $unitCost;
        })->sum();

        $this->command->info(sprintf('  Raw material cost/plate : ₹%.2f (%.1f%% food cost at ₹350 selling price)',
            $totalCost, ($totalCost / 350) * 100));
    }
}
