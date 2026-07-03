<?php

namespace App\Services;

/**
 * Single source of truth for which fields may be read for GRN inventory cost display/reports.
 *
 * FORBIDDEN for cost reads (approved GRNs):
 * - purchase_order_items (unit_price, subtotal, total_cess, etc.)
 * - inventory_items.cost_price
 * - LandedCostAllocator (approve-time write only)
 *
 * @see GrnItemCostSnapshot
 */
final class GrnFrozenCostPolicy
{
    /**
     * Vendor spend (PO) is a separate concept — see ProcurementCostTerminology.
     * This policy covers inventory cost (WAC) reads only.
     */
    /** @var list<string> */
    public const GRN_ITEM_UNIT_FIELDS = [
        'merchandise_unit_cost',
        'cess_unit_cost',
        'freight_unit_cost',
        'landed_unit_cost',
    ];

    /** @var list<string> */
    public const GRN_ITEM_LINE_TOTAL_FIELDS = [
        'line_subtotal_accepted',
        'line_cess_accepted',
        'line_freight_allocated',
    ];

    public const GRN_COSTING_MODE_FIELD = 'inventory_costing_mode';

    public const SNAPSHOT_READER = GrnItemCostSnapshot::class;

    public const ALLOCATOR_WRITE_ONLY = LandedCostAllocator::class;
}
