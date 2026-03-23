<?php

namespace Database\Seeders;

use App\Models\DietaryType;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTax;
use App\Models\InventoryUom;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuSubCategory;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RestaurantMaster;
use App\Models\RestaurantMenuItem;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FreshBiryaniTeaCoffeeSeeder extends Seeder
{
    public function run(): void
    {
        // ─── 0. Truncate (FK order) ─────────────────────────────────────────
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $tables = [
            'pos_payments', 'pos_order_items', 'pos_orders',
        ];
        foreach ($tables as $t) {
            if (Schema::hasTable($t)) {
                DB::table($t)->truncate();
            }
        }

        // Reset table status: occupied/cleaning tables with no orders → available
        if (Schema::hasTable('restaurant_tables')) {
            DB::table('restaurant_tables')
                ->whereIn('status', ['occupied', 'cleaning'])
                ->update(['status' => 'available']);
        }

        $tables = [
            'recipe_ingredients',
            'recipes',
            'production_logs',
            'restaurant_menu_item_variants', 'menu_item_variants',
            'restaurant_menu_items', 'menu_items', 'menu_sub_categories', 'menu_categories',
            'dietary_types',
            'inventory_item_locations', 'inventory_transactions', 'grns',
            'purchase_order_items', 'purchase_orders', 'inventory_items',
        ];

        foreach ($tables as $t) {
            if (Schema::hasTable($t)) {
                DB::table($t)->truncate();
            }
        }

        // Truncate inventory_categories (parent_id self-ref) and rebuild
        if (Schema::hasTable('inventory_categories')) {
            DB::table('inventory_categories')->update(['parent_id' => null]);
            DB::table('inventory_categories')->truncate();
        }
        if (Schema::hasTable('inventory_uoms')) {
            DB::table('inventory_uoms')->truncate();
        }
        if (Schema::hasTable('vendors')) {
            DB::table('vendors')->truncate();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // ─── 1. Vendors ────────────────────────────────────────────────────
        $vendors = [
            ['name' => 'Fresh F&B Supplies', 'contact_person' => 'Raj Kumar', 'phone' => '9876543210', 'email' => 'raj@freshfbsupplies.com', 'address' => 'Industrial Area, Chennai'],
            ['name' => 'Spice & Grain Co', 'contact_person' => 'Priya Sharma', 'phone' => '9123456789', 'email' => 'orders@spicegrain.co', 'address' => 'Wholesale Market, Coimbatore'],
            ['name' => 'Dairy Fresh Ltd', 'contact_person' => 'Suresh Nair', 'phone' => '9988776655', 'email' => 'supply@dairyfresh.in', 'address' => 'Milk Colony, Madurai'],
        ];
        $vendorMap = [];
        foreach ($vendors as $v) {
            $vendor = Vendor::create($v);
            $vendorMap[$v['name']] = $vendor;
        }

        // ─── 2. UOMs ────────────────────────────────────────────────────────
        $uoms = [
            ['Kg', 'Kilogram'],
            ['Gm', 'Gram'],
            ['Ltr', 'Litre'],
            ['Ml', 'Millilitre'],
            ['Pcs', 'Piece'],
            ['Cup', 'Cup'],
            ['Bottle', 'Bottle'],
            ['Can', 'Can'],
        ];
        $uomMap = [];
        foreach ($uoms as [$short, $name]) {
            $uomMap[$short] = InventoryUom::create(['short_name' => $short, 'name' => $name]);
        }

        // ─── 3. Inventory categories ───────────────────────────────────────
        $fb = InventoryCategory::create(['name' => 'F&B', 'parent_id' => null, 'description' => 'Food & Beverage']);
        $catDry = InventoryCategory::create(['name' => 'Dry Provisions', 'parent_id' => $fb->id, 'description' => 'Rice, flour, sugar, spices']);
        $catDairy = InventoryCategory::create(['name' => 'Dairy & Eggs', 'parent_id' => $fb->id, 'description' => 'Milk, cream, curd, eggs']);
        $catVeg = InventoryCategory::create(['name' => 'Vegetables', 'parent_id' => $fb->id, 'description' => 'Fresh vegetables and herbs']);
        $catMeat = InventoryCategory::create(['name' => 'Meat & Seafood', 'parent_id' => $fb->id, 'description' => 'Chicken, mutton, fish']);
        $catOils = InventoryCategory::create(['name' => 'Oils & Fats', 'parent_id' => $fb->id, 'description' => 'Cooking oils and ghee']);
        $catBev = InventoryCategory::create(['name' => 'Beverages', 'parent_id' => $fb->id, 'description' => 'Tea, coffee, soft drinks']);
        $catFG = InventoryCategory::create(['name' => 'Finished Goods', 'parent_id' => $fb->id, 'description' => 'Prepared batch items (e.g. Biryani)']);

        // ─── 4. Tax ────────────────────────────────────────────────────────
        $gst5 = InventoryTax::where('rate', 5)->first();
        if (! $gst5) {
            $gst5 = InventoryTax::create(['name' => 'GST 5% (Local)', 'rate' => 5, 'type' => 'local']);
        }

        // ─── 5. Inventory items ─────────────────────────────────────────────
        $v1 = $vendorMap['Fresh F&B Supplies'];
        $v2 = $vendorMap['Spice & Grain Co'];
        $v3 = $vendorMap['Dairy Fresh Ltd'];

        $itemDefs = [
            // [name, sku, category, vendor, pur_uom, issue_uom, conv, cost, reorder]
            ['Chicken (Bone-in)', 'FB-MT-CH1', $catMeat, $v1, 'Kg', 'Gm', 1000, 220, 2],
            ['Mutton', 'FB-MT-MU1', $catMeat, $v1, 'Kg', 'Gm', 1000, 450, 1],
            ['Basmati Rice', 'FB-DP-RI1', $catDry, $v2, 'Kg', 'Gm', 1000, 90, 3],
            ['Onion', 'FB-VG-ON1', $catVeg, $v1, 'Kg', 'Gm', 1000, 30, 2],
            ['Tomato', 'FB-VG-TM1', $catVeg, $v1, 'Kg', 'Gm', 1000, 25, 2],
            ['Mint Leaves', 'FB-VG-MN1', $catVeg, $v1, 'Kg', 'Gm', 1000, 80, 1],
            ['Coriander Leaves', 'FB-VG-CR1', $catVeg, $v1, 'Kg', 'Gm', 1000, 60, 1],
            ['Curd (Yogurt)', 'FB-DE-CU1', $catDairy, $v3, 'Kg', 'Gm', 1000, 65, 2],
            ['Desi Ghee', 'FB-OF-GH1', $catOils, $v2, 'Kg', 'Gm', 1000, 500, 1],
            ['Sunflower Oil', 'FB-OF-OI1', $catOils, $v2, 'Ltr', 'Ml', 1000, 120, 2],
            ['Ginger-Garlic Paste', 'FB-DP-GG1', $catDry, $v2, 'Kg', 'Gm', 1000, 80, 1],
            ['Biryani Masala', 'FB-DP-BM1', $catDry, $v2, 'Kg', 'Gm', 1000, 400, 1],
            ['Red Chilli Powder', 'FB-DP-RC1', $catDry, $v2, 'Kg', 'Gm', 1000, 200, 1],
            ['Turmeric Powder', 'FB-DP-TU1', $catDry, $v2, 'Kg', 'Gm', 1000, 180, 1],
            ['Garam Masala', 'FB-DP-GM1', $catDry, $v2, 'Kg', 'Gm', 1000, 320, 1],
            ['Salt', 'FB-DP-SA1', $catDry, $v2, 'Kg', 'Gm', 1000, 20, 1],
            ['Saffron', 'FB-DP-SF1', $catDry, $v2, 'Kg', 'Gm', 1000, 50000, 1],
            ['Tea Leaves', 'FB-BV-TL1', $catBev, $v2, 'Kg', 'Gm', 1000, 300, 1],
            ['Coffee Powder', 'FB-BV-CP1', $catBev, $v2, 'Kg', 'Gm', 1000, 600, 1],
            ['Milk', 'FB-DE-MK1', $catDairy, $v3, 'Ltr', 'Ml', 1000, 60, 5],
            ['Sugar', 'FB-DP-SG1', $catDry, $v2, 'Kg', 'Gm', 1000, 45, 2],
            ['Cardamom', 'FB-DP-CD1', $catDry, $v2, 'Kg', 'Gm', 1000, 1200, 1],
            ['Cinnamon', 'FB-DP-CN1', $catDry, $v2, 'Kg', 'Gm', 1000, 800, 1],
            ['Ginger', 'FB-VG-GI1', $catVeg, $v1, 'Kg', 'Gm', 1000, 100, 1],
            ['Potato', 'FB-VG-PT1', $catVeg, $v1, 'Kg', 'Gm', 1000, 35, 2],
            ['Eggs', 'FB-DE-EG1', $catDairy, $v3, 'Pcs', 'Pcs', 1, 8, 2],
            ['Butter', 'FB-OF-BT1', $catOils, $v3, 'Kg', 'Gm', 1000, 480, 1],
            ['Green Chilli', 'FB-VG-GC1', $catVeg, $v1, 'Kg', 'Gm', 1000, 60, 1],
            ['Pepsi (Can)', 'FB-BV-PP1', $catBev, $v1, 'Pcs', 'Pcs', 1, 30, 2],
            ['Sprite (Can)', 'FB-BV-SP1', $catBev, $v1, 'Pcs', 'Pcs', 1, 30, 2],
            ['JW Black Label', 'FB-BV-JW1', $catBev, $v1, 'Bottle', 'Ml', 750, 4500, 1],
            // Finished goods
            ['Chicken Biryani', 'FB-FG-CB1', $catFG, $v1, 'Pcs', 'Pcs', 1, 150, 0],
            ['Mutton Biryani', 'FB-FG-MB1', $catFG, $v1, 'Pcs', 'Pcs', 1, 250, 0],
            ['Veg Biryani', 'FB-FG-VB1', $catFG, $v1, 'Pcs', 'Pcs', 1, 100, 0],
        ];

        $itemMap = [];
        foreach ($itemDefs as [$name, $sku, $cat, $vendor, $pu, $iu, $conv, $cost, $reorder]) {
            $itemMap[$name] = InventoryItem::create([
                'name' => $name,
                'sku' => $sku,
                'category_id' => $cat->id,
                'vendor_id' => $vendor->id,
                'purchase_uom_id' => $uomMap[$pu]->id,
                'issue_uom_id' => $uomMap[$iu]->id,
                'conversion_factor' => $conv,
                'cost_price' => $cost,
                'reorder_level' => $reorder,
                'current_stock' => $reorder * 20,
                'tax_id' => $gst5->id,
            ]);
        }

        // ─── 6. Stock in locations (quantities in issue UOM: Gm, Ml, or Pcs) ─
        $mainStore = InventoryLocation::where('name', 'Main Store')->first();
        $kitchen = InventoryLocation::where('name', 'Kitchen')->first();

        // Stock per item: Main Store + Kitchen (each gets same qty in issue UOM)
        // Gm items: 20000 = 20 kg, 10000 = 10 kg, etc. | Ml: 20000 = 20 Ltr | Pcs: 120 = 10 dozen
        $stockQty = [
            'Chicken (Bone-in)' => 20000,  // 20 kg
            'Mutton' => 15000,  // 15 kg
            'Basmati Rice' => 25000,  // 25 kg
            'Onion' => 10000,  // 10 kg
            'Tomato' => 10000,  // 10 kg
            'Mint Leaves' => 2000,   // 2 kg
            'Coriander Leaves' => 2000,   // 2 kg
            'Curd (Yogurt)' => 5000,   // 5 kg
            'Desi Ghee' => 5000,   // 5 kg
            'Sunflower Oil' => 20000,  // 20 Ltr (ml)
            'Ginger-Garlic Paste' => 5000,   // 5 kg
            'Biryani Masala' => 2000,   // 2 kg
            'Red Chilli Powder' => 2000,   // 2 kg
            'Turmeric Powder' => 1000,   // 1 kg
            'Garam Masala' => 1000,   // 1 kg
            'Salt' => 10000,  // 10 kg
            'Saffron' => 50,     // 50 gm (expensive)
            'Tea Leaves' => 5000,   // 5 kg
            'Coffee Powder' => 2000,   // 2 kg
            'Milk' => 20000,  // 20 Ltr (ml)
            'Sugar' => 10000,  // 10 kg
            'Cardamom' => 500,    // 500 gm
            'Cinnamon' => 500,    // 500 gm
            'Ginger' => 2000,   // 2 kg
            'Potato' => 15000, // 15 kg
            'Eggs' => 120,    // 120 pcs (10 dozen)
            'Butter' => 2000,   // 2 kg
            'Green Chilli' => 2000,   // 2 kg
            'Pepsi (Can)' => 48,      // 48 cans
            'Sprite (Can)' => 48,     // 48 cans
            'JW Black Label' => 10,  // 10 bottles
        ];

        foreach (array_filter([$mainStore, $kitchen]) as $loc) {
            foreach ($stockQty as $itemName => $qty) {
                if (! isset($itemMap[$itemName])) {
                    continue;
                }
                DB::table('inventory_item_locations')->insert([
                    'inventory_item_id' => $itemMap[$itemName]->id,
                    'inventory_location_id' => $loc->id,
                    'quantity' => $qty,
                    'reorder_level' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // ─── 7. Dietary types ──────────────────────────────────────────────
        DietaryType::create(['name' => 'Veg', 'is_active' => true]);
        DietaryType::create(['name' => 'Non-Veg', 'is_active' => true]);
        DietaryType::create(['name' => 'Egg', 'is_active' => true]);

        // ─── 8. Menu categories & subcategories ────────────────────────────
        $catBiryani = MenuCategory::create(['name' => 'Biryani', 'is_active' => true]);
        $catBeverages = MenuCategory::create(['name' => 'Beverages', 'is_active' => true]);

        $subChicken = MenuSubCategory::create(['menu_category_id' => $catBiryani->id, 'name' => 'Chicken', 'description' => 'Chicken biryanis', 'is_active' => true]);
        $subMutton = MenuSubCategory::create(['menu_category_id' => $catBiryani->id, 'name' => 'Mutton', 'description' => 'Mutton biryanis', 'is_active' => true]);
        $subVeg = MenuSubCategory::create(['menu_category_id' => $catBiryani->id, 'name' => 'Veg', 'description' => 'Vegetarian biryanis', 'is_active' => true]);
        $subHot = MenuSubCategory::create(['menu_category_id' => $catBeverages->id, 'name' => 'Hot Drinks', 'description' => 'Tea, coffee', 'is_active' => true]);
        $subCold = MenuSubCategory::create(['menu_category_id' => $catBeverages->id, 'name' => 'Cold Drinks', 'description' => 'Iced beverages', 'is_active' => true]);

        // ─── 9. Menu items ─────────────────────────────────────────────────
        $pcs = $uomMap['Pcs'];

        $menuItems = [
            // Name, Code, Cat, Sub, Price, Type, EPT, isDirect, requiresProduction
            ['Chicken Biryani', 'MENU-CB-001', $catBiryani, $subChicken, 350, 'non-veg', 15, false, true],
            ['Mutton Biryani', 'MENU-MB-001', $catBiryani, $subMutton, 450, 'non-veg', 20, false, true],
            ['Veg Biryani', 'MENU-VB-001', $catBiryani, $subVeg, 250, 'veg', 12, false, true],
            ['Tea', 'MENU-TE-001', $catBeverages, $subHot, 30, 'veg', 5, true, true],
            ['Coffee', 'MENU-CF-001', $catBeverages, $subHot, 40, 'veg', 5, true, true],
            ['Masala Chai', 'MENU-MC-001', $catBeverages, $subHot, 35, 'veg', 6, true, true],
            ['Cold Coffee', 'MENU-CC-001', $catBeverages, $subCold, 50, 'veg', 5, true, true],
            ['Iced Tea', 'MENU-IT-001', $catBeverages, $subCold, 45, 'veg', 5, true, true],
            ['Pepsi (Can)', 'MENU-PP-001', $catBeverages, $subCold, 40, 'veg', 0, true, false],
            ['Sprite (Can)', 'MENU-SP-001', $catBeverages, $subCold, 40, 'veg', 0, true, false],
        ];

        $menuItemMap = [];
        foreach ($menuItems as [$name, $code, $cat, $sub, $price, $type, $ept, $isDirect, $requiresProd]) {
            $menuItemMap[$name] = MenuItem::create([
                'item_code' => $code,
                'name' => $name,
                'menu_category_id' => $cat->id,
                'menu_sub_category_id' => $sub->id,
                'price' => $price,
                'tax_id' => $gst5->id,
                'fixed_ept' => $ept,
                'type' => $type,
                'is_active' => true,
                'is_direct_sale' => $isDirect,
                'requires_production' => $requiresProd,
            ]);
        }

        // ─── 10. Recipes & ingredients ───────────────────────────────────────

        // Chicken Biryani
        $cb = $menuItemMap['Chicken Biryani'];
        $r1 = Recipe::create([
            'menu_item_id' => $cb->id,
            'yield_quantity' => 1,
            'yield_uom_id' => $pcs->id,
            'food_cost_target' => 30,
            'notes' => 'Classic Hyderabadi chicken biryani',
            'is_active' => true,
            'requires_production' => true,
        ]);
        foreach ([
            ['Chicken (Bone-in)', 200, 75], ['Basmati Rice', 120, 100], ['Onion', 80, 85],
            ['Tomato', 50, 90], ['Ginger-Garlic Paste', 15, 100], ['Curd (Yogurt)', 40, 100],
            ['Desi Ghee', 10, 100], ['Sunflower Oil', 15, 100], ['Biryani Masala', 5, 100],
            ['Red Chilli Powder', 3, 100], ['Turmeric Powder', 1, 100], ['Garam Masala', 2, 100],
            ['Salt', 3, 100], ['Saffron', 0.1, 100], ['Mint Leaves', 10, 80], ['Coriander Leaves', 10, 80],
        ] as [$n, $q, $y]) {
            RecipeIngredient::create([
                'recipe_id' => $r1->id,
                'inventory_item_id' => $itemMap[$n]->id,
                'uom_id' => $itemMap[$n]->issue_uom_id,
                'quantity' => $q,
                'yield_percentage' => $y,
            ]);
        }

        // Veg Biryani (simplified)
        $vb = $menuItemMap['Veg Biryani'];
        $r2 = Recipe::create([
            'menu_item_id' => $vb->id,
            'yield_quantity' => 1,
            'yield_uom_id' => $pcs->id,
            'food_cost_target' => 25,
            'notes' => 'Vegetarian biryani with potato and vegetables',
            'is_active' => true,
            'requires_production' => true,
        ]);
        foreach ([
            ['Basmati Rice', 150, 100], ['Potato', 80, 90], ['Onion', 60, 85],
            ['Tomato', 40, 90], ['Ginger-Garlic Paste', 10, 100], ['Sunflower Oil', 20, 100],
            ['Biryani Masala', 5, 100], ['Salt', 3, 100], ['Mint Leaves', 8, 80], ['Coriander Leaves', 8, 80],
        ] as [$n, $q, $y]) {
            RecipeIngredient::create([
                'recipe_id' => $r2->id,
                'inventory_item_id' => $itemMap[$n]->id,
                'uom_id' => $itemMap[$n]->issue_uom_id,
                'quantity' => $q,
                'yield_percentage' => $y,
            ]);
        }

        // Tea
        $tea = $menuItemMap['Tea'];
        $r3 = Recipe::create([
            'menu_item_id' => $tea->id,
            'yield_quantity' => 1,
            'yield_uom_id' => $pcs->id,
            'food_cost_target' => 40,
            'notes' => 'Standard milk tea',
            'is_active' => true,
            'requires_production' => false,
        ]);
        foreach ([['Tea Leaves', 3, 100], ['Milk', 100, 100], ['Sugar', 10, 100]] as [$n, $q, $y]) {
            RecipeIngredient::create([
                'recipe_id' => $r3->id,
                'inventory_item_id' => $itemMap[$n]->id,
                'uom_id' => $itemMap[$n]->issue_uom_id,
                'quantity' => $q,
                'yield_percentage' => $y,
            ]);
        }

        // Coffee
        $coffee = $menuItemMap['Coffee'];
        $r4 = Recipe::create([
            'menu_item_id' => $coffee->id,
            'yield_quantity' => 1,
            'yield_uom_id' => $pcs->id,
            'food_cost_target' => 35,
            'notes' => 'South Indian filter coffee',
            'is_active' => true,
            'requires_production' => false,
        ]);
        foreach ([['Coffee Powder', 5, 100], ['Milk', 100, 100], ['Sugar', 10, 100]] as [$n, $q, $y]) {
            RecipeIngredient::create([
                'recipe_id' => $r4->id,
                'inventory_item_id' => $itemMap[$n]->id,
                'uom_id' => $itemMap[$n]->issue_uom_id,
                'quantity' => $q,
                'yield_percentage' => $y,
            ]);
        }

        // Masala Chai
        $mc = $menuItemMap['Masala Chai'];
        $r5 = Recipe::create([
            'menu_item_id' => $mc->id,
            'yield_quantity' => 1,
            'yield_uom_id' => $pcs->id,
            'food_cost_target' => 38,
            'notes' => 'Spiced tea with cardamom and ginger',
            'is_active' => true,
            'requires_production' => false,
        ]);
        foreach ([
            ['Tea Leaves', 3, 100], ['Milk', 100, 100], ['Sugar', 10, 100],
            ['Cardamom', 1, 100], ['Ginger', 2, 100],
        ] as [$n, $q, $y]) {
            RecipeIngredient::create([
                'recipe_id' => $r5->id,
                'inventory_item_id' => $itemMap[$n]->id,
                'uom_id' => $itemMap[$n]->issue_uom_id,
                'quantity' => $q,
                'yield_percentage' => $y,
            ]);
        }

        // Cold Coffee
        $cc = $menuItemMap['Cold Coffee'];
        $r6 = Recipe::create([
            'menu_item_id' => $cc->id,
            'yield_quantity' => 1,
            'yield_uom_id' => $pcs->id,
            'food_cost_target' => 35,
            'notes' => 'Iced coffee with milk',
            'is_active' => true,
            'requires_production' => false,
        ]);
        foreach ([['Coffee Powder', 6, 100], ['Milk', 150, 100], ['Sugar', 15, 100]] as [$n, $q, $y]) {
            RecipeIngredient::create([
                'recipe_id' => $r6->id,
                'inventory_item_id' => $itemMap[$n]->id,
                'uom_id' => $itemMap[$n]->issue_uom_id,
                'quantity' => $q,
                'yield_percentage' => $y,
            ]);
        }

        // Iced Tea (simple)
        $it = $menuItemMap['Iced Tea'];
        $r7 = Recipe::create([
            'menu_item_id' => $it->id,
            'yield_quantity' => 1,
            'yield_uom_id' => $pcs->id,
            'food_cost_target' => 35,
            'notes' => 'Chilled iced tea',
            'is_active' => true,
            'requires_production' => false,
        ]);
        foreach ([['Tea Leaves', 4, 100], ['Sugar', 15, 100]] as [$n, $q, $y]) {
            RecipeIngredient::create([
                'recipe_id' => $r7->id,
                'inventory_item_id' => $itemMap[$n]->id,
                'uom_id' => $itemMap[$n]->issue_uom_id,
                'quantity' => $q,
                'yield_percentage' => $y,
            ]);
        }

        // Mutton Biryani
        $mb = $menuItemMap['Mutton Biryani'];
        $r8 = Recipe::create([
            'menu_item_id' => $mb->id,
            'yield_quantity' => 1,
            'yield_uom_id' => $pcs->id,
            'food_cost_target' => 32,
            'notes' => 'Mutton biryani with tender mutton pieces',
            'is_active' => true,
            'requires_production' => true,
        ]);
        foreach ([
            ['Mutton', 250, 70], ['Basmati Rice', 120, 100], ['Onion', 80, 85],
            ['Tomato', 50, 90], ['Ginger-Garlic Paste', 15, 100], ['Curd (Yogurt)', 40, 100],
            ['Desi Ghee', 10, 100], ['Sunflower Oil', 15, 100], ['Biryani Masala', 5, 100],
            ['Red Chilli Powder', 3, 100], ['Salt', 3, 100], ['Mint Leaves', 10, 80], ['Coriander Leaves', 10, 80],
        ] as [$n, $q, $y]) {
            RecipeIngredient::create([
                'recipe_id' => $r8->id,
                'inventory_item_id' => $itemMap[$n]->id,
                'uom_id' => $itemMap[$n]->issue_uom_id,
                'quantity' => $q,
                'yield_percentage' => $y,
            ]);
        }

        // Link Pepsi/Sprite directly to inventory items
        if (isset($menuItemMap['Pepsi (Can)']) && isset($itemMap['Pepsi (Can)'])) {
            $menuItemMap['Pepsi (Can)']->update(['inventory_item_id' => $itemMap['Pepsi (Can)']->id]);
        }
        if (isset($menuItemMap['Sprite (Can)']) && isset($itemMap['Sprite (Can)'])) {
            $menuItemMap['Sprite (Can)']->update(['inventory_item_id' => $itemMap['Sprite (Can)']->id]);
        }

        // Link Finished Goods directly to inventory items
        if (isset($menuItemMap['Chicken Biryani']) && isset($itemMap['Chicken Biryani'])) {
            $menuItemMap['Chicken Biryani']->update(['inventory_item_id' => $itemMap['Chicken Biryani']->id]);
        }
        if (isset($menuItemMap['Mutton Biryani']) && isset($itemMap['Mutton Biryani'])) {
            $menuItemMap['Mutton Biryani']->update(['inventory_item_id' => $itemMap['Mutton Biryani']->id]);
        }
        if (isset($menuItemMap['Veg Biryani']) && isset($itemMap['Veg Biryani'])) {
            $menuItemMap['Veg Biryani']->update(['inventory_item_id' => $itemMap['Veg Biryani']->id]);
        }

        // ─── 11. Restaurant menu items (link to OTTAAL only; BAR has its own items) ─
        $restaurants = RestaurantMaster::where('is_active', true)->where('name', 'OTTAAL')->get();
        foreach ($restaurants as $rest) {
            foreach ($menuItemMap as $name => $mi) {
                RestaurantMenuItem::create([
                    'menu_item_id' => $mi->id,
                    'restaurant_master_id' => $rest->id,
                    'price' => $mi->price,
                    'fixed_ept' => $mi->fixed_ept,
                    'is_active' => true,
                ]);
            }
        }
        
        // Also add Cold Drinks, Biryani and Coffee to the BAR outlet
        $bar = RestaurantMaster::where('name', 'BAR')->first();
        if ($bar) {
            foreach ($menuItemMap as $name => $mi) {
                $categoryName = $mi->category?->name ?? '';
                if (
                    in_array($name, ['Pepsi (Can)', 'Sprite (Can)']) || 
                    $categoryName === 'Biryani' || 
                    str_contains($name, 'Coffee')
                ) {
                    RestaurantMenuItem::firstOrCreate([
                        'menu_item_id' => $mi->id,
                        'restaurant_master_id' => $bar->id,
                    ], [
                        'price' => $mi->price,
                        'fixed_ept' => $mi->fixed_ept,
                        'is_active' => true,
                    ]);
                }
            }
        }

        $this->command->info('Fresh Biryani, Tea, Coffee seeder complete.');
        $this->command->info('  Vendors: '.count($vendorMap));
        $this->command->info('  UOMs: '.count($uomMap));
        $this->command->info('  Inventory items: '.count($itemMap));
        $this->command->info('  Menu items: '.count($menuItemMap));
        $this->command->info('  Recipes: 8');
    }
}