<?php

namespace Database\Seeders;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTax;
use App\Models\InventoryUom;
use App\Models\RestaurantMaster;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

/**
 * Bar inventory catalog from BEVCO-style organized list (VAT 10%, cess by category).
 *
 * Requires: InventoryTaxSeeder, InventoryUomSeeder, CessSlabSeeder, DepartmentSeeder,
 * LocationSeeder, RestaurantTableSeeder (BAR outlet + Bar Store).
 */
class BarInventoryOrganizedSeeder extends Seeder
{
    private const REORDER_LEVEL = 10;

    /** @var array<string, string> */
    private const CAT_CODES = BarOrganizedCatalog::CAT_CODES;

    public function run(): void
    {
        $rows = require __DIR__.'/data/bar_inventory_organized.php';

        $vat10 = InventoryTax::firstOrCreate(
            ['name' => 'Liquor VAT 10%'],
            ['rate' => 10, 'type' => 'vat']
        );

        $btl = $this->uom('BTL', 'Bottle');
        $ml = $this->uom('ML', 'Millilitre');

        $vendor = Vendor::firstOrCreate(
            ['name' => 'BEVCO'],
            [
                'contact_person' => 'BEVCO Kerala',
                'phone' => null,
                'email' => null,
                'address' => 'Kerala State Beverages Corporation',
                'is_liquor_supplier' => true,
                'default_tax_price_basis' => 'tax_inclusive',
            ]
        );
        $vendorUpdates = [];
        if (! $vendor->is_liquor_supplier) {
            $vendorUpdates['is_liquor_supplier'] = true;
        }
        if ($vendor->default_tax_price_basis !== 'tax_inclusive') {
            $vendorUpdates['default_tax_price_basis'] = 'tax_inclusive';
        }
        if ($vendorUpdates !== []) {
            $vendor->update($vendorUpdates);
        }

        $alcoholRoot = InventoryCategory::updateOrCreate(
            ['name' => 'Alcohol'],
            ['parent_id' => null, 'description' => 'Spirits, beer, wine — bar inventory']
        );

        $categoryIds = [];
        foreach (self::CAT_CODES as $catName => $_code) {
            $categoryIds[$catName] = InventoryCategory::updateOrCreate(
                ['name' => $catName],
                ['parent_id' => $alcoholRoot->id, 'description' => "{$catName} — alcohol"]
            )->id;
        }

        $barStore = InventoryLocation::where('name', 'Bar Store')->first();
        $barOutlet = RestaurantMaster::where('name', 'BAR')->first();
        if ($barOutlet && $barStore && ! $barOutlet->kitchen_location_id) {
            $barOutlet->update(['kitchen_location_id' => $barStore->id]);
        }

        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $cat = $row['cat'];
            $itemName = $row['item'];
            $size = (int) $row['size'];
            $isBeer = $cat === 'Beer';

            $displayName = $isBeer
                ? "{$itemName} {$size}ml"
                : "{$itemName} {$size}ml";

            $sku = BarOrganizedCatalog::inventorySku($cat, $itemName, $size);
            $meta = $this->liquorMeta($cat);

            $payload = [
                'name' => $displayName,
                'category_id' => $categoryIds[$cat] ?? $alcoholRoot->id,
                'vendor_id' => $vendor->id,
                'purchase_uom_id' => $btl,
                'issue_uom_id' => $isBeer ? $btl : $ml,
                'conversion_factor' => $isBeer ? 1 : (float) $size,
                'cost_price' => 0,
                'reorder_level' => self::REORDER_LEVEL,
                'current_stock' => 0,
                'tax_id' => $vat10->id,
                'is_direct_sale' => true,
                'is_alcohol' => true,
                'is_cess_applicable' => $meta['is_cess_applicable'],
                'liquor_category' => $meta['liquor_category'],
                'cess_amount' => null,
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

        $this->command?->info('Bar inventory (organized) seeded.');
        $this->command?->info("  Items created: {$created}");
        $this->command?->info("  Items updated: {$updated}");
        $this->command?->info('  Vendor: BEVCO · Tax: Liquor VAT 10% · Category: Alcohol ('.count($categoryIds).' subcategories) · Reorder level: '.self::REORDER_LEVEL);
    }

    private function uom(string $shortName, string $name): int
    {
        $short = strtoupper($shortName);
        $uom = InventoryUom::where('short_name', $short)->first()
            ?? InventoryUom::where('name', $name)->first();

        if ($uom) {
            return (int) $uom->id;
        }

        return (int) InventoryUom::create([
            'short_name' => $short,
            'name' => $name,
        ])->id;
    }

    /** @return array{liquor_category: ?string, is_cess_applicable: bool} */
    private function liquorMeta(string $category): array
    {
        return match ($category) {
            'Brandy', 'Whisky', 'Rum', 'Vodka', 'Gin' => [
                'liquor_category' => 'imfl',
                'is_cess_applicable' => true,
            ],
            'Wine' => [
                'liquor_category' => 'fmfl',
                'is_cess_applicable' => true,
            ],
            default => [
                'liquor_category' => null,
                'is_cess_applicable' => false,
            ],
        };
    }
}
