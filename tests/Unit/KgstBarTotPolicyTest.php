<?php

namespace Tests\Unit;

use App\Services\KgstBarTotPolicy;
use Tests\TestCase;

class KgstBarTotPolicyTest extends TestCase
{
    public function test_kgst_model_uses_full_turnover_no_per_sale_tax(): void
    {
        [$tax, $turnover] = KgstBarTotPolicy::liquorLineTaxAndTurnover(
            effGross: 770.0,
            taxExempt: false,
            useKgstModel: true,
            priceTaxInclusive: true,
            taxRatePercent: 10.0,
        );

        $this->assertEquals(0.0, $tax);
        $this->assertEquals(770.0, $turnover);
    }

    public function test_legacy_model_extracts_vat_from_inclusive_mrp(): void
    {
        [$tax, $net] = KgstBarTotPolicy::liquorLineTaxAndTurnover(
            effGross: 770.0,
            taxExempt: false,
            useKgstModel: false,
            priceTaxInclusive: true,
            taxRatePercent: 10.0,
        );

        $this->assertEqualsWithDelta(70.0, $tax, 0.01);
        $this->assertEqualsWithDelta(700.0, $net, 0.01);
    }

    public function test_tot_liability_is_ten_percent_of_turnover(): void
    {
        $this->assertEquals(365311.0, KgstBarTotPolicy::totLiabilityFromTurnover(3653110.0));
    }
}
