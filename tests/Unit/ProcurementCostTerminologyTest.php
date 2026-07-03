<?php

namespace Tests\Unit;

use App\Services\ProcurementCostTerminology;
use PHPUnit\Framework\TestCase;

class ProcurementCostTerminologyTest extends TestCase
{
    public function test_vendor_spend_report_meta(): void
    {
        $meta = ProcurementCostTerminology::vendorSpendReportMeta();

        $this->assertSame(ProcurementCostTerminology::VENDOR_SPEND, $meta['report_type']);
        $this->assertSame('Vendor Spend', $meta['label']);
        $this->assertSame('purchase_order_items', $meta['source']);
    }

    public function test_inventory_cost_report_meta(): void
    {
        $meta = ProcurementCostTerminology::inventoryCostReportMeta();

        $this->assertSame(ProcurementCostTerminology::INVENTORY_COST_WAC, $meta['report_type']);
        $this->assertSame('Inventory Cost (WAC)', $meta['label']);
        $this->assertStringContainsString('GrnItemCostSnapshot', $meta['source']);
    }
}
