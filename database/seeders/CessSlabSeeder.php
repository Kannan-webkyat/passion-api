<?php

namespace Database\Seeders;

use App\Models\CessSlab;
use Illuminate\Database\Seeder;

/**
 * Kerala BEVCO-style flat cess per bottle (IMFL / FMFL) by MRP bracket.
 *
 * flat_cess_amount = actual price hike at retail counter (includes state sales tax
 * on top of the announced base cess). Base announced: ₹0 / ₹20 / ₹40 — counter: ₹0 / ₹30 / ₹50.
 *
 * @see https://bevco.in (adjust bands/amounts when government notifications change)
 */
class CessSlabSeeder extends Seeder
{
    public function run(): void
    {
        $imflFmflSlabs = [
            // Below ₹500 — exempt
            ['min_mrp' => 0, 'max_mrp' => 499.99, 'flat_cess_amount' => 0.00],
            // ₹500 to ₹999 — base ₹20, counter ₹30
            ['min_mrp' => 500, 'max_mrp' => 999.99, 'flat_cess_amount' => 30.00],
            // ₹1,000 and above — base ₹40, counter ₹50
            ['min_mrp' => 1000, 'max_mrp' => 999999.99, 'flat_cess_amount' => 50.00],
        ];

        // Replace IMFL/FMFL bands so re-seed picks up rule changes (not only firstOrCreate).
        CessSlab::query()
            ->whereIn('item_category', ['imfl', 'fmfl'])
            ->delete();

        foreach (['imfl', 'fmfl'] as $category) {
            foreach ($imflFmflSlabs as $slab) {
                CessSlab::create([
                    'item_category' => $category,
                    'min_mrp' => $slab['min_mrp'],
                    'max_mrp' => $slab['max_mrp'],
                    'flat_cess_amount' => $slab['flat_cess_amount'],
                    'is_active' => true,
                ]);
            }
        }

        // Remove legacy placeholder beer / wine slabs if present.
        CessSlab::query()->whereIn('item_category', ['beer', 'wine'])->delete();

        $count = count($imflFmflSlabs) * 2;
        $this->command?->info("Cess slabs seeded: {$count} row(s) (IMFL/FMFL use counter cess ₹0 / ₹30 / ₹50).");
    }
}
