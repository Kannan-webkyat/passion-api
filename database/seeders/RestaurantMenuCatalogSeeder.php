<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\InventoryLocation;
use App\Models\InventoryTax;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\RestaurantMaster;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantTable;
use App\Models\TableCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Restaurant recipe menu catalog — salads through dessert.
 *
 * Does not touch bar/alcohol menu items. Creates OTTAAL outlet + tables if missing.
 * Menu availability: all active outlets.
 */
class RestaurantMenuCatalogSeeder extends Seeder
{
    private const OUTLET_NAME = 'OTTAAL';

    private const ADDRESS = 'EDATHUVA - CHAMPAKKULAM ROAD NEAR EDATHUA POLIC STATION';

    private const EMAIL = 'passionshotel@gmail.com';

    private const PHONE = '9496428888';

    private const GSTIN = '32AQOPP9995P2ZG';

    private const FSSAI = '00111111111';

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

    public function run(): void
    {
        $catalog = require __DIR__.'/data/restaurant_menu_catalog.php';

        $gst5 = InventoryTax::firstOrCreate(
            ['name' => 'GST 5% (Local)'],
            ['rate' => 5, 'type' => 'local']
        );

        $this->ensureRestaurantOutlet();

        $outlets = BarOrganizedCatalog::activeOutlets();
        if ($outlets->isEmpty()) {
            $this->command?->error('No active outlets found. Create outlets before seeding restaurant menu.');

            return;
        }

        $categoryIds = $this->seedCategories(array_keys($catalog));

        $created = 0;
        $updated = 0;

        foreach ($catalog as $categoryName => $items) {
            $categoryId = $categoryIds[$categoryName] ?? null;
            if (! $categoryId) {
                continue;
            }

            $codePrefix = self::CATEGORY_CODES[$categoryName] ?? 'RST';

            foreach ($items as $item) {
                $itemCode = $this->itemCode($codePrefix, $item['name']);
                $displayName = $this->titleCase($item['name']);

                $menuItem = MenuItem::where('item_code', $itemCode)->first();
                $isNew = ! $menuItem;

                $menuItem = MenuItem::updateOrCreate(
                    ['item_code' => $itemCode],
                    [
                        'name' => $displayName,
                        'menu_category_id' => $categoryId,
                        'menu_sub_category_id' => null,
                        'price' => 0,
                        'tax_id' => $gst5->id,
                        'fixed_ept' => 0,
                        'type' => $item['type'],
                        'is_active' => true,
                        'is_direct_sale' => false,
                        'requires_production' => (bool) ($item['kot'] ?? true),
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
                            'price' => 0,
                            'fixed_ept' => 0,
                            'is_active' => true,
                            'price_tax_inclusive' => true,
                        ]
                    );
                }

                $isNew ? $created++ : $updated++;
            }
        }

        $total = $created + $updated;
        $this->command?->info('Restaurant menu catalog seeded.');
        $this->command?->info('  Outlets: '.$outlets->pluck('name')->join(', '));
        $this->command?->info('  Categories: '.count($catalog));
        $this->command?->info("  Menu items created: {$created}");
        $this->command?->info("  Menu items updated: {$updated}");
        $this->command?->info("  Total recipe items: {$total}");
    }

    private function ensureRestaurantOutlet(): void
    {
        $kitchen = InventoryLocation::where('name', 'Kitchen')->first();
        $fnbDept = Department::where('code', 'FNB')->first();

        $outlet = RestaurantMaster::updateOrCreate(
            ['name' => self::OUTLET_NAME],
            [
                'floor' => null,
                'description' => 'Restaurant',
                'is_active' => true,
                'address' => self::ADDRESS,
                'email' => self::EMAIL,
                'phone' => self::PHONE,
                'gstin' => self::GSTIN,
                'fssai' => self::FSSAI,
            ]
        );

        if ($kitchen) {
            $outlet->update(['kitchen_location_id' => $kitchen->id]);
        }
        if ($fnbDept) {
            $outlet->update(['department_id' => $fnbDept->id]);
        }

        $this->seedRestaurantTables($outlet);
    }

    private function seedRestaurantTables(RestaurantMaster $outlet): void
    {
        $twoSeater = TableCategory::firstOrCreate(
            ['name' => '2-Seater'],
            ['capacity' => 2, 'description' => 'Intimate table for couples.']
        );
        $fourSeater = TableCategory::firstOrCreate(
            ['name' => '4-Seater'],
            ['capacity' => 4, 'description' => 'Standard family table.']
        );
        $sixSeater = TableCategory::firstOrCreate(
            ['name' => '6-Seater'],
            ['capacity' => 6, 'description' => 'Large group table.']
        );

        $tables = [
            ['T-01', $twoSeater, 2],
            ['T-02', $twoSeater, 2],
            ['T-03', $fourSeater, 4],
            ['T-04', $fourSeater, 4],
            ['T-05', $fourSeater, 4],
            ['T-06', $sixSeater, 6],
        ];

        foreach ($tables as [$number, $category, $capacity]) {
            RestaurantTable::updateOrCreate(
                [
                    'table_number' => $number,
                    'restaurant_master_id' => $outlet->id,
                ],
                [
                    'category_id' => $category->id,
                    'capacity' => $capacity,
                    'status' => 'available',
                    'location' => null,
                ]
            );
        }
    }

    /** @param array<int, string> $names */
    private function seedCategories(array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $ids[$name] = MenuCategory::updateOrCreate(
                ['name' => $name],
                ['is_active' => true]
            )->id;
        }

        return $ids;
    }

    private function itemCode(string $prefix, string $name): string
    {
        $slug = Str::upper(Str::slug($name, '_'));

        return "MENU-{$prefix}-{$slug}";
    }

    private function titleCase(string $name): string
    {
        return Str::title(Str::lower($name));
    }
}
