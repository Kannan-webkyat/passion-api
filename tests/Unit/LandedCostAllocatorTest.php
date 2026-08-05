<?php

namespace Tests\Unit;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\LandedCostAllocator;
use Tests\TestCase;

class LandedCostAllocatorTest extends TestCase
{
    public function test_partial_grns_split_freight_without_double_counting(): void
    {
        $po = new PurchaseOrder([
            'subtotal' => 10000,
            'transportation_charge' => 300,
            'loading_unloading_charge' => 200,
        ]);

        $poItem = new PurchaseOrderItem([
            'quantity_ordered' => 100,
            'subtotal' => 10000,
            'total_cess' => 3000,
            'tax_amount' => 0,
        ]);
        $poItem->id = 1;

        $po->setRelation('items', collect([$poItem]));

        $grn1Lines = [(object) ['quantity_accepted' => 40, 'purchase_order_item_id' => 1]];
        $grn2Lines = [(object) ['quantity_accepted' => 60, 'purchase_order_item_id' => 1]];

        $grn1Merch = LandedCostAllocator::grnMerchandiseSubtotalSum($grn1Lines, $po);
        $grn2Merch = LandedCostAllocator::grnMerchandiseSubtotalSum($grn2Lines, $po);

        $this->assertEqualsWithDelta(4000, $grn1Merch, 0.01);
        $this->assertEqualsWithDelta(6000, $grn2Merch, 0.01);

        $freight1 = LandedCostAllocator::grnFreightAllocatedSum($grn1Lines, $po);
        $freight2 = LandedCostAllocator::grnFreightAllocatedSum($grn2Lines, $po);

        $this->assertEqualsWithDelta(200, $freight1, 0.02);
        $this->assertEqualsWithDelta(300, $freight2, 0.02);
        $this->assertEqualsWithDelta(500, $freight1 + $freight2, 0.05);
    }

    public function test_landed_unit_includes_base_cess_freight_and_non_recoverable_tax(): void
    {
        $po = new PurchaseOrder([
            'subtotal' => 5000,
            'transportation_charge' => 100,
            'loading_unloading_charge' => 0,
        ]);

        $poItem = new PurchaseOrderItem([
            'quantity_ordered' => 10,
            'subtotal' => 5000,
            'total_cess' => 300,
            'tax_amount' => 1200,
        ]);
        $poItem->id = 1;

        $po->setRelation('items', collect([$poItem]));

        $grnLines = [(object) ['quantity_accepted' => 10, 'purchase_order_item_id' => 1]];
        $grnMerch = LandedCostAllocator::grnMerchandiseSubtotalSum($grnLines, $po);

        $landed = LandedCostAllocator::forGrnLine($po, $poItem, 10, $grnMerch, false);

        $this->assertEquals(500.0, $landed['merchandise_unit']);
        $this->assertEquals(30.0, $landed['cess_unit']);
        $this->assertEqualsWithDelta(10.0, $landed['freight_unit'], 0.01);
        $this->assertEquals(120.0, $landed['non_recoverable_tax_unit']);
        $this->assertEquals(0.0, $landed['recoverable_tax_unit']);
        $this->assertEqualsWithDelta(660.0, $landed['landed_unit_purchase'], 0.01);
    }

    public function test_recoverable_gst_excluded_from_landed_unit(): void
    {
        $po = new PurchaseOrder(['subtotal' => 5000, 'transportation_charge' => 0, 'loading_unloading_charge' => 0]);
        $poItem = new PurchaseOrderItem([
            'quantity_ordered' => 10,
            'subtotal' => 5000,
            'total_cess' => 0,
            'tax_amount' => 900,
        ]);
        $poItem->id = 1;
        $po->setRelation('items', collect([$poItem]));

        $grnLines = [(object) ['quantity_accepted' => 10, 'purchase_order_item_id' => 1]];
        $grnMerch = LandedCostAllocator::grnMerchandiseSubtotalSum($grnLines, $po);

        $landed = LandedCostAllocator::forGrnLine($po, $poItem, 10, $grnMerch, true);

        $this->assertEquals(500.0, $landed['landed_unit_purchase']);
        $this->assertEquals(90.0, $landed['recoverable_tax_unit']);
        $this->assertEquals(0.0, $landed['non_recoverable_tax_unit']);
        $this->assertEquals(900.0, $landed['line_recoverable_tax_accepted']);
    }
}
