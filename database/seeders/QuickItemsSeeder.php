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

class QuickItemsSeeder extends Seeder
{
    public function run(): void
    {
        // ─── 1. UOMs ─────────────────────────────────────────────────────────
        $kg  = InventoryUom::firstOrCreate(['short_name' => 'Kg'],  ['name' => 'Kilogram']);
        $gm  = InventoryUom::firstOrCreate(['short_name' => 'Gm'],  ['name' => 'Gram']);
        $ltr = InventoryUom::firstOrCreate(['short_name' => 'Ltr'], ['name' => 'Litre']);
        $ml  = InventoryUom::firstOrCreate(['short_name' => 'Ml'],  ['name' => 'Millilitre']);
        $pcs = InventoryUom::firstOrCreate(['short_name' => 'Pcs'], ['name' => 'Piece']);

        // ─── 2. Inventory categories ─────────────────────────────────────────
        $fb = InventoryCategory::firstOrCreate(
            ['name' => 'F&B', 'parent_id' => null],
            ['description' => 'Food & Beverage']
        );
        $catDry   = InventoryCategory::firstOrCreate(['name' => 'Dry Provisions', 'parent_id' => $fb->id], ['description' => 'Rice, flour, sugar, spices']);
        $catDairy = InventoryCategory::firstOrCreate(['name' => 'Dairy & Eggs',   'parent_id' => $fb->id], ['description' => 'Milk, cream, curd, eggs']);
        $catOils  = InventoryCategory::firstOrCreate(['name' => 'Oils & Fats',    'parent_id' => $fb->id], ['description' => 'Cooking oils and ghee']);
        $catVeg   = InventoryCategory::firstOrCreate(['name' => 'Vegetables',     'parent_id' => $fb->id], ['description' => 'Fresh vegetables and herbs']);

        // ─── 3. Tax ──────────────────────────────────────────────────────────
        $gst5 = InventoryTax::where('rate', 5)->first();

        // ─── 4. New inventory items ───────────────────────────────────────────
        // Items shared with existing recipes use firstOrCreate to avoid duplicates.
        // [name, sku, category, purchase_uom, issue_uom, conv_factor, cost_per_pur_uom, reorder]
        $newItemDefs = [
            // Beverages
            ['Tea Leaves',       'FB-DP-TL100', $catDry,   $kg,  $gm,  1000,  300,  1],
            ['Coffee Powder',    'FB-DP-CP100', $catDry,   $kg,  $gm,  1000,  600,  1],
            ['Milk',             'FB-DE-MLK1L', $catDairy, $ltr, $ml,  1000,   60,  5],
            ['Sugar',            'FB-DP-SGR1K', $catDry,   $kg,  $gm,  1000,   45,  2],

            // Egg items
            ['Eggs',             'FB-DE-EGG12', $catDairy, $pcs, $pcs,    1,    8,  2],
            ['Butter',           'FB-OF-BTR500',$catOils,  $kg,  $gm,  1000, 480,  1],
            ['Green Chilli',     'FB-VG-GCH1K', $catVeg,   $kg,  $gm,  1000,  60,  1],
        ];

        $itemMap = [];
        foreach ($newItemDefs as [$name, $sku, $cat, $purUom, $issUom, $conv, $cost, $reorder]) {
            $itemMap[$name] = InventoryItem::firstOrCreate(
                ['name' => $name],
                [
                    'sku'               => $sku,
                    'category_id'       => $cat->id,
                    'purchase_uom_id'   => $purUom->id,
                    'issue_uom_id'      => $issUom->id,
                    'conversion_factor' => $conv,
                    'cost_price'        => $cost,
                    'reorder_level'     => $reorder,
                    'current_stock'     => 0,
                    'tax_id'            => $gst5?->id,
                ]
            );
        }

        // Also pull in existing shared items (Onion, Salt) for omelette recipe
        $itemMap['Onion'] = InventoryItem::where('name', 'Onion')->first();
        $itemMap['Salt']  = InventoryItem::where('name', 'Salt')->first();

        // ─── 5. Stock — 30 portions of each item in both stores ──────────────
        // Tea (30 cups): 3g leaves, 100ml milk, 10g sugar
        // Coffee (30 cups): 5g powder, 100ml milk, 10g sugar
        // Omelette (30 plates): 2 eggs, 10g butter, 20g onion, 5g green chilli, 2g salt
        // Milk shared between tea (30×100=3000ml) + coffee (30×100=3000ml) = 6000ml total
        $stockQty = [
            'Tea Leaves'    => 90,    // 30 × 3g
            'Coffee Powder' => 150,   // 30 × 5g
            'Milk'          => 6000,  // 30 × 100ml tea + 30 × 100ml coffee
            'Sugar'         => 600,   // 30 × 10g tea + 30 × 10g coffee
            'Eggs'          => 60,    // 30 × 2 pcs (pcs, conv=1 so qty=60)
            'Butter'        => 300,   // 30 × 10g
            'Green Chilli'  => 150,   // 30 × 5g
        ];

        $locationNames = ['Kitchen Store', 'Main Store'];

        foreach ($locationNames as $locationName) {
            $location = InventoryLocation::where('name', $locationName)->first();
            if (!$location) continue;

            foreach ($stockQty as $itemName => $qty) {
                if (empty($itemMap[$itemName])) continue;
                $item = $itemMap[$itemName];

                $existing = DB::table('inventory_item_locations')
                    ->where('inventory_item_id', $item->id)
                    ->where('inventory_location_id', $location->id)
                    ->first();

                if ($existing) {
                    DB::table('inventory_item_locations')
                        ->where('id', $existing->id)
                        ->update(['quantity' => $existing->quantity + $qty, 'updated_at' => now()]);
                } else {
                    DB::table('inventory_item_locations')->insert([
                        'inventory_item_id'     => $item->id,
                        'inventory_location_id' => $location->id,
                        'quantity'              => $qty,
                        'reorder_level'         => 0,
                        'created_at'            => now(),
                        'updated_at'            => now(),
                    ]);
                }
            }
        }

        // ─── 6. Menu categories ───────────────────────────────────────────────
        $catBeverages = MenuCategory::firstOrCreate(['name' => 'Beverages'], ['is_active' => true]);
        $catBreakfast = MenuCategory::firstOrCreate(['name' => 'Breakfast'],  ['is_active' => true]);

        // ─── 7. Menu items ────────────────────────────────────────────────────
        $menuTea = MenuItem::firstOrCreate(
            ['item_code' => 'MENU-BV-TEA'],
            [
                'name'               => 'Tea',
                'menu_category_id'   => $catBeverages->id,
                'price'              => 30.00,
                'type'               => 'veg',
                'is_active'          => true,
            ]
        );

        $menuCoffee = MenuItem::firstOrCreate(
            ['item_code' => 'MENU-BV-CFE'],
            [
                'name'               => 'Coffee',
                'menu_category_id'   => $catBeverages->id,
                'price'              => 40.00,
                'type'               => 'veg',
                'is_active'          => true,
            ]
        );

        $menuOmelette = MenuItem::firstOrCreate(
            ['item_code' => 'MENU-BK-OML'],
            [
                'name'               => 'Omelette',
                'menu_category_id'   => $catBreakfast->id,
                'price'              => 80.00,
                'type'               => 'non-veg',
                'is_active'          => true,
            ]
        );

        // ─── 8. Recipes & ingredients ─────────────────────────────────────────

        // ── Tea (1 cup) ──
        if (!Recipe::where('menu_item_id', $menuTea->id)->exists()) {
            $teaRecipe = Recipe::create([
                'menu_item_id'       => $menuTea->id,
                'yield_quantity'     => 1,
                'yield_uom_id'       => $pcs->id,
                'food_cost_target'   => 40.00,
                'notes'              => 'Standard cup of milk tea. Adjust sugar to taste.',
                'is_active'          => true,
                'requires_production'=> false,
            ]);
            foreach ([
                [$itemMap['Tea Leaves'], 3,   100, 'Loose leaf or equivalent tea bags.'],
                [$itemMap['Milk'],       100, 100, '100ml full-cream milk.'],
                [$itemMap['Sugar'],      10,  100, 'Adjust to guest preference.'],
            ] as [$item, $qty, $yld, $note]) {
                RecipeIngredient::create([
                    'recipe_id'         => $teaRecipe->id,
                    'inventory_item_id' => $item->id,
                    'uom_id'            => $item->issue_uom_id,
                    'quantity'          => $qty,
                    'yield_percentage'  => $yld,
                    'notes'             => $note,
                ]);
            }
        }

        // ── Coffee (1 cup) ──
        if (!Recipe::where('menu_item_id', $menuCoffee->id)->exists()) {
            $coffeeRecipe = Recipe::create([
                'menu_item_id'       => $menuCoffee->id,
                'yield_quantity'     => 1,
                'yield_uom_id'       => $pcs->id,
                'food_cost_target'   => 35.00,
                'notes'              => 'South Indian filter coffee style. Strong decoction with steamed milk.',
                'is_active'          => true,
                'requires_production'=> false,
            ]);
            foreach ([
                [$itemMap['Coffee Powder'], 5,   100, 'Strong decoction. Use filter coffee blend.'],
                [$itemMap['Milk'],          100, 100, '100ml full-cream milk, heated.'],
                [$itemMap['Sugar'],         10,  100, 'Adjust to guest preference.'],
            ] as [$item, $qty, $yld, $note]) {
                RecipeIngredient::create([
                    'recipe_id'         => $coffeeRecipe->id,
                    'inventory_item_id' => $item->id,
                    'uom_id'            => $item->issue_uom_id,
                    'quantity'          => $qty,
                    'yield_percentage'  => $yld,
                    'notes'             => $note,
                ]);
            }
        }

        // ── Omelette (1 plate, 2-egg) ──
        if (!Recipe::where('menu_item_id', $menuOmelette->id)->exists()) {
            $omelRecipe = Recipe::create([
                'menu_item_id'       => $menuOmelette->id,
                'yield_quantity'     => 1,
                'yield_uom_id'       => $pcs->id,
                'food_cost_target'   => 30.00,
                'notes'              => 'Plain 2-egg omelette with onion and green chilli. Served with toast.',
                'is_active'          => true,
                'requires_production'=> false,
            ]);
            foreach ([
                [$itemMap['Eggs'],         2,  100, '2 whole eggs, beaten.'],
                [$itemMap['Butter'],       10, 100, 'For cooking.'],
                [$itemMap['Onion'],        20,  85, 'Finely chopped.'],
                [$itemMap['Green Chilli'],  5, 100, 'Finely chopped. Remove seeds for mild version.'],
                [$itemMap['Salt'],          2, 100, 'To taste.'],
            ] as [$item, $qty, $yld, $note]) {
                if (!$item) continue;
                RecipeIngredient::create([
                    'recipe_id'         => $omelRecipe->id,
                    'inventory_item_id' => $item->id,
                    'uom_id'            => $item->issue_uom_id,
                    'quantity'          => $qty,
                    'yield_percentage'  => $yld,
                    'notes'             => $note,
                ]);
            }
        }

        // ─── Summary ──────────────────────────────────────────────────────────
        $this->command->info('Quick Items seeder complete.');
        $this->command->info('  Menu items : Tea (₹30) · Coffee (₹40) · Omelette (₹80)');
        $this->command->info('  Inventory  : 7 new items added');
        $this->command->info('  Stock      : 30 portions each in Kitchen Store + Main Store');
        $this->command->info('  Recipes    : 3 recipes (auto-deduct on order settle)');
    }
}
