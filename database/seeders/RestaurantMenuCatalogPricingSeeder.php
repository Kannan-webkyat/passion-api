<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\RestaurantMaster;
use App\Models\RestaurantMenuItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Tax-inclusive sell prices for RestaurantMenuCatalogSeeder items (OTTAAL outlet).
 *
 * Uses category + dietary type defaults with light per-item variation.
 * Optional explicit `price` on catalog rows overrides the estimate.
 */
class RestaurantMenuCatalogPricingSeeder extends Seeder
{
    private const OUTLET_NAME = 'OTTAAL';

    /** @var array<string, string> */
    private const CATEGORY_CODES = [
        'FRESH SALADS' => 'SAL',
        'SOUPS' => 'SUP',
        'STARTERS' => 'STR',
        'FRESH CATCH' => 'FSH',
        'CHICKEN & DUCK SPECIALITIES' => 'CKT',
        'MEAT FEAST' => 'MET',
        'VEG DELIGHTS' => 'VGD',
        'RICE ITEMS' => 'RCE',
        'BREADS' => 'BRD',
        'COMBO OFFER' => 'CMB',
        'FRESH JUICE' => 'JUC',
        'DESSERT' => 'DST',
    ];

    /** @var array<string, array{veg: int, non-veg: int}> Base MRP (₹, tax-inclusive) by category. */
    private const CATEGORY_BASE = [
        'FRESH SALADS' => ['veg' => 180, 'non-veg' => 260],
        'SOUPS' => ['veg' => 120, 'non-veg' => 160],
        'STARTERS' => ['veg' => 220, 'non-veg' => 320],
        'FRESH CATCH' => ['veg' => 380, 'non-veg' => 480],
        'CHICKEN & DUCK SPECIALITIES' => ['veg' => 280, 'non-veg' => 380],
        'MEAT FEAST' => ['veg' => 320, 'non-veg' => 450],
        'VEG DELIGHTS' => ['veg' => 200, 'non-veg' => 280],
        'RICE ITEMS' => ['veg' => 220, 'non-veg' => 320],
        'BREADS' => ['veg' => 45, 'non-veg' => 55],
        'COMBO OFFER' => ['veg' => 450, 'non-veg' => 550],
        'FRESH JUICE' => ['veg' => 90, 'non-veg' => 90],
        'DESSERT' => ['veg' => 90, 'non-veg' => 120],
    ];

    public function run(): void
    {
        $catalog = require __DIR__.'/data/restaurant_menu_catalog.php';

        $outlet = RestaurantMaster::where('name', self::OUTLET_NAME)->where('is_active', true)->first();
        if (! $outlet) {
            $this->command?->error('OTTAAL outlet not found. Run RestaurantMenuCatalogSeeder first.');

            return;
        }

        $priced = 0;
        $skipped = 0;

        foreach ($catalog as $categoryName => $items) {
            $codePrefix = self::CATEGORY_CODES[$categoryName] ?? 'RST';

            foreach ($items as $item) {
                $itemCode = $this->itemCode($codePrefix, $item['name']);
                $menuItem = MenuItem::where('item_code', $itemCode)->first();

                if (! $menuItem) {
                    $this->command?->warn("Skipping pricing — menu item not found: {$itemCode}");
                    $skipped++;

                    continue;
                }

                $price = isset($item['price'])
                    ? (int) $item['price']
                    : $this->estimatePrice($categoryName, $item['name'], $item['type'] ?? 'veg');

                $menuItem->update(['price' => $price]);

                RestaurantMenuItem::updateOrCreate(
                    [
                        'menu_item_id' => $menuItem->id,
                        'restaurant_master_id' => $outlet->id,
                    ],
                    [
                        'price' => $price,
                        'fixed_ept' => 0,
                        'is_active' => true,
                        'price_tax_inclusive' => true,
                    ]
                );

                $priced++;
            }
        }

        $this->command?->info('Restaurant menu catalog pricing seeded.');
        $this->command?->info("  Outlet: {$outlet->name}");
        $this->command?->info("  Items priced: {$priced}");
        if ($skipped > 0) {
            $this->command?->warn("  Skipped (no menu item): {$skipped}");
        }
    }

    private function estimatePrice(string $categoryName, string $itemName, string $type): int
    {
        $dietKey = strtolower($type) === 'veg' ? 'veg' : 'non-veg';
        $base = self::CATEGORY_BASE[$categoryName][$dietKey]
            ?? self::CATEGORY_BASE[$categoryName]['non-veg']
            ?? 200;

        // Spread prices within a category without a separate price list.
        $hash = crc32(Str::upper($itemName));
        $offset = (($hash % 9) - 4) * 10;

        return max(40, (int) (round(($base + $offset) / 5) * 5));
    }

    private function itemCode(string $prefix, string $name): string
    {
        $slug = Str::upper(Str::slug($name, '_'));

        return "MENU-{$prefix}-{$slug}";
    }
}
