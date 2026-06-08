<?php

namespace Database\Seeders;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryTax;
use App\Models\InventoryUom;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Restaurant kitchen inventory from Restaurant_Inventory_Management.csv.
 *
 * Hierarchy matches inventory UI: Main Category (root) → Sub-Category → Item.
 */
class RestaurantInventoryCatalogSeeder extends Seeder
{
    /** @var array<string, string> */
    private const MAIN_CODES = [
        'Dry Goods' => 'DRG',
        'Spices' => 'SPI',
        'Fresh Produce' => 'PRD',
        'Meat & Fish' => 'MEF',
        'Dairy & Oils' => 'DRY',
        'Condiments' => 'CND',
        'Frozen Items' => 'FRZ',
        'Disposables' => 'DSP',
    ];

    /** @var array<string, array{0: string, 1: string}> */
    private const UOM_ALIASES = [
        'Kg' => ['Kg', 'Kilogram'],
        'Grams' => ['Gm', 'Gram'],
        'Liter' => ['Ltr', 'Litre'],
        'Ml' => ['Ml', 'Millilitre'],
        'Pcs' => ['Pcs', 'Piece'],
    ];

    public function run(): void
    {
        $rows = require __DIR__.'/data/restaurant_inventory_catalog.php';

        $gst5 = InventoryTax::firstOrCreate(
            ['name' => 'GST 5% (Local)'],
            ['rate' => 5, 'type' => 'local']
        );

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

        $mainCategoryIds = [];
        $subCategoryIds = [];
        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $mainName = $row['main_category'];
            $subName = $row['sub_category'];

            if (! isset($mainCategoryIds[$mainName])) {
                $mainCategoryIds[$mainName] = InventoryCategory::updateOrCreate(
                    ['name' => $mainName],
                    [
                        'parent_id' => null,
                        'description' => "{$mainName} — restaurant inventory",
                    ]
                )->id;
            }

            $subKey = "{$mainName}::{$subName}";
            if (! isset($subCategoryIds[$subKey])) {
                $subCategoryIds[$subKey] = InventoryCategory::updateOrCreate(
                    ['name' => $this->subCategoryName($mainName, $subName)],
                    [
                        'parent_id' => $mainCategoryIds[$mainName],
                        'description' => "{$subName} — {$mainName}",
                    ]
                )->id;
            }

            $sku = $this->sku($mainName, $row['item']);
            $payload = [
                'name' => $row['item'],
                'category_id' => $subCategoryIds[$subKey],
                'vendor_id' => $vendor->id,
                'purchase_uom_id' => $this->uomId($row['purchase_uom']),
                'issue_uom_id' => $this->uomId($row['issue_uom']),
                'conversion_factor' => (float) $row['conversion_factor'],
                'cost_price' => (float) $row['cost_price'],
                'reorder_level' => (float) $row['reorder_level'],
                'current_stock' => 0,
                'tax_id' => $gst5->id,
                'is_direct_sale' => (bool) $row['is_direct_sale'],
                'is_prepared_item' => (bool) $row['is_prepared_item'],
            ];

            $item = InventoryItem::where('sku', $sku)->first();
            if ($item) {
                $item->update($payload);
                $updated++;
            } else {
                InventoryItem::create(array_merge(['sku' => $sku], $payload));
                $created++;
            }
        }

        $this->cleanupLegacyCategories();

        $this->command?->info('Restaurant inventory catalog seeded.');
        $this->command?->info("  Items created: {$created}");
        $this->command?->info("  Items updated: {$updated}");
        $this->command?->info('  Main categories: '.count($mainCategoryIds).' · Sub-categories: '.count($subCategoryIds));
        $this->command?->info('  Vendor: Restaurant Supplies · Tax: GST 5% (Local)');
    }

    private function subCategoryName(string $mainCategory, string $subCategory): string
    {
        if ($subCategory === $mainCategory) {
            return "{$mainCategory} Items";
        }

        return $subCategory;
    }

    private function cleanupLegacyCategories(): void
    {
        InventoryCategory::query()
            ->where('name', 'like', '% — %')
            ->whereDoesntHave('items')
            ->each(fn (InventoryCategory $category) => $category->delete());
    }

    private function sku(string $mainCategory, string $item): string
    {
        $code = self::MAIN_CODES[$mainCategory] ?? 'RST';
        $slug = Str::upper(Str::slug(Str::limit($item, 32, ''), '_'));
        $slug = $slug !== '' ? $slug : 'ITEM';

        return "RST-{$code}-{$slug}";
    }

    private function uomId(string $label): int
    {
        $aliases = self::UOM_ALIASES[$label] ?? [Str::title($label), $label];

        $uom = InventoryUom::where('short_name', $aliases[0])->first()
            ?? InventoryUom::where('name', $aliases[1])->first()
            ?? InventoryUom::whereRaw('LOWER(short_name) = ?', [strtolower($aliases[0])])->first();

        if ($uom) {
            return (int) $uom->id;
        }

        return (int) InventoryUom::create([
            'short_name' => $aliases[0],
            'name' => $aliases[1],
        ])->id;
    }
}
