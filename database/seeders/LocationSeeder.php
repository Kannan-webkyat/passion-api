<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\InventoryLocation;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Main Store (No Department, system-wide)
        InventoryLocation::updateOrCreate(
            ['name' => 'Main Store'],
            ['type' => 'main_store', 'is_active' => true, 'department_id' => null]
        );

        // 2. Kitchen (POS deductions, production)
        $ktnDept = Department::where('code', 'KTN')->first();
        InventoryLocation::updateOrCreate(
            ['name' => 'Kitchen'],
            ['type' => 'kitchen_store', 'is_active' => true, 'department_id' => $ktnDept?->id]
        );

        // 3. Sub-stores (linked to Departments)
        $mappings = [
            ['name' => 'Bar Store',      'code' => 'BAR', 'type' => 'bar_store'],
            ['name' => 'Housekeeping Store', 'code' => 'HKP', 'type' => 'housekeeping_store'],
            ['name' => 'Engineering Hub', 'code' => 'ENG', 'type' => 'sub_store'],
            ['name' => 'Reception Pantry', 'code' => 'FRO', 'type' => 'sub_store'],
        ];

        foreach ($mappings as $map) {
            $dept = Department::where('code', $map['code'])->first();
            if ($dept) {
                InventoryLocation::updateOrCreate(
                    ['name' => $map['name']],
                    [
                        'type' => $map['type'],
                        'department_id' => $dept->id,
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
