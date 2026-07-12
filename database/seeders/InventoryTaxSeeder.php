<?php

namespace Database\Seeders;

use App\Models\InventoryTax;
use Illuminate\Database\Seeder;

class InventoryTaxSeeder extends Seeder
{
    public function run()
    {
        $taxes = [
            ['name' => 'GST 5% (Local)', 'rate' => 5, 'type' => 'local', 'is_input_credit_eligible' => true],
            ['name' => 'GST 12% (Local)', 'rate' => 12, 'type' => 'local', 'is_input_credit_eligible' => true],
            ['name' => 'GST 18% (Local)', 'rate' => 18, 'type' => 'local', 'is_input_credit_eligible' => true],
            ['name' => 'IGST 18%', 'rate' => 18, 'type' => 'inter-state', 'is_input_credit_eligible' => true],
            ['name' => 'Liquor VAT', 'rate' => 22, 'type' => 'vat', 'is_input_credit_eligible' => false],
            ['name' => 'Liquor VAT 10%', 'rate' => 10, 'type' => 'vat', 'is_input_credit_eligible' => false],
        ];

        foreach ($taxes as $tax) {
            InventoryTax::updateOrCreate(['name' => $tax['name']], $tax);
        }
    }
}
