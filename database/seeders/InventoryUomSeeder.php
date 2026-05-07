<?php

namespace Database\Seeders;

use App\Models\InventoryUom;
use Illuminate\Database\Seeder;

class InventoryUomSeeder extends Seeder
{
    public function run(): void
    {
        $uoms = [
            ['name' => 'Piece', 'short_name' => 'PCS'],
            ['name' => 'Bottle', 'short_name' => 'BTL'],
            ['name' => 'Sachet', 'short_name' => 'SACH'],
            ['name' => 'Pack', 'short_name' => 'PACK'],
            ['name' => 'Box', 'short_name' => 'BOX'],
            ['name' => 'Set', 'short_name' => 'SET'],
            ['name' => 'Pair', 'short_name' => 'PAIR'],
            ['name' => 'Bag', 'short_name' => 'BAG'],
            ['name' => 'Unit', 'short_name' => 'UNIT'],
        ];

        foreach ($uoms as $uom) {
            $name = trim((string) $uom['name']);
            $short = strtoupper(trim((string) $uom['short_name']));

            // inventory_uoms has UNIQUE(name) and UNIQUE(short_name).
            // Existing DBs may already contain a row matching either field, so match by both.
            $existing = InventoryUom::where('short_name', '=', $short, 'and')
                ->orWhere('name', '=', $name)
                ->first();

            if ($existing) {
                $existing->update([
                    'name' => $existing->name ?: $name,
                    'short_name' => $existing->short_name ?: $short,
                ]);
                continue;
            }

            InventoryUom::create([
                'name' => $name,
                'short_name' => $short,
            ]);
        }
    }
}
