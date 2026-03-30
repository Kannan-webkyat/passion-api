<?php

namespace Database\Seeders;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTax;
use App\Models\InventoryUom;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemVariant;
use App\Models\MenuSubCategory;
use App\Models\RestaurantMaster;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantMenuItemVariant;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BarSeeder extends Seeder
{
    public function run(): void
    {
        $liquorVat = InventoryTax::where('name', 'Liquor VAT')->first();
        if (! $liquorVat) {
            $liquorVat = InventoryTax::create(['name' => 'Liquor VAT', 'rate' => 22, 'type' => 'vat']);
        }

        $pcs = InventoryUom::where('short_name', 'Pcs')->first();
        if (! $pcs) {
            $pcs = InventoryUom::create(['short_name' => 'Pcs', 'name' => 'Piece']);
        }
        $ml = InventoryUom::where('short_name', 'ml')->first();
        if (! $ml) {
            $ml = InventoryUom::create(['short_name' => 'ml', 'name' => 'Millilitre']);
        }

        // ─── 1. Bar vendor ──────────────────────────────────────────────────
        $vendor = Vendor::firstOrCreate(
            ['name' => 'Bar Supplies Co'],
            ['contact_person' => 'Bar Manager', 'phone' => '9876500000', 'email' => 'bar@supplies.com', 'address' => 'Wholesale Liquor, Chennai']
        );

        // ─── 2. Bar inventory category ─────────────────────────────────────
        $fb = InventoryCategory::where('name', 'F&B')->first();
        if (! $fb) {
            $fb = InventoryCategory::create(['name' => 'F&B', 'parent_id' => null, 'description' => 'Food & Beverage']);
        }
        $catBar = InventoryCategory::firstOrCreate(
            ['name' => 'Bar'],
            ['parent_id' => $fb->id, 'description' => 'Spirits, beer, liquor']
        );
        if (! $catBar->parent_id) {
            $catBar->update(['parent_id' => $fb->id]);
        }

        // ─── 3. Bar inventory items ─────────────────────────────────────────
        // Spirits: ml (peg-level), Beer: Pcs (bottle-level)
        // [name, sku, cost, reorder, issue_uom, conversion_factor]
        $itemDefs = [
            ['Johnnie Walker Red 750ml', 'FB-BR-JW1', 1800, 1500, $ml->id, 750],   // spirits: ml, reorder 1500ml
            ['Royal Challenge 750ml', 'FB-BR-RC1', 1200, 1500, $ml->id, 750],
            ['Kingfisher Premium 650ml', 'FB-BR-KF1', 80, 6, $pcs->id, 1],        // beer: Pcs
            ['Bira 91 Blonde 330ml', 'FB-BR-BR1', 60, 6, $pcs->id, 1],
        ];

        $itemMap = [];
        foreach ($itemDefs as [$name, $sku, $cost, $reorder, $issueUomId, $conv]) {
            $itemMap[$name] = InventoryItem::firstOrCreate(
                ['sku' => $sku],
                [
                    'name' => $name,
                    'category_id' => $catBar->id,
                    'vendor_id' => $vendor->id,
                    'purchase_uom_id' => $pcs->id,
                    'issue_uom_id' => $issueUomId,
                    'conversion_factor' => $conv,
                    'cost_price' => $cost,
                    'reorder_level' => $reorder,
                    'current_stock' => 0,
                    'tax_id' => $liquorVat->id,
                    'is_direct_sale' => true,
                ]
            );
            $itemMap[$name]->update([
                'is_direct_sale' => true,
                'issue_uom_id' => $issueUomId,
                'conversion_factor' => $conv,
                'reorder_level' => $reorder,
            ]);
        }

        // ─── 4. Stock in Main Store and Bar Store ──────────────────────────
        $mainStore = InventoryLocation::where('name', 'Main Store')->first();
        $barStore = InventoryLocation::where('name', 'Bar Store')->first();

        // Spirits: ml (bottles × ml). Beer: Pcs (bottles)
        $stockData = [
            'Johnnie Walker Red 750ml' => 12 * 750,
            'Royal Challenge 750ml' => 12 * 750,
            'Kingfisher Premium 650ml' => 48,
            'Bira 91 Blonde 330ml' => 48,
        ];

        foreach (array_filter([$mainStore, $barStore]) as $loc) {
            foreach ($stockData as $itemName => $qty) {
                if (! isset($itemMap[$itemName])) {
                    continue;
                }
                DB::table('inventory_item_locations')->updateOrInsert(
                    [
                        'inventory_item_id' => $itemMap[$itemName]->id,
                        'inventory_location_id' => $loc->id,
                    ],
                    [
                        'quantity' => $qty,
                        'reorder_level' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        foreach ($itemMap as $item) {
            $total = DB::table('inventory_item_locations')
                ->where('inventory_item_id', $item->id)
                ->sum('quantity');
            $item->update(['current_stock' => (int) round($total)]);
        }

        // ─── 5. Bar outlet (create if missing) ───────────────────────────────
        $barOutlet = RestaurantMaster::firstOrCreate(
            ['name' => 'BAR'],
            [
                'floor' => null,
                'description' => 'Champions',
                'is_active' => true,
                'address' => 'EDATHUVA - CHAMPAKKULAM ROAD NEAR EDATHUA POLIC STATION',
                'email' => 'passionshotel@gmail.com',
                'phone' => '9496428888',
                'gstin' => '32AQOPP9995P2ZG',
                'fssai' => '00111111111',
            ]
        );
        if ($barStore) {
            $barOutlet->update(['kitchen_location_id' => $barStore->id]);
        }

        // ─── 6. Bar menu category & subcategories ──────────────────────────
        $catBarMenu = MenuCategory::firstOrCreate(
            ['name' => 'Bar'],
            ['is_active' => true]
        );
        $subSpirits = MenuSubCategory::firstOrCreate(
            ['menu_category_id' => $catBarMenu->id, 'name' => 'Spirits'],
            ['description' => 'Whisky, rum, vodka', 'is_active' => true]
        );
        $subBeer = MenuSubCategory::firstOrCreate(
            ['menu_category_id' => $catBarMenu->id, 'name' => 'Beer'],
            ['description' => 'Beer & lager', 'is_active' => true]
        );

        // ─── 7. Bar menu items ──────────────────────────────────────────────
        // Spirits: variants (30ml, 60ml, Bottle). Beer: no variants, single price per bottle.
        // Bar items are assigned only to the Bar outlet.
        $priceMultiplier = fn (RestaurantMaster $r, float $base): float => (int) round($base);

        // Spirits with variants: [name, code, sub, type, invItem, variants or null]
        $spiritMenuItems = [
            ['Johnnie Walker Red Label', 'MENU-JW-001', $subSpirits, 'non-veg', 'Johnnie Walker Red 750ml', [
                ['30ml', 150, 30],
                ['60ml', 280, 60],
                ['Bottle 750ml', 2500, 750],
            ]],
            ['Royal Challenge', 'MENU-RC-001', $subSpirits, 'non-veg', 'Royal Challenge 750ml', [
                ['30ml', 120, 30],
                ['60ml', 220, 60],
                ['Bottle 750ml', 1800, 750],
            ]],
        ];

        foreach ($spiritMenuItems as [$name, $code, $sub, $type, $invItemName, $variants]) {
            $invItem = $itemMap[$invItemName] ?? null;
            $mi = MenuItem::firstOrCreate(
                ['item_code' => $code],
                [
                    'name' => $name,
                    'menu_category_id' => $catBarMenu->id,
                    'menu_sub_category_id' => $sub->id,
                    'price' => 0,
                    'tax_id' => $liquorVat->id,
                    'fixed_ept' => 0,
                    'type' => $type,
                    'is_active' => true,
                    'is_direct_sale' => true,
                    'requires_production' => false,
                    'inventory_item_id' => $invItem?->id,
                ]
            );
            $mi->update([
                'is_direct_sale' => true,
                'requires_production' => false,
                'inventory_item_id' => $invItem?->id
            ]);

            $rmi = RestaurantMenuItem::updateOrCreate(
                ['menu_item_id' => $mi->id, 'restaurant_master_id' => $barOutlet->id],
                ['price' => 0, 'fixed_ept' => 0, 'is_active' => true, 'price_tax_inclusive' => true]
            );
            foreach ($variants as $i => [$label, $basePrice, $mlQty]) {
                $v = MenuItemVariant::updateOrCreate(
                    ['menu_item_id' => $mi->id, 'size_label' => $label],
                    ['price' => $basePrice, 'ml_quantity' => (float) $mlQty, 'sort_order' => $i]
                );
                $restPrice = $priceMultiplier($barOutlet, (float) $basePrice);
                RestaurantMenuItemVariant::firstOrCreate(
                    ['restaurant_menu_item_id' => $rmi->id, 'menu_item_variant_id' => $v->id],
                    ['price' => $restPrice]
                );
            }
        }

        // Beer: no variants, single price per bottle (1 Pcs = 1 bottle)
        $beerMenuItems = [
            ['Kingfisher Premium 650ml', 'MENU-KF-001', $subBeer, 'non-veg', 'Kingfisher Premium 650ml', 150],
            ['Bira 91 Blonde 330ml', 'MENU-BR-001', $subBeer, 'veg', 'Bira 91 Blonde 330ml', 120],
        ];

        foreach ($beerMenuItems as [$name, $code, $sub, $type, $invItemName, $basePrice]) {
            $invItem = $itemMap[$invItemName] ?? null;
            $mi = MenuItem::firstOrCreate(
                ['item_code' => $code],
                [
                    'name' => $name,
                    'menu_category_id' => $catBarMenu->id,
                    'menu_sub_category_id' => $sub->id,
                    'price' => $basePrice,
                    'tax_id' => $liquorVat->id,
                    'fixed_ept' => 0,
                    'type' => $type,
                    'is_active' => true,
                    'is_direct_sale' => true,
                    'requires_production' => false,
                    'inventory_item_id' => $invItem?->id,
                ]
            );
            $mi->update([
                'is_direct_sale' => true,
                'requires_production' => false,
                'inventory_item_id' => $invItem?->id
            ]);

            // Beer has no variants — remove any old variants
            MenuItemVariant::where('menu_item_id', $mi->id)->each(function ($v) {
                RestaurantMenuItemVariant::where('menu_item_variant_id', $v->id)->delete();
                $v->delete();
            });

            $restPrice = $priceMultiplier($barOutlet, (float) $basePrice);
            RestaurantMenuItem::updateOrCreate(
                ['menu_item_id' => $mi->id, 'restaurant_master_id' => $barOutlet->id],
                ['price' => $restPrice, 'fixed_ept' => 0, 'is_active' => true, 'price_tax_inclusive' => true]
            );
        }

        $this->command->info('Bar seeder complete.');
        $this->command->info('  Inventory items: '.count($itemMap));
        $this->command->info('  Menu items: '.(count($spiritMenuItems) + count($beerMenuItems)));
    }
}
