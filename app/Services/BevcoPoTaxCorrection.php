<?php

namespace App\Services;

use App\Models\GRN;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correct Bevco / liquor-supplier PO lines to non_taxable (all-in price, no separate VAT).
 */
final class BevcoPoTaxCorrection
{
    /**
     * @return array{po_lines: int, purchase_orders: int, grn_lines: int}
     */
    public static function run(): array
    {
        if (! Schema::hasTable('purchase_order_items') || ! Schema::hasTable('vendors')) {
            return ['po_lines' => 0, 'purchase_orders' => 0, 'grn_lines' => 0];
        }

        $liquorVendorIds = Vendor::query()
            ->where('is_liquor_supplier', true)
            ->pluck('id')
            ->all();

        if ($liquorVendorIds === []) {
            return ['po_lines' => 0, 'purchase_orders' => 0, 'grn_lines' => 0];
        }

        $poLineCount = 0;
        $poIds = [];
        $grnLineCount = 0;

        DB::transaction(function () use ($liquorVendorIds, &$poLineCount, &$poIds, &$grnLineCount) {
            $lines = PurchaseOrderItem::query()
                ->whereHas('purchaseOrder', fn ($q) => $q->whereIn('vendor_id', $liquorVendorIds))
                ->with(['purchaseOrder', 'inventoryItem'])
                ->get();

            foreach ($lines as $poItem) {
                if ($poItem->tax_price_basis === PurchaseOrderLineAmounts::BASIS_NON_TAXABLE
                    && (float) $poItem->tax_amount <= 0) {
                    continue;
                }

                $inv = $poItem->inventoryItem;
                $qty = (float) $poItem->quantity_ordered;
                $up = (float) $poItem->unit_price;
                $unitCess = $inv ? CessSlabResolver::resolveUnitCess($inv, $up) : (float) ($poItem->unit_cess ?? 0);

                $computed = PurchaseOrderLineAmounts::compute(
                    $qty,
                    $up,
                    0,
                    PurchaseOrderLineAmounts::BASIS_NON_TAXABLE,
                    $unitCess
                );

                $poItem->update([
                    'tax_price_basis' => PurchaseOrderLineAmounts::BASIS_NON_TAXABLE,
                    'tax_rate' => 0,
                    'tax_type' => LiquorItemClassifier::resolvePoLineTaxType($inv),
                    'subtotal' => $computed['subtotal'],
                    'tax_amount' => 0,
                    'total_amount' => $computed['total_amount'],
                    'unit_cess' => $computed['unit_cess'],
                    'total_cess' => $computed['total_cess'],
                ]);

                $poLineCount++;
                $poIds[$poItem->purchase_order_id] = true;
            }

            foreach (array_keys($poIds) as $poId) {
                self::refreshPurchaseOrderHeader((int) $poId);
            }

            if (Schema::hasTable('grn_items')) {
                $grnLineCount = self::refreshGrnSnapshots(array_keys($poIds));
            }
        });

        if (Schema::hasTable('menu_items') && Schema::hasTable('inventory_items')) {
            DB::table('menu_items')
                ->whereIn('inventory_item_id', function ($q) {
                    $q->select('id')->from('inventory_items')->where('is_alcohol', true);
                })
                ->whereNotNull('tax_id')
                ->update(['tax_id' => null, 'updated_at' => now()]);
        }

        return [
            'po_lines' => $poLineCount,
            'purchase_orders' => count($poIds),
            'grn_lines' => $grnLineCount,
        ];
    }

    private static function refreshPurchaseOrderHeader(int $poId): void
    {
        $po = PurchaseOrder::with('items')->find($poId);
        if (! $po) {
            return;
        }

        $subtotal = 0.0;
        $tax = 0.0;
        $cess = 0.0;
        foreach ($po->items as $line) {
            $subtotal += (float) $line->subtotal;
            $tax += (float) $line->tax_amount;
            $cess += (float) $line->total_cess;
        }

        $parts = PurchaseOrderLineAmounts::computeHeaderTotals([
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total_cess_amount' => $cess,
            'transportation_charge' => (float) ($po->transportation_charge ?? 0),
            'loading_unloading_charge' => (float) ($po->loading_unloading_charge ?? 0),
        ]);

        $po->update([
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round($tax, 2),
            'total_cess_amount' => round($cess, 2),
            'total_amount' => $parts['total_amount'],
            'grand_total_payable' => $parts['grand_total_payable'],
        ]);
    }

    /**
     * @param  list<int>  $poIds
     */
    private static function refreshGrnSnapshots(array $poIds): int
    {
        if ($poIds === []) {
            return 0;
        }

        $updated = 0;
        $grnItems = DB::table('grn_items as gi')
            ->join('purchase_order_items as poi', 'poi.id', '=', 'gi.purchase_order_item_id')
            ->join('grns as g', 'g.id', '=', 'gi.grn_id')
            ->whereIn('poi.purchase_order_id', $poIds)
            ->where('g.status', GRN::STATUS_APPROVED)
            ->select('gi.id')
            ->pluck('gi.id');

        foreach ($grnItems as $grnItemId) {
            $grnLine = \App\Models\GrnItem::with(['grn.purchaseOrder.items', 'inventoryItem.tax'])->find($grnItemId);
            if (! $grnLine || ! $grnLine->grn?->purchaseOrder) {
                continue;
            }

            $po = $grnLine->grn->purchaseOrder;
            $po->loadMissing('items');
            $accepted = (float) $grnLine->quantity_accepted;
            if ($accepted <= 0) {
                continue;
            }

            $poItem = $po->items->firstWhere('id', $grnLine->purchase_order_item_id);
            if (! $poItem) {
                continue;
            }

            $grnMerch = LandedCostAllocator::grnMerchandiseSubtotalSum([$grnLine], $po);
            $eligible = TaxCreditPolicy::forInventoryItem($poItem->inventoryItem);
            $landed = LandedCostAllocator::forGrnLine($po, $poItem, $accepted, $grnMerch, $eligible);
            $postedUnit = InventoryCostingConfig::postedUnitCostFromAllocation($landed);
            $ordered = max(0.000001, (float) $poItem->quantity_ordered);

            DB::table('grn_items')->where('id', $grnLine->id)->update([
                'tax_rate' => 0,
                'line_subtotal_accepted' => $landed['line_subtotal_accepted'],
                'line_tax_accepted' => 0,
                'line_recoverable_tax_accepted' => 0,
                'line_non_recoverable_tax_accepted' => 0,
                'line_cess_accepted' => $landed['line_cess_accepted'],
                'line_freight_allocated' => $landed['line_freight_allocated'],
                'merchandise_unit_cost' => $landed['merchandise_unit'],
                'cess_unit_cost' => $landed['cess_unit'],
                'freight_unit_cost' => $landed['freight_unit'],
                'non_recoverable_tax_unit_cost' => 0,
                'landed_unit_cost' => $postedUnit,
                'tax_input_credit_eligible' => $landed['tax_input_credit_eligible'],
                'updated_at' => now(),
            ]);

            $updated++;
        }

        return $updated;
    }
}
