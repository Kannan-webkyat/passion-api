<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\InventoryTax;

class InventoryTaxSeeder extends Seeder
{
    public function run()
    {
        $taxes = [
            ['name' => 'GST 5% (Local)', 'rate' => 5, 'type' => 'local'],
            ['name' => 'GST 12% (Local)', 'rate' => 12, 'type' => 'local'],
            ['name' => 'GST 18% (Local)', 'rate' => 18, 'type' => 'local'],
            ['name' => 'IGST 18%', 'rate' => 18, 'type' => 'inter-state'],
            ['name' => 'Liquor VAT', 'rate' => 22, 'type' => 'vat'],
        ];

        foreach ($taxes as $tax) {
            InventoryTax::updateOrCreate(['name' => $tax['name']], $tax);
        }
    }
}
