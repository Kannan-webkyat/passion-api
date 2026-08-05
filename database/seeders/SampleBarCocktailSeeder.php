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
use App\Models\MenuSubCategory;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RestaurantMaster;
use App\Models\RestaurantMenuItem;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * One sample bar cocktail on BAR, Brews and Bubbles, and Champions Bar.
 *
 * Requires: BarInventoryOrganizedSeeder, BarOutletSeeder (or BAR outlet from RestaurantTableSeeder).
 */
class SampleBarCocktailSeeder extends Seeder
{
    private const OUTLET_NAMES = ['BAR', 'Brews and Bubbles', 'Champions Bar'];

    private const COCKTAIL_ITEM_CODE = 'MENU-CKT-ANTIQUITY-BLUE-HIGHBALL';

    private const SPIRIT_SKU = 'BAR-WSK-ANTIQUITY_BLUE_ULTRA_PRE-750';

    private const COCKTAIL_PRICE = 380;

    /** Spirit ml per pour (issue UOM for spirits is ML). */
    private const SPIRIT_ML = 60;

    /** @var array<string, array{sku: string, name: string, issue_uom: string, bar_qty: float, cost_per_unit: float}> */
    private const MIXERS = [
        'BAR-MIX-SODA-WATER' => [
            'sku' => 'BAR-MIX-SODA-WATER',
            'name' => 'Soda Water (Bar Mixer)',
            'issue_uom' => 'ML',
            'bar_qty' => 50000,
            'cost_per_unit' => 0.05,
        ],
        'BAR-MIX-LIME-JUICE' => [
            'sku' => 'BAR-MIX-LIME-JUICE',
            'name' => 'Fresh Lime Juice (Bar Mixer)',
            'issue_uom' => 'ML',
            'bar_qty' => 10000,
            'cost_per_unit' => 0.05,
        ],
    ];

    /** @var array<string, float> mixer SKU => ml per cocktail */
    private const MIXER_BOM = [
        'BAR-MIX-SODA-WATER' => 150,
        'BAR-MIX-LIME-JUICE' => 15,
    ];

    public function run(): void
    {
        $barStore = InventoryLocation::where('name', 'Bar Store')->first();
        if (! $barStore) {
            $this->command?->error('Bar Store not found. Run LocationSeeder first.');

            return;
        }

        $outlets = RestaurantMaster::query()
            ->whereIn('name', self::OUTLET_NAMES)
            ->where('is_active', true)
            ->get();

        if ($outlets->isEmpty()) {
            $this->command?->error('No bar outlets found. Expected: '.implode(', ', self::OUTLET_NAMES));

            return;
        }

        foreach ($outlets as $outlet) {
            $updates = [];
            if ((int) ($outlet->kitchen_location_id ?? 0) !== (int) $barStore->id) {
                $updates['kitchen_location_id'] = $barStore->id;
            }
            if (! $outlet->bar_location_id) {
                $updates['bar_location_id'] = $barStore->id;
            }
            if ($updates !== []) {
                $outlet->update($updates);
            }
        }

        $spirit = InventoryItem::where('sku', self::SPIRIT_SKU)->first();
        if (! $spirit) {
            $this->command?->error('Spirit not found: '.self::SPIRIT_SKU.'. Run BarInventoryOrganizedSeeder first.');

            return;
        }

        $vat10 = InventoryTax::where('name', 'Liquor VAT 10%')->first()
            ?? InventoryTax::where('type', 'vat')->orderBy('id')->first();
        if (! $vat10) {
            $this->command?->error('Liquor VAT tax not found.');

            return;
        }

        $mixerItems = $this->ensureMixers($barStore);
        $this->seedBarStock($barStore, $spirit, $mixerItems);

        $mainCategory = MenuCategory::firstOrCreate(
            ['name' => 'Alcohols'],
            ['is_active' => true]
        );

        $subCocktails = MenuSubCategory::updateOrCreate(
            ['menu_category_id' => $mainCategory->id, 'name' => 'Cocktails'],
            ['description' => 'Mixed drinks — MTO from bar shelf', 'is_active' => true]
        );

        $pcs = InventoryUom::where('short_name', 'PCS')->first()
            ?? InventoryUom::where('short_name', 'Pcs')->first();

        $menuItem = MenuItem::updateOrCreate(
            ['item_code' => self::COCKTAIL_ITEM_CODE],
            [
                'name' => 'Antiquity Blue Highball',
                'menu_category_id' => $mainCategory->id,
                'menu_sub_category_id' => $subCocktails->id,
                'price' => self::COCKTAIL_PRICE,
                'tax_id' => $vat10->id,
                'fixed_ept' => 0,
                'type' => 'non-veg',
                'is_active' => true,
                'is_direct_sale' => false,
                'requires_production' => false,
                'inventory_item_id' => null,
            ]
        );

        foreach ($outlets as $outlet) {
            RestaurantMenuItem::updateOrCreate(
                [
                    'menu_item_id' => $menuItem->id,
                    'restaurant_master_id' => $outlet->id,
                ],
                [
                    'price' => self::COCKTAIL_PRICE,
                    'fixed_ept' => 0,
                    'is_active' => true,
                    'price_tax_inclusive' => true,
                ]
            );
        }

        $recipe = Recipe::updateOrCreate(
            ['menu_item_id' => $menuItem->id],
            [
                'output_inventory_item_id' => null,
                'recipe_kind' => Recipe::KIND_MENU_ITEM,
                'yield_quantity' => 1,
                'yield_uom_id' => $pcs?->id,
                'food_cost_target' => 35,
                'notes' => 'MTO cocktail — Antiquity Blue 60ml + soda & lime',
                'is_active' => true,
                'requires_production' => false,
            ]
        );

        RecipeIngredient::where('recipe_id', $recipe->id)->delete();

        RecipeIngredient::create([
            'recipe_id' => $recipe->id,
            'inventory_item_id' => $spirit->id,
            'uom_id' => $spirit->issue_uom_id,
            'quantity' => self::SPIRIT_ML,
            'yield_percentage' => 100,
        ]);

        foreach (self::MIXER_BOM as $sku => $qty) {
            $mixer = $mixerItems[$sku] ?? InventoryItem::where('sku', $sku)->first();
            if (! $mixer) {
                continue;
            }
            RecipeIngredient::create([
                'recipe_id' => $recipe->id,
                'inventory_item_id' => $mixer->id,
                'uom_id' => $mixer->issue_uom_id,
                'quantity' => $qty,
                'yield_percentage' => 100,
            ]);
        }

        $this->command?->info('Sample bar cocktail seeded.');
        $this->command?->info('  Item: Antiquity Blue Highball @ ₹'.self::COCKTAIL_PRICE.' (tax inclusive)');
        $this->command?->info('  Outlets: '.$outlets->pluck('name')->join(', '));
        $this->command?->info('  Spirit: '.self::SPIRIT_SKU.' ('.self::SPIRIT_ML.' ml per pour)');
        $this->command?->info('  Bar store linked on all bar outlets (bar_location_id).');
    }

    /** @return array<string, InventoryItem> */
    private function ensureMixers(InventoryLocation $barStore): array
    {
        $gst5 = InventoryTax::where('name', 'GST 5% (Local)')->first()
            ?? InventoryTax::where('type', 'local')->orderBy('id')->first();
        if (! $gst5) {
            $this->command?->error('GST 5% tax not found for mixers.');

            return [];
        }

        $fb = InventoryCategory::firstOrCreate(
            ['name' => 'F&B'],
            ['parent_id' => null, 'description' => 'Food & Beverage']
        );

        $catBar = InventoryCategory::firstOrCreate(
            ['name' => 'Bar'],
            ['parent_id' => $fb->id, 'description' => 'Spirits, beer, liquor']
        );

        $catMixers = InventoryCategory::updateOrCreate(
            ['name' => 'Bar Mixers'],
            ['parent_id' => $catBar->id, 'description' => 'Soda, syrups, garnish — cocktail BOM']
        );

        $vendor = Vendor::firstOrCreate(
            ['name' => 'Bar Supplies Co'],
            [
                'contact_person' => 'Bar Manager',
                'phone' => '9876500000',
                'email' => 'bar@supplies.com',
                'address' => 'Local bar supplies',
                'is_liquor_supplier' => false,
            ]
        );

        $ml = InventoryUom::where('short_name', 'ML')->first()
            ?? InventoryUom::where('short_name', 'ml')->first();

        if (! $ml) {
            $this->command?->error('ML UOM not found.');

            return [];
        }

        $map = [];
        foreach (self::MIXERS as $def) {
            $map[$def['sku']] = InventoryItem::updateOrCreate(
                ['sku' => $def['sku']],
                [
                    'name' => $def['name'],
                    'category_id' => $catMixers->id,
                    'vendor_id' => $vendor->id,
                    'purchase_uom_id' => $ml->id,
                    'issue_uom_id' => $ml->id,
                    'conversion_factor' => 1,
                    'cost_price' => $def['cost_per_unit'],
                    'reorder_level' => 5000,
                    'current_stock' => 0,
                    'tax_id' => $gst5->id,
                    'is_direct_sale' => false,
                    'is_alcohol' => false,
                    'is_prepared_item' => false,
                ]
            );
        }

        return $map;
    }

    /** @param array<string, InventoryItem> $mixers */
    private function seedBarStock(InventoryLocation $barStore, InventoryItem $spirit, array $mixers): void
    {
        $spiritQty = 12 * max(1, (float) ($spirit->conversion_factor ?? 750));
        $this->seedLocationQty($barStore, $spirit, $spiritQty, 'Sample cocktail seeder — spirit');

        foreach ($mixers as $sku => $mixer) {
            $qty = self::MIXERS[$sku]['bar_qty'] ?? 10000;
            $this->seedLocationQty($barStore, $mixer, (float) $qty, 'Sample cocktail seeder — mixer');
        }
    }

    private function seedLocationQty(
        InventoryLocation $location,
        InventoryItem $item,
        float $qty,
        string $note,
    ): void {
        $existing = DB::table('inventory_item_locations')
            ->where('inventory_item_id', $item->id)
            ->where('inventory_location_id', $location->id)
            ->first();

        if ($existing && (float) $existing->quantity > 0) {
            return;
        }

        DB::table('inventory_item_locations')->updateOrInsert(
            [
                'inventory_item_id' => $item->id,
                'inventory_location_id' => $location->id,
            ],
            [
                'quantity' => $qty,
                'reorder_level' => $item->reorder_level ?? 0,
                'updated_at' => now(),
                'created_at' => $existing->created_at ?? now(),
            ]
        );

        InventoryTransaction::create([
            'inventory_item_id' => $item->id,
            'inventory_location_id' => $location->id,
            'type' => 'in',
            'quantity' => $qty,
            'unit_cost' => (float) ($item->cost_price ?? 0),
            'total_cost' => round($qty * (float) ($item->cost_price ?? 0), 2),
            'reason' => 'Opening Stock',
            'notes' => $note,
            'user_id' => null,
        ]);

        InventoryItem::syncStoredCurrentStockFromLocations($item->id);
    }
}
