<?php

namespace Tests\Unit;

use App\Services\PurchaseOrderLineAmounts;
use App\Services\TaxCreditPolicy;
use PHPUnit\Framework\TestCase;

class TaxCreditPolicyTest extends TestCase
{
    public function test_inclusive_price_reverse_calculates_exclusive_base_and_tax(): void
    {
        $result = PurchaseOrderLineAmounts::compute(1, 620, 24, PurchaseOrderLineAmounts::BASIS_INCLUSIVE, 30);

        $this->assertEqualsWithDelta(500.0, $result['subtotal'], 0.01);
        $this->assertEqualsWithDelta(120.0, $result['tax_amount'], 0.01);
        $this->assertEquals(30.0, $result['unit_cess']);
    }

    public function test_non_recoverable_tax_splits_to_inventory_bucket(): void
    {
        $split = TaxCreditPolicy::splitLineTax(120, 1, false);

        $this->assertEquals(0.0, $split['recoverable']);
        $this->assertEquals(120.0, $split['non_recoverable']);
        $this->assertEquals(120.0, $split['unit_non_recoverable']);
    }

    public function test_recoverable_gst_splits_to_input_credit_bucket(): void
    {
        $split = TaxCreditPolicy::splitLineTax(90, 1, true);

        $this->assertEquals(90.0, $split['recoverable']);
        $this->assertEquals(0.0, $split['non_recoverable']);
        $this->assertEquals(90.0, $split['unit_recoverable']);
    }
}
