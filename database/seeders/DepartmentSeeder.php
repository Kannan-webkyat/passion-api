<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Department;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $depts = [
            ['name' => 'Food & Beverage',  'code' => 'FNB', 'is_active' => true],
            ['name' => 'Housekeeping',     'code' => 'HKP', 'is_active' => true],
            ['name' => 'Engineering',      'code' => 'ENG', 'is_active' => true],
            ['name' => 'Front Office',     'code' => 'FRO', 'is_active' => true],
            ['name' => 'Administration',   'code' => 'ADM', 'is_active' => true],
            ['name' => 'Staff Cafeteria',  'code' => 'CAF', 'is_active' => true],
            ['name' => 'Kitchen',          'code' => 'KTN', 'is_active' => true],
            ['name' => 'Bar & Lounge',     'code' => 'BAR', 'is_active' => true],
        ];

        foreach ($depts as $d) {
            Department::updateOrCreate(['code' => $d['code']], $d);
        }
    }
}
