<?php

namespace Tests\Unit\Support;

use App\Models\RoomType;
use App\Support\ExtraBedPricing;
use Tests\TestCase;

class ExtraBedPricingTest extends TestCase
{
    public function test_child_extra_beds_use_child_rate(): void
    {
        $rt = new RoomType([
            'base_occupancy' => 2,
            'child_sharing_limit' => 1,
            'extra_bed_cost' => 1000,
            'child_extra_bed_cost' => 600,
        ]);

        // 2 adults + 3 children → 1 child needs extra bed (after sharing limit)
        $charge = ExtraBedPricing::perNightCharge($rt, 2, 3, 1);

        $this->assertSame(600.0, $charge);
    }

    public function test_adult_extra_beds_use_adult_rate(): void
    {
        $rt = new RoomType([
            'base_occupancy' => 2,
            'child_sharing_limit' => 1,
            'extra_bed_cost' => 1000,
            'child_extra_bed_cost' => 600,
        ]);

        // 4 adults → 2 extra adult beds
        $charge = ExtraBedPricing::perNightCharge($rt, 4, 0, 2);

        $this->assertSame(2000.0, $charge);
    }
}
