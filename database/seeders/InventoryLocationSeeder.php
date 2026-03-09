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
            ['name' => 'Kitchen Store', 'type' => 'sub_store'],
            ['name' => 'Bar Store',     'type' => 'sub_store'],
            ['name' => 'HK Store',      'type' => 'sub_store'],
            ['name' => 'Front Office',  'type' => 'sub_store'],
            ['name' => 'Laundry',       'type' => 'sub_store'],
        ];

        foreach ($locations as $loc) {
            InventoryLocation::updateOrCreate(['name' => $loc['name']], $loc);
        }
    }
}
