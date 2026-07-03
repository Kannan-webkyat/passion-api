<?php

namespace App\Services;

/**
 * Strict separation: vendor spend (PO) vs inventory cost / WAC (GRN).
 *
 * Never use "cost" ambiguously in UI or APIs — always qualify the concept.
 */
final class ProcurementCostTerminology
{
    public const VENDOR_SPEND = 'vendor_spend';

    public const INVENTORY_COST_WAC = 'inventory_cost_wac';

    /** @return array<string, string> */
    public static function definitions(): array
    {
        return [
            self::VENDOR_SPEND => 'What you agreed to pay the vendor (purchase order terms).',
            self::INVENTORY_COST_WAC => 'What stock actually costs in inventory (frozen on GRN approval).',
        ];
    }

    /** @return array<string, mixed> */
    public static function vendorSpendReportMeta(): array
    {
        return [
            'report_type' => self::VENDOR_SPEND,
            'label' => 'Vendor Spend',
            'source' => 'purchase_order_items',
            'not' => 'This is not inventory WAC. Use GRN cost_snapshot for stock valuation.',
        ];
    }

    /** @return array<string, mixed> */
    public static function inventoryCostReportMeta(): array
    {
        return [
            'report_type' => self::INVENTORY_COST_WAC,
            'label' => 'Inventory Cost (WAC)',
            'source' => 'grn_items frozen fields via GrnItemCostSnapshot',
            'not' => 'This is not vendor payable. Use PO / vendor spend reports for payables.',
        ];
    }
}
