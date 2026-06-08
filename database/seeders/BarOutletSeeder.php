<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\InventoryLocation;
use App\Models\RestaurantMaster;
use App\Models\RestaurantTable;
use App\Models\TableCategory;
use Illuminate\Database\Seeder;

class BarOutletSeeder extends Seeder
{
    private const ADDRESS = 'EDATHUVA - CHAMPAKKULAM ROAD NEAR EDATHUA POLIC STATION';

    private const EMAIL = 'passionshotel@gmail.com';

    private const PHONE = '9496428888';

    private const GSTIN = '32AQOPP9995P2ZG';

    private const FSSAI = '00111111111';

    /**
     * @var array<int, array{
     *     name: string,
     *     description: string,
     *     tables: array<int, array{0: string, 1: string, 2: int}>
     * }>
     */
    private const BAR_OUTLETS = [
        [
            'name' => 'Brews and Bubbles',
            'description' => 'Bar',
            'tables' => [
                ['BnB-01', '2-Seater', 2],
                ['BnB-02', '2-Seater', 2],
                ['BnB-03', '4-Seater', 4],
                ['BnB-04', '4-Seater', 4],
            ],
        ],
        [
            'name' => 'Champions Bar',
            'description' => 'Bar',
            'tables' => [
                ['CB-01', '2-Seater', 2],
                ['CB-02', '2-Seater', 2],
                ['CB-03', '4-Seater', 4],
                ['CB-04', '6-Seater', 6],
            ],
        ],
    ];

    public function run(): void
    {
        $barStore = InventoryLocation::where('name', 'Bar Store')->first();
        $barDept = Department::where('code', 'BAR')->first();
        $categories = $this->tableCategories();

        $outletsCreated = 0;
        $outletsUpdated = 0;
        $tablesSeeded = 0;

        foreach (self::BAR_OUTLETS as $def) {
            $outlet = RestaurantMaster::updateOrCreate(
                ['name' => $def['name']],
                [
                    'floor' => null,
                    'description' => $def['description'],
                    'is_active' => true,
                    'address' => self::ADDRESS,
                    'email' => self::EMAIL,
                    'phone' => self::PHONE,
                    'gstin' => self::GSTIN,
                    'fssai' => self::FSSAI,
                ]
            );

            $outlet->wasRecentlyCreated ? $outletsCreated++ : $outletsUpdated++;

            if ($barStore) {
                $outlet->update(['kitchen_location_id' => $barStore->id]);
            }
            if ($barDept) {
                $outlet->update(['department_id' => $barDept->id]);
            }

            foreach ($def['tables'] as [$tableNumber, $categoryName, $capacity]) {
                $category = $categories[$categoryName] ?? null;
                if (! $category) {
                    $this->command?->warn("Skipping table {$tableNumber} — category not found: {$categoryName}");

                    continue;
                }

                RestaurantTable::updateOrCreate(
                    [
                        'table_number' => $tableNumber,
                        'restaurant_master_id' => $outlet->id,
                    ],
                    [
                        'category_id' => $category->id,
                        'capacity' => $capacity,
                        'status' => 'available',
                        'location' => null,
                    ]
                );
                $tablesSeeded++;
            }
        }

        $this->command?->info('Bar outlets & tables seeded.');
        $this->command?->info("  Outlets created: {$outletsCreated} · updated: {$outletsUpdated}");
        $this->command?->info('  Outlets: '.collect(self::BAR_OUTLETS)->pluck('name')->join(', '));
        $this->command?->info("  Tables seeded: {$tablesSeeded}");
    }

    /** @return array<string, TableCategory> */
    private function tableCategories(): array
    {
        $defs = [
            ['name' => '2-Seater', 'capacity' => 2, 'description' => 'Intimate table for couples.'],
            ['name' => '4-Seater', 'capacity' => 4, 'description' => 'Standard group table.'],
            ['name' => '6-Seater', 'capacity' => 6, 'description' => 'Large group table.'],
        ];

        $categories = [];
        foreach ($defs as $def) {
            $categories[$def['name']] = TableCategory::firstOrCreate(
                ['name' => $def['name']],
                ['capacity' => $def['capacity'], 'description' => $def['description']]
            );
        }

        return $categories;
    }
}
