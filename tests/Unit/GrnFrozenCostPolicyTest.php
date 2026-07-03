<?php

namespace Tests\Unit;

use App\Services\GrnFrozenCostPolicy;
use App\Services\GrnItemCostSnapshot;
use App\Services\LandedCostAllocator;
use PHPUnit\Framework\TestCase;

class GrnFrozenCostPolicyTest extends TestCase
{
    public function test_policy_declares_single_snapshot_reader(): void
    {
        $this->assertSame(GrnItemCostSnapshot::class, GrnFrozenCostPolicy::SNAPSHOT_READER);
        $this->assertSame(LandedCostAllocator::class, GrnFrozenCostPolicy::ALLOCATOR_WRITE_ONLY);
    }

    public function test_policy_lists_only_frozen_grn_unit_fields(): void
    {
        $this->assertContains('landed_unit_cost', GrnFrozenCostPolicy::GRN_ITEM_UNIT_FIELDS);
        $this->assertContains('merchandise_unit_cost', GrnFrozenCostPolicy::GRN_ITEM_UNIT_FIELDS);
        $this->assertContains('cess_unit_cost', GrnFrozenCostPolicy::GRN_ITEM_UNIT_FIELDS);
        $this->assertContains('freight_unit_cost', GrnFrozenCostPolicy::GRN_ITEM_UNIT_FIELDS);
        $this->assertSame('inventory_costing_mode', GrnFrozenCostPolicy::GRN_COSTING_MODE_FIELD);
    }
}
