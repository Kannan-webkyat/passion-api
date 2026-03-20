<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\InventoryLocation;
use App\Models\Department;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Main Store (No Department, system-wide)
        InventoryLocation::updateOrCreate(
            ['name' => 'Main Store'],
            ['type' => 'main_store', 'is_active' => true, 'department_id' => null]
        );

        // 2. Kitchen Store (POS deductions, production) — type = kitchen_store
        InventoryLocation::updateOrCreate(
            ['name' => 'Kitchen Store'],
            ['type' => 'kitchen_store', 'is_active' => true, 'department_id' => Department::where('code', 'KTN')->first()?->id]
        );

        // 3. Sub-stores (linked to Departments)
        $mappings = [
            ['name' => 'Bar Store',      'code' => 'BAR'],
            ['name' => 'Housekeeping Store', 'code' => 'HKP'],
            ['name' => 'Engineering Hub', 'code' => 'ENG'],
            ['name' => 'Reception Pantry', 'code' => 'FRO'],
        ];

        foreach ($mappings as $map) {
            $dept = Department::where('code', $map['code'])->first();
            if ($dept && $map['name'] !== 'Kitchen Store') {
                InventoryLocation::updateOrCreate(
                    ['name' => $map['name']],
                    [
                        'type' => 'sub_store',
                        'department_id' => $dept->id,
                        'is_active' => true
                    ]
                );
            }
        }
    }
}
