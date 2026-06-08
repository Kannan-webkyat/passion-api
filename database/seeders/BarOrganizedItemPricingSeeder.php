<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\MenuItemVariant;
use App\Models\RestaurantMaster;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantMenuItemVariant;
use Illuminate\Database\Seeder;

/**
 * Counter MRP-style sell prices for BarMenuConfigurationSeeder items.
 *
 * Uses category + bottle size to estimate tax-inclusive outlet prices.
 * Open-bottle (top) SKUs: 30 / 60 / 90 ml pegs + full bottle, proportional to bottle MRP.
 * Beer, wine, and smaller sealed bottles: whole-bottle price only.
 */
class BarOrganizedItemPricingSeeder extends Seeder
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

    /** @var array<int, int> */
    private const BEER_MRP_BY_ML = [
        650 => 260,
        500 => 210,
        330 => 190,
    ];

    /** @var array<int, int> */
    private const PEG_ML = [30, 60, 90];

    public function run(): void
    {
        $rows = require __DIR__.'/data/bar_menu_configuration.php';

        $outlets = BarOrganizedCatalog::activeOutlets();
        if ($outlets->isEmpty()) {
            $this->command?->error('No active outlets found. Run BarOutletSeeder first.');

            return;
        }

        $priced = 0;
        $skipped = 0;
        $pegItems = 0;
        $wholeItems = 0;

        foreach ($rows as $row) {
            $sub = $row['sub'];
            $itemName = $row['item'];
            $size = (int) $row['size'];
            $itemCode = BarOrganizedCatalog::menuItemCode($sub, $itemName, $size);

            $menuItem = MenuItem::where('item_code', $itemCode)->first();
            if (! $menuItem) {
                $this->command?->warn("Skipping pricing — menu item not found: {$itemCode}");
                $skipped++;

                continue;
            }

            $bottleMrp = $this->estimateBottleMrp($row);
            $openBottle = $this->isOpenBottleSku($row);

            if ($openBottle) {
                $this->pricePegItem($menuItem, $outlets, $size, $bottleMrp);
                $pegItems++;
            } else {
                $this->priceWholeBottleItem($menuItem, $outlets, $bottleMrp);
                $wholeItems++;
            }

            $priced++;
        }

        $this->command?->info('Bar organized item pricing seeded.');
        $this->command?->info("  Items priced: {$priced}");
        $this->command?->info("  Open-bottle (peg) items: {$pegItems}");
        $this->command?->info("  Whole-bottle items: {$wholeItems}");
        if ($skipped > 0) {
            $this->command?->warn("  Skipped (no menu item): {$skipped}");
        }
        $this->command?->info('  Outlets: '.$outlets->pluck('name')->join(', '));
    }

    /** @param array<string, mixed> $row */
    private function estimateBottleMrp(array $row): int
    {
        $sub = $row['sub'];
        $size = max(1, (int) $row['size']);

        if (! empty($row['beer'])) {
            return self::BEER_MRP_BY_ML[$size] ?? (int) round($size * 0.4);
        }

        $base750 = self::BASE_MRP_750[$sub] ?? 1000;

        return (int) round($base750 * ($size / 750));
    }

    /** @param array<string, mixed> $row */
    private function isOpenBottleSku(array $row): bool
    {
        if (! empty($row['beer']) || ! empty($row['wine'])) {
            return false;
        }

        return ! empty($row['top']);
    }

    private function priceWholeBottleItem(MenuItem $menuItem, $outlets, int $bottleMrp): void
    {
        $menuItem->update(['price' => $bottleMrp]);

        foreach ($outlets as $outlet) {
            RestaurantMenuItem::updateOrCreate(
                [
                    'menu_item_id' => $menuItem->id,
                    'restaurant_master_id' => $outlet->id,
                ],
                [
                    'price' => $bottleMrp,
                    'fixed_ept' => 0,
                    'is_active' => true,
                    'price_tax_inclusive' => true,
                ]
            );
        }
    }

    private function pricePegItem(MenuItem $menuItem, $outlets, int $bottleMl, int $bottleMrp): void
    {
        $menuItem->update(['price' => 0]);

        $variantPrices = [];
        foreach (self::PEG_ML as $pegMl) {
            $variantPrices["{$pegMl}ml"] = $this->pegPrice($bottleMrp, $bottleMl, $pegMl);
        }
        $variantPrices["Full Bottle {$bottleMl}ml"] = $bottleMrp;

        foreach ($outlets as $outlet) {
            $rmi = RestaurantMenuItem::updateOrCreate(
                [
                    'menu_item_id' => $menuItem->id,
                    'restaurant_master_id' => $outlet->id,
                ],
                [
                    'price' => 0,
                    'fixed_ept' => 0,
                    'is_active' => true,
                    'price_tax_inclusive' => true,
                ]
            );

            foreach ($menuItem->variants as $variant) {
                $price = $variantPrices[$variant->size_label] ?? 0;
                $variant->update(['price' => $price]);

                RestaurantMenuItemVariant::updateOrCreate(
                    [
                        'restaurant_menu_item_id' => $rmi->id,
                        'menu_item_variant_id' => $variant->id,
                    ],
                    ['price' => $price]
                );
            }
        }
    }

    private function pegPrice(int $bottleMrp, int $bottleMl, int $pegMl): int
    {
        return (int) round($bottleMrp * ($pegMl / max(1, $bottleMl)));
    }
}
