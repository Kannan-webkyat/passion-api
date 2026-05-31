<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\InventoryTax;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemVariant;
use App\Models\MenuSubCategory;
use App\Models\RestaurantMaster;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantMenuItemVariant;
use Illuminate\Database\Seeder;

/**
 * Bar POS menu from menu_configuration_v2.html — links to BarInventoryOrganizedSeeder SKUs.
 *
 * Main category: Alcohols · Sub: Brandy…Beer · Tax: Liquor VAT 10% · Direct sale · No KOT
 * Largest SKU per brand (top): 30 / 60 / 90 ml + Full Bottle variants
 * Smaller spirits, wine, beer: whole bottle only · Outlets: OTTAAL + BAR
 */
class BarMenuConfigurationSeeder extends Seeder
{
    /** @var array<int, array{label: string, ml: int}> */
    private const PEG_VARIANTS = [
        ['label' => '30ml', 'ml' => 30],
        ['label' => '60ml', 'ml' => 60],
        ['label' => '90ml', 'ml' => 90],
    ];

    public function run(): void
    {
        $rows = require __DIR__.'/data/bar_menu_configuration.php';

        $vat10 = InventoryTax::where('name', 'Liquor VAT 10%')->first();
        if (! $vat10) {
            $this->command?->error('Liquor VAT 10% not found. Run InventoryTaxSeeder and BarInventoryOrganizedSeeder first.');

            return;
        }

        $mainCategory = MenuCategory::updateOrCreate(
            ['name' => 'Alcohols'],
            ['is_active' => true]
        );

        $subCategoryIds = [];
        foreach (array_keys(BarOrganizedCatalog::CAT_CODES) as $subName) {
            $subCategoryIds[$subName] = MenuSubCategory::updateOrCreate(
                ['menu_category_id' => $mainCategory->id, 'name' => $subName],
                ['description' => "{$subName} — alcohol menu", 'is_active' => true]
            )->id;
        }

        $outlets = RestaurantMaster::whereIn('name', ['OTTAAL', 'BAR'])->get();
        if ($outlets->isEmpty()) {
            $this->command?->error('No outlets found (OTTAAL / BAR). Run RestaurantTableSeeder first.');

            return;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $withPegs = 0;
        $wholeOnly = 0;

        foreach ($rows as $row) {
            $sub = $row['sub'];
            $itemName = $row['item'];
            $size = (int) $row['size'];

            $sku = BarOrganizedCatalog::inventorySku($sub, $itemName, $size);
            $inventory = InventoryItem::where('sku', $sku)->first();
            if (! $inventory) {
                $this->command?->warn("Skipping menu row — inventory not found: {$sku}");
                $skipped++;

                continue;
            }

            $openBottle = $this->isOpenBottleSku($row);
            $itemCode = BarOrganizedCatalog::menuItemCode($sub, $itemName, $size);
            $displayName = $inventory->name;

            $menuItem = MenuItem::where('item_code', $itemCode)->first();
            $isNew = ! $menuItem;

            $menuItem = MenuItem::updateOrCreate(
                ['item_code' => $itemCode],
                [
                    'name' => $displayName,
                    'menu_category_id' => $mainCategory->id,
                    'menu_sub_category_id' => $subCategoryIds[$sub] ?? null,
                    'price' => 0,
                    'tax_id' => $vat10->id,
                    'fixed_ept' => 0,
                    'type' => 'non-veg',
                    'is_active' => true,
                    'is_direct_sale' => true,
                    'requires_production' => false,
                    'inventory_item_id' => $inventory->id,
                ]
            );

            if ($openBottle) {
                $this->syncPegVariants($menuItem, $outlets, $size);
                $withPegs++;
            } else {
                $this->syncWholeBottleOnly($menuItem, $outlets);
                $wholeOnly++;
            }

            $isNew ? $created++ : $updated++;
        }

        $this->command?->info('Bar menu configuration seeded.');
        $this->command?->info("  Menu items created: {$created}");
        $this->command?->info("  Menu items updated: {$updated}");
        $this->command?->info("  Open-bottle (peg) items: {$withPegs}");
        $this->command?->info("  Whole-bottle only: {$wholeOnly}");
        if ($skipped > 0) {
            $this->command?->warn("  Skipped (no inventory): {$skipped}");
        }
        $this->command?->info('  Category: Alcohols · Outlets: '. $outlets->pluck('name')->join(', '));
    }

    /** @param array<string, mixed> $row */
    private function isOpenBottleSku(array $row): bool
    {
        if (! empty($row['beer']) || ! empty($row['wine'])) {
            return false;
        }

        return ! empty($row['top']);
    }

    private function syncWholeBottleOnly(MenuItem $menuItem, $outlets): void
    {
        $this->removeAllVariants($menuItem);

        foreach ($outlets as $outlet) {
            RestaurantMenuItem::updateOrCreate(
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
        }
    }

    private function syncPegVariants(MenuItem $menuItem, $outlets, int $bottleMl): void
    {
        $variantDefs = self::PEG_VARIANTS;
        $variantDefs[] = [
            'label' => "Full Bottle {$bottleMl}ml",
            'ml' => $bottleMl,
        ];

        $variantIds = [];
        foreach ($variantDefs as $sort => $def) {
            $variant = MenuItemVariant::updateOrCreate(
                [
                    'menu_item_id' => $menuItem->id,
                    'size_label' => $def['label'],
                ],
                [
                    'price' => 0,
                    'ml_quantity' => (float) $def['ml'],
                    'sort_order' => $sort,
                ]
            );
            $variantIds[] = $variant->id;
        }

        MenuItemVariant::where('menu_item_id', $menuItem->id)
            ->whereNotIn('id', $variantIds)
            ->each(fn (MenuItemVariant $v) => $this->deleteVariant($v));

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

            foreach ($menuItem->variants()->whereIn('id', $variantIds)->get() as $variant) {
                RestaurantMenuItemVariant::updateOrCreate(
                    [
                        'restaurant_menu_item_id' => $rmi->id,
                        'menu_item_variant_id' => $variant->id,
                    ],
                    ['price' => 0]
                );
            }

            RestaurantMenuItemVariant::where('restaurant_menu_item_id', $rmi->id)
                ->whereNotIn('menu_item_variant_id', $variantIds)
                ->delete();
        }
    }

    private function removeAllVariants(MenuItem $menuItem): void
    {
        MenuItemVariant::where('menu_item_id', $menuItem->id)
            ->each(fn (MenuItemVariant $v) => $this->deleteVariant($v));
    }

    private function deleteVariant(MenuItemVariant $variant): void
    {
        RestaurantMenuItemVariant::where('menu_item_variant_id', $variant->id)->delete();
        $variant->delete();
    }
}
