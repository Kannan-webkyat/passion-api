<?php

namespace Tests\Unit;

use App\Services\InventoryCostingConfig;
use App\Services\LandedCostAllocator;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use PHPUnit\Framework\TestCase;

class InventoryCostingConfigTest extends TestCase
{
    public function test_exclusive_mode_posts_merchandise_unit_only(): void
    {
        $allocation = [
            'merchandise_unit' => 500.0,
            'landed_unit_purchase' => 545.0,
        ];

        $posted = InventoryCostingConfig::postedUnitCostForMode(
            InventoryCostingConfig::MODE_EXCLUSIVE_ONLY,
            $allocation,
        );

        $this->assertSame(500.0, $posted);
    }

    public function test_landed_cost_mode_posts_full_landed_unit(): void
    {
        $allocation = [
            'merchandise_unit' => 500.0,
            'landed_unit_purchase' => 545.0,
        ];

        $posted = InventoryCostingConfig::postedUnitCostForMode(
            InventoryCostingConfig::MODE_LANDED_COST,
            $allocation,
        );

        $this->assertSame(545.0, $posted);
    }

    public function test_mode_switch_does_not_change_stored_grn_unit_cost(): void
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
        ]);
        $poItem->id = 1;
        $po->setRelation('items', collect([$poItem]));

        $grnLines = [(object) ['quantity_accepted' => 10, 'purchase_order_item_id' => 1]];
        $grnMerch = LandedCostAllocator::grnMerchandiseSubtotalSum($grnLines, $po);
        $allocation = LandedCostAllocator::forGrnLine($po, $poItem, 10, $grnMerch);

        $postedExclusive = InventoryCostingConfig::postedUnitCostForMode(
            InventoryCostingConfig::MODE_EXCLUSIVE_ONLY,
            $allocation,
        );
        $postedLanded = InventoryCostingConfig::postedUnitCostForMode(
            InventoryCostingConfig::MODE_LANDED_COST,
            $allocation,
        );

        $this->assertEqualsWithDelta(500.0, $postedExclusive, 0.01);
        $this->assertEqualsWithDelta(540.0, $postedLanded, 0.01);

        // Approved GRN stores posted unit at approval time — later mode changes do not rewrite it.
        $frozenGrnPostedUnit = $postedExclusive;
        $this->assertSame(500.0, $frozenGrnPostedUnit);
    }

    public function test_invalid_mode_falls_back_to_exclusive_posting(): void
    {
        $allocation = [
            'merchandise_unit' => 400.0,
            'landed_unit_purchase' => 450.0,
        ];

        $posted = InventoryCostingConfig::postedUnitCostForMode('unknown', $allocation);

        $this->assertSame(400.0, $posted);
    }
}
