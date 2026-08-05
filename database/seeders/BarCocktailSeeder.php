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
use App\Models\RestaurantMenuItem;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bar cocktails & mocktails as MTO menu items (recipe.requires_production = false).
 *
 * Creates bar mixer inventory, links spirits from BAR-* catalog as BOM ingredients,
 * menu items on all active outlets, and opening stock at Bar Store.
 *
 * Requires: BarInventoryOrganizedSeeder, BarOutletSeeder, RestaurantBarOpeningStockSeeder (spirits).
 */
class BarCocktailSeeder extends Seeder
{
    /** Estimated counter MRP for a 750ml bottle by category (₹, tax-inclusive). */
    private const BASE_MRP_750 = [
        'Brandy' => 1200,
        'Whisky' => 1500,
        'Rum' => 900,
        'Vodka' => 1000,
        'Gin' => 1100,
        'Wine' => 850,
    ];

    /** @var array<string, array{name: string, mixers: array<int, array{0: string, 1: float}>, markup: int, spirit_ml: int}> */
    private const CATEGORY_TEMPLATES = [
        'Brandy' => [
            'name' => 'Brandy Sour',
            'spirit_ml' => 60,
            'markup' => 80,
            'mixers' => [
                ['BAR-MIX-LIME-JUICE', 15],
                ['BAR-MIX-SUGAR-SYRUP', 10],
            ],
        ],
        'Whisky' => [
            'name' => 'Highball',
            'spirit_ml' => 60,
            'markup' => 100,
            'mixers' => [
                ['BAR-MIX-SODA-WATER', 150],
            ],
        ],
        'Rum' => [
            'name' => 'Punch',
            'spirit_ml' => 60,
            'markup' => 60,
            'mixers' => [
                ['BAR-MIX-SODA-WATER', 120],
                ['BAR-MIX-LIME-JUICE', 20],
            ],
        ],
        'Vodka' => [
            'name' => 'Soda',
            'spirit_ml' => 60,
            'markup' => 70,
            'mixers' => [
                ['BAR-MIX-SODA-WATER', 150],
                ['BAR-MIX-LIME-JUICE', 15],
            ],
        ],
        'Gin' => [
            'name' => 'Fizz',
            'spirit_ml' => 60,
            'markup' => 80,
            'mixers' => [
                ['BAR-MIX-SODA-WATER', 120],
                ['BAR-MIX-LIME-JUICE', 15],
                ['BAR-MIX-SUGAR-SYRUP', 10],
            ],
        ],
        'Wine' => [
            'name' => 'Spritzer',
            'spirit_ml' => 120,
            'markup' => 50,
            'mixers' => [
                ['BAR-MIX-SODA-WATER', 80],
            ],
        ],
    ];

    /** @var array<string, InventoryItem> */
    private array $mixerMap = [];

    public function run(): void
    {
        $barStore = InventoryLocation::where('name', 'Bar Store')->first();
        $outlets = BarOrganizedCatalog::activeOutlets();

        if ($outlets->isEmpty()) {
            $this->command?->error('No active outlets found. Run BarOutletSeeder first.');

            return;
        }

        $this->seedMixers($barStore);

        $vat10 = InventoryTax::where('name', 'Liquor VAT 10%')->first();
        if (! $vat10) {
            $this->command?->error('Liquor VAT 10% tax not found.');

            return;
        }

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

        $cocktails = $this->cocktailDefinitions();
        $created = 0;
        $skipped = 0;

        foreach ($cocktails as $def) {
            $ingredientRows = $this->resolveIngredients($def['ingredients']);
            if ($ingredientRows === null) {
                $this->command?->warn("Skipping {$def['name']} — missing inventory ingredient(s).");
                $skipped++;

                continue;
            }

            $menuItem = MenuItem::updateOrCreate(
                ['item_code' => $def['item_code']],
                [
                    'name' => $def['name'],
                    'menu_category_id' => $mainCategory->id,
                    'menu_sub_category_id' => $subCocktails->id,
                    'price' => $def['price'],
                    'tax_id' => $vat10->id,
                    'fixed_ept' => 0,
                    'type' => $def['type'],
                    'is_active' => true,
                    'is_direct_sale' => false,
                    'requires_production' => true,
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
                        'price' => $def['price'],
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
                    'notes' => $def['notes'],
                    'is_active' => true,
                    'requires_production' => false,
                ]
            );

            RecipeIngredient::where('recipe_id', $recipe->id)->delete();
            foreach ($ingredientRows as $row) {
                RecipeIngredient::create([
                    'recipe_id' => $recipe->id,
                    'inventory_item_id' => $row['item']->id,
                    'uom_id' => $row['item']->issue_uom_id,
                    'quantity' => $row['quantity'],
                    'yield_percentage' => 100,
                ]);
            }

            $created++;
        }

        $this->command?->info('Bar cocktail / mocktail seeder complete.');
        $this->command?->info("  Mixer SKUs: ".count($this->mixerMap));
        $this->command?->info("  Cocktails seeded: {$created}");
        if ($skipped > 0) {
            $this->command?->warn("  Skipped: {$skipped}");
        }
        $this->command?->info('  Outlets: '.$outlets->pluck('name')->join(', '));
    }

    /** @return array<int, array<string, mixed>> */
    private function cocktailDefinitions(): array
    {
        $defs = $this->cocktailsFromBarCatalog();
        $defs[] = [
            'item_code' => 'MENU-CKT-VIRGIN-MOJITO',
            'name' => 'Virgin Mojito (Mocktail)',
            'price' => 180,
            'type' => 'veg',
            'notes' => 'MTO mocktail — no alcohol',
            'ingredients' => [
                ['mixer', 'BAR-MIX-SODA-WATER', 150],
                ['mixer', 'BAR-MIX-LIME-JUICE', 25],
                ['mixer', 'BAR-MIX-SUGAR-SYRUP', 15],
                ['mixer', 'BAR-MIX-MINT-FRESH', 5],
            ],
        ];

        return $defs;
    }

    /** @return array<int, array<string, mixed>> */
    private function cocktailsFromBarCatalog(): array
    {
        $rows = require __DIR__.'/data/bar_menu_configuration.php';
        $defs = [];

        foreach ($rows as $row) {
            if (empty($row['top']) || ! empty($row['beer']) || ! empty($row['wine'])) {
                continue;
            }

            $sub = $row['sub'];
            $template = self::CATEGORY_TEMPLATES[$sub] ?? null;
            if (! $template) {
                continue;
            }

            $itemName = $row['item'];
            $size = (int) $row['size'];
            $sku = BarOrganizedCatalog::inventorySku($sub, $itemName, $size);
            if (! InventoryItem::where('sku', $sku)->exists()) {
                continue;
            }

            $shortBrand = Str::before($itemName, ' (');
            $cocktailName = "{$shortBrand} {$template['name']}";
            $itemCode = 'MENU-CKT-'.substr($sku, 4);

            $ingredients = [
                ['spirit', $sub, $itemName, $size, $template['spirit_ml']],
            ];
            foreach ($template['mixers'] as [$mixerSku, $qty]) {
                $ingredients[] = ['mixer', $mixerSku, $qty];
            }

            $defs[] = [
                'item_code' => $itemCode,
                'name' => $cocktailName,
                'price' => $this->estimateCocktailPrice($sub, $size, $template),
                'type' => 'non-veg',
                'notes' => "MTO — {$shortBrand}, ".strtolower($template['name']).' mixers',
                'ingredients' => $ingredients,
            ];
        }

        return $defs;
    }

    /** @param array{name: string, mixers: array<int, array{0: string, 1: float}>, markup: int, spirit_ml: int} $template */
    private function estimateCocktailPrice(string $sub, int $bottleMl, array $template): int
    {
        $base750 = self::BASE_MRP_750[$sub] ?? 1000;
        $bottleMrp = (int) round($base750 * ($bottleMl / 750));
        $spiritMl = max(1, (int) $template['spirit_ml']);
        $pegPrice = (int) round($bottleMrp * ($spiritMl / max(1, $bottleMl)));

        return $pegPrice + (int) $template['markup'];
    }

    private function seedMixers(?InventoryLocation $barStore): void
    {
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

        $gst5 = InventoryTax::where('name', 'GST 5% (Local)')->first()
            ?? InventoryTax::firstOrCreate(['name' => 'GST 5% (Local)'], ['rate' => 5, 'type' => 'local']);

        $ml = InventoryUom::where('short_name', 'ML')->first()
            ?? InventoryUom::where('short_name', 'ml')->first();
        $gm = InventoryUom::where('short_name', 'Gm')->first()
            ?? InventoryUom::where('short_name', 'GM')->first();

        if (! $ml || ! $gm) {
            $this->command?->error('ML and Gm UOMs required.');

            return;
        }

        $defs = [
            ['BAR-MIX-SODA-WATER', 'Soda Water (Bar Mixer)', $ml->id, 50000],
            ['BAR-MIX-LIME-JUICE', 'Fresh Lime Juice (Bar Mixer)', $ml->id, 10000],
            ['BAR-MIX-SUGAR-SYRUP', 'Sugar Syrup (Bar Mixer)', $ml->id, 15000],
            ['BAR-MIX-MINT-FRESH', 'Fresh Mint (Bar Mixer)', $gm->id, 2000],
        ];

        foreach ($defs as [$sku, $name, $issueUomId, $barQty]) {
            $isMl = $issueUomId === $ml->id;
            $item = InventoryItem::updateOrCreate(
                ['sku' => $sku],
                [
                    'name' => $name,
                    'category_id' => $catMixers->id,
                    'vendor_id' => $vendor->id,
                    'purchase_uom_id' => $issueUomId,
                    'issue_uom_id' => $issueUomId,
                    'conversion_factor' => 1,
                    'cost_price' => $isMl ? 0.05 : 0.5,
                    'reorder_level' => $isMl ? 5000 : 200,
                    'current_stock' => 0,
                    'tax_id' => $gst5->id,
                    'is_direct_sale' => false,
                    'is_alcohol' => false,
                    'is_prepared_item' => false,
                ]
            );

            $this->mixerMap[$sku] = $item;

            if ($barStore) {
                $this->seedBarStoreQty($item, $barStore, (float) $barQty);
            }
        }
    }

    private function seedBarStoreQty(InventoryItem $item, InventoryLocation $barStore, float $qty): void
    {
        $existing = DB::table('inventory_item_locations')
            ->where('inventory_item_id', $item->id)
            ->where('inventory_location_id', $barStore->id)
            ->first();

        if ($existing && (float) $existing->quantity > 0) {
            return;
        }

        DB::table('inventory_item_locations')->updateOrInsert(
            [
                'inventory_item_id' => $item->id,
                'inventory_location_id' => $barStore->id,
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
            'inventory_location_id' => $barStore->id,
            'type' => 'in',
            'quantity' => $qty,
            'unit_cost' => (float) ($item->cost_price ?? 0),
            'total_cost' => round($qty * (float) ($item->cost_price ?? 0), 2),
            'reason' => 'Opening Stock (cocktail seeder)',
            'notes' => 'Bar mixer stock for cocktail BOM.',
            'user_id' => null,
        ]);

        InventoryItem::syncStoredCurrentStockFromLocations($item->id);
    }

    /**
     * @param  array<int, array<string, mixed>>  $ingredients
     * @return array<int, array{item: InventoryItem, quantity: float}>|null
     */
    private function resolveIngredients(array $ingredients): ?array
    {
        $rows = [];

        foreach ($ingredients as $ing) {
            if ($ing[0] === 'mixer') {
                $item = $this->mixerMap[$ing[1]] ?? InventoryItem::where('sku', $ing[1])->first();
                if (! $item) {
                    return null;
                }
                $rows[] = ['item' => $item, 'quantity' => (float) $ing[2]];

                continue;
            }

            if ($ing[0] === 'spirit') {
                [, $cat, $name, $size, $ml] = $ing;
                $sku = BarOrganizedCatalog::inventorySku($cat, $name, (int) $size);
                $item = InventoryItem::where('sku', $sku)->first();
                if (! $item) {
                    return null;
                }
                $rows[] = ['item' => $item, 'quantity' => (float) $ml];
            }
        }

        return $rows;
    }

}
