<?php

namespace Tests\Feature\RoomChart;

use App\Models\RoomType;
use App\Support\SeasonalRoomPricing;

class ExtraBedAgePricingTest extends RoomChartTestCase
{
    public function test_extra_bed_price_uses_child_age_band(): void
    {
        $roomType = new RoomType([
            'base_occupancy' => 2,
            'child_sharing_limit' => 1,
            'child_age_from' => 2,
            'child_age_limit' => 12,
            'extra_bed_cost' => 500,
            'child_extra_bed_cost' => 300,
        ]);

        $this->assertSame(300.0, SeasonalRoomPricing::extraBedPreTax($roomType, 2, 2, [5, 6], 1));
        $this->assertSame(500.0, SeasonalRoomPricing::extraBedPreTax($roomType, 3, 0, [], 1));
        $this->assertSame(500.0, SeasonalRoomPricing::extraBedPreTax($roomType, 2, 2, null, 1));
        $this->assertSame(['adult' => 0, 'child' => 1], SeasonalRoomPricing::requiredExtraBeds($roomType, 2, 2, [5, 6]));
        $this->assertSame(['adult' => 0, 'child' => 0], SeasonalRoomPricing::requiredExtraBeds($roomType, 2, 1, [14]));

        $roomType->child_sharing_limit = 0;
        $this->assertSame(['adult' => 1, 'child' => 0], SeasonalRoomPricing::requiredExtraBeds($roomType, 2, 1, [14]));
        $this->assertSame(500.0, SeasonalRoomPricing::extraBedPreTax($roomType, 2, 1, [14], 1));
    }
}
