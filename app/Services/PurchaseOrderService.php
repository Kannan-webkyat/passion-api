<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\ProcurementRequisition;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    /**
     * Mutates each line with subtotal (exclusive net), tax_amount, total_amount, tax_rate, tax_price_basis.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{subtotal: float, tax_amount: float}
     */
    public static function applyLineAmountsToItems(array &$items): array
    {
        $subtotalSum = 0.0;
        $taxSum = 0.0;

        foreach ($items as &$line) {
            $basis = PurchaseOrderLineAmounts::normalizeBasis($line['tax_price_basis'] ?? null);
            $qty = (float) $line['quantity'];
            $up = (float) $line['unit_price'];
            $rate = (float) ($line['tax_rate'] ?? 0);

            if ($basis === PurchaseOrderLineAmounts::BASIS_NON_TAXABLE) {
                $rate = 0.0;
            }

            $computed = PurchaseOrderLineAmounts::compute($qty, $up, $rate, $basis);

            $line['tax_price_basis'] = $basis;
            $line['tax_rate'] = $computed['tax_rate'];
            $line['subtotal'] = $computed['subtotal'];
            $line['tax_amount'] = $computed['tax_amount'];
            $line['total_amount'] = $computed['total_amount'];

            $subtotalSum += $computed['subtotal'];
            $taxSum += $computed['tax_amount'];
        }
        unset($line);

        return ['subtotal' => $subtotalSum, 'tax_amount' => $taxSum];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function createFromValidatedData(array $validated, ?int $procurementRequisitionId = null, string $initialStatus = 'draft'): PurchaseOrder
    {
        return DB::transaction(function () use ($validated, $procurementRequisitionId, $initialStatus) {
            return $this->createFromValidatedDataWithinTransaction($validated, $procurementRequisitionId, $initialStatus);
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function createFromValidatedDataWithinTransaction(array $validated, ?int $procurementRequisitionId = null, string $initialStatus = 'draft'): PurchaseOrder
    {
        $totals = self::applyLineAmountsToItems($validated['items']);
        $subtotal = $totals['subtotal'];
        $taxAmount = $totals['tax_amount'];

        $year = date('Y', strtotime($validated['order_date']));
        $lastPO = PurchaseOrder::whereYear('order_date', $year)
            ->orderBy('po_number', 'desc')
            ->lockForUpdate()
            ->first();

        $nextNum = 1;
        if ($lastPO && preg_match('/PO-\d{4}-(\d+)/', $lastPO->po_number, $matches)) {
            $nextNum = (int) $matches[1] + 1;
        }
        $poNumber = 'PO-' . $year . '-' . str_pad((string) $nextNum, 3, '0', STR_PAD_LEFT);

        $po = PurchaseOrder::create([
            'vendor_id' => $validated['vendor_id'],
            'location_id' => $validated['location_id'],
            'order_date' => $validated['order_date'],
            'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => $initialStatus,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total_amount' => $subtotal + $taxAmount,
            'created_by' => auth()->id(),
            'po_number' => $poNumber,
            'procurement_requisition_id' => $procurementRequisitionId,
        ]);

        foreach ($validated['items'] as $line) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'inventory_item_id' => $line['inventory_item_id'],
                'quantity_ordered' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'tax_price_basis' => $line['tax_price_basis'],
                'subtotal' => $line['subtotal'],
                'tax_rate' => $line['tax_rate'] ?? 0,
                'tax_amount' => $line['tax_amount'],
                'total_amount' => $line['total_amount'],
            ]);
        }

        self::addStockExpectedForPurchaseOrder($po->fresh(['items']));

        return $po->load('vendor', 'items.inventoryItem', 'location', 'creator');
    }

    public static function addStockExpectedForPurchaseOrder(PurchaseOrder $po): void
    {
        $po->loadMissing('items');
        foreach ($po->items as $line) {
            $q = (float) $line->quantity_ordered;
            InventoryItem::where('id', $line->inventory_item_id)->increment('stock_expected', $q);
        }
    }

    public static function subtractStockExpectedForPurchaseOrderLines(PurchaseOrder $po): void
    {
        $po->loadMissing('items');
        foreach ($po->items as $line) {
            $q = (float) $line->quantity_ordered;
            DB::table('inventory_items')
                ->where('id', $line->inventory_item_id)
                ->update([
                    'stock_expected' => DB::raw('GREATEST(0, COALESCE(stock_expected, 0) - ' . $q . ')'),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Keep procurement requisition status consistent with linked POs.
     *
     * Rules:
     * - If there is at least one non-cancelled PO linked: status = po_generated
     * - If there are zero non-cancelled linked POs and status was po_generated: revert to comparison
     */
    public static function syncProcurementRequisitionStatus(?int $procurementRequisitionId): void
    {
        if (! $procurementRequisitionId) {
            return;
        }

        /** @var ProcurementRequisition|null $req */
        $req = ProcurementRequisition::find($procurementRequisitionId);
        if (! $req) {
            return;
        }

        $activePoCount = PurchaseOrder::where('procurement_requisition_id', $procurementRequisitionId)
            ->where('status', '!=', 'cancelled')
            ->count();

        if ($activePoCount > 0 && $req->status !== 'po_generated') {
            $req->update(['status' => 'po_generated']);
            return;
        }

        if ($activePoCount === 0 && $req->status === 'po_generated') {
            $req->update(['status' => 'comparison']);
        }
    }

    /**
     * Receive a sent PO into a store location (production GRN / stock-in path).
     *
     * @throws \RuntimeException
     */
    public function receivePurchaseOrder(PurchaseOrder $purchaseOrder, int $locationId, ?int $userId = null): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder, $locationId, $userId) {
            $lockedPo = PurchaseOrder::lockForUpdate()->findOrFail($purchaseOrder->id);
            if ($lockedPo->status === 'received') {
                throw new \RuntimeException('PO already received');
            }
            if ($lockedPo->status !== 'sent') {
                throw new \RuntimeException('Send the PO before receiving stock');
            }

            $lockedPo->update([
                'status' => 'received',
                'received_at' => now(),
            ]);

            self::subtractStockExpectedForPurchaseOrderLines($lockedPo);
            $lockedPo->load('items');

            foreach ($lockedPo->items as $poItem) {
                $poItem->update([
                    'quantity_received' => (int) $poItem->quantity_ordered,
                ]);

                /** @var InventoryItem|null $item */
                $item = InventoryItem::lockForUpdate()->find($poItem->inventory_item_id);
                if (! $item) {
                    continue;
                }

                $conversionFactor = floatval($item->conversion_factor ?? 1);
                $convertedQuantity = $poItem->quantity_ordered * $conversionFactor;

                $lineExclusiveTotal = (float) ($poItem->subtotal ?? 0);
                $qtyOrdered = (float) $poItem->quantity_ordered;
                $exclusiveUnitInPurchaseUom = $qtyOrdered > 0 ? $lineExclusiveTotal / $qtyOrdered : 0.0;

                $unitCostPerIssue = $conversionFactor > 0 ? $exclusiveUnitInPurchaseUom / $conversionFactor : $exclusiveUnitInPurchaseUom;
                $totalCost = round($lineExclusiveTotal, 2);

                $stockBeforeIssue = InventoryItem::sumQuantityAcrossLocations($item->id);
                $onHandForWacIssue = max(0, $stockBeforeIssue);
                $onHandForWacPurchase = $onHandForWacIssue / ($conversionFactor ?: 1);
                $currentPurchasePrice = (float) ($item->cost_price ?? 0);
                $newPurchaseQty = (float) $poItem->quantity_ordered;

                $denominatorPurchase = $onHandForWacPurchase + $newPurchaseQty;
                $newPurchaseCost = $denominatorPurchase > 0
                    ? (($onHandForWacPurchase * $currentPurchasePrice) + ($newPurchaseQty * $exclusiveUnitInPurchaseUom)) / $denominatorPurchase
                    : $exclusiveUnitInPurchaseUom;

                DB::table('inventory_item_locations')->updateOrInsert(
                    ['inventory_item_id' => $item->id, 'inventory_location_id' => $locationId],
                    ['updated_at' => now(), 'created_at' => now()]
                );
                DB::table('inventory_item_locations')
                    ->where('inventory_item_id', $item->id)
                    ->where('inventory_location_id', $locationId)
                    ->increment('quantity', $convertedQuantity);

                $item->update(['cost_price' => round($newPurchaseCost, 4)]);
                InventoryItem::syncStoredCurrentStockFromLocations($item->id);

                $locationName = \App\Models\InventoryLocation::find($locationId)?->name ?? 'Store';

                \App\Models\InventoryTransaction::create([
                    'inventory_item_id' => $item->id,
                    'inventory_location_id' => $locationId,
                    'type' => 'in',
                    'quantity' => $convertedQuantity,
                    'unit_cost' => round($unitCostPerIssue, 4),
                    'total_cost' => $totalCost,
                    'reason' => 'Purchase Receipt',
                    'notes' => 'From PO: ' . $lockedPo->po_number . ' at ' . $locationName . ' (Ordered: ' . $poItem->quantity_ordered . ' ' . ($item->purchaseUom->short_name ?? '') . ')',
                    'user_id' => $userId,
                    'reference_type' => 'purchase_order',
                    'reference_id' => (string) $lockedPo->id,
                ]);
            }

            return $lockedPo->fresh(['vendor', 'items.inventoryItem', 'location', 'creator']);
        });
    }
}
