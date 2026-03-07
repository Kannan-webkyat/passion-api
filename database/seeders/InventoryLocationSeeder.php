<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\InventoryLocation;

class InventoryLocationSeeder extends Seeder
{
    public function run(): void
    {
        $locations = [
            ['name' => 'Main Store',    'type' => 'main_store'],
            ['name' => 'Kitchen Store', 'type' => 'department'],
            ['name' => 'Bar Store',     'type' => 'department'],
            ['name' => 'HK Store',      'type' => 'department'],
            ['name' => 'Front Office',  'type' => 'department'],
            ['name' => 'Laundry',       'type' => 'department'],
        ];

        foreach ($locations as $loc) {
            InventoryLocation::updateOrCreate(['name' => $loc['name']], $loc);
        }
    }
}
