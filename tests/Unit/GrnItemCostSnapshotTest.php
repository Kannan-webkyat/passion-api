<?php

namespace Tests\Unit;

use App\Models\GrnItem;
use App\Services\GrnItemCostSnapshot;
use App\Services\InventoryCostingConfig;
use PHPUnit\Framework\TestCase;

class GrnItemCostSnapshotTest extends TestCase
{
    public function test_reads_frozen_unit_and_line_fields_without_po_recalc(): void
    {
        $line = new GrnItem([
            'quantity_accepted' => 10,
            'line_subtotal_accepted' => 5000,
            'line_cess_accepted' => 300,
            'line_freight_allocated' => 100,
            'merchandise_unit_cost' => 500,
            'cess_unit_cost' => 30,
            'freight_unit_cost' => 10,
            'landed_unit_cost' => 540,
        ]);

        $snapshot = GrnItemCostSnapshot::fromGrnItem($line, InventoryCostingConfig::MODE_LANDED_COST);

        $this->assertSame(500.0, $snapshot['merchandise_unit_cost']);
        $this->assertSame(30.0, $snapshot['cess_unit_cost']);
        $this->assertSame(10.0, $snapshot['freight_unit_cost']);
        $this->assertSame(540.0, $snapshot['posted_unit_cost']);
        $this->assertSame(300.0, $snapshot['line_cess_accepted']);
        $this->assertSame(100.0, $snapshot['line_freight_allocated']);
        $this->assertSame(5400.0, $snapshot['line_posted_total']);
        $this->assertTrue($snapshot['uses_landed_cost']);
    }

    public function test_exclusive_mode_uses_frozen_posted_unit_not_recomputed_landed(): void
    {
        $line = new GrnItem([
            'quantity_accepted' => 10,
            'line_subtotal_accepted' => 5000,
            'line_cess_accepted' => 300,
            'line_freight_allocated' => 100,
            'merchandise_unit_cost' => 500,
            'cess_unit_cost' => 30,
            'freight_unit_cost' => 10,
            'landed_unit_cost' => 500,
        ]);

        $snapshot = GrnItemCostSnapshot::fromGrnItem($line, InventoryCostingConfig::MODE_EXCLUSIVE_ONLY);

        $this->assertSame(500.0, $snapshot['posted_unit_cost']);
        $this->assertFalse($snapshot['uses_landed_cost']);
        $this->assertSame(300.0, $snapshot['line_cess_accepted']);
    }

    public function test_legacy_lines_without_unit_columns_use_frozen_line_totals_only(): void
    {
        $line = new GrnItem([
            'quantity_accepted' => 4,
            'line_subtotal_accepted' => 2000,
            'line_cess_accepted' => 120,
            'line_freight_allocated' => 40,
            'landed_unit_cost' => 500,
        ]);

        $snapshot = GrnItemCostSnapshot::fromGrnItem($line, InventoryCostingConfig::MODE_EXCLUSIVE_ONLY);

        $this->assertSame(500.0, $snapshot['merchandise_unit_cost']);
        $this->assertSame(30.0, $snapshot['cess_unit_cost']);
        $this->assertSame(10.0, $snapshot['freight_unit_cost']);
        $this->assertSame(500.0, $snapshot['posted_unit_cost']);
    }
}
