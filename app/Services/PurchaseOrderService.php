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
     * Mutates each line with subtotal, tax, cess, and merchandise total_amount.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{subtotal: float, tax_amount: float, total_cess_amount: float}
     */
    public static function applyLineAmountsToItems(array &$items): array
    {
        $subtotalSum = 0.0;
        $taxSum = 0.0;
        $cessSum = 0.0;

        $itemIds = array_values(array_unique(array_filter(array_map(
            fn ($line) => (int) ($line['inventory_item_id'] ?? 0),
            $items
        ))));
        $inventoryById = InventoryItem::query()
            ->whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');

        foreach ($items as &$line) {
            $basis = PurchaseOrderLineAmounts::normalizeBasis($line['tax_price_basis'] ?? null);
            $qty = (float) $line['quantity'];
            $up = (float) $line['unit_price'];
            $rate = (float) ($line['tax_rate'] ?? 0);

            if ($basis === PurchaseOrderLineAmounts::BASIS_NON_TAXABLE) {
                $rate = 0.0;
            }

            /** @var InventoryItem|null $inv */
            $inv = $inventoryById->get((int) $line['inventory_item_id']);
            $unitCess = $inv ? CessSlabResolver::resolveUnitCess($inv, $up) : 0.0;

            $computed = PurchaseOrderLineAmounts::compute($qty, $up, $rate, $basis, $unitCess);

            $line['tax_price_basis'] = $basis;
            $line['tax_rate'] = $computed['tax_rate'];
            $line['subtotal'] = $computed['subtotal'];
            $line['tax_amount'] = $computed['tax_amount'];
            $line['total_amount'] = $computed['total_amount'];
            $line['unit_cess'] = $computed['unit_cess'];
            $line['total_cess'] = $computed['total_cess'];

            $subtotalSum += $computed['subtotal'];
            $taxSum += $computed['tax_amount'];
            $cessSum += $computed['total_cess'];
        }
        unset($line);

        return [
            'subtotal' => $subtotalSum,
            'tax_amount' => $taxSum,
            'total_cess_amount' => $cessSum,
        ];
    }

    /**
     * @param  array<string, mixed>  $headerCharges  transportation_charge, loading_unloading_charge, tds_amount (optional)
     * @return array<string, float|null>
     */
    public static function buildHeaderFinancials(array $lineTotals, array $headerCharges = []): array
    {
        $transport = round(max(0, (float) ($headerCharges['transportation_charge'] ?? 0)), 2);
        $loading = round(max(0, (float) ($headerCharges['loading_unloading_charge'] ?? 0)), 2);
        $tds = isset($headerCharges['tds_amount']) && $headerCharges['tds_amount'] !== ''
            ? round(max(0, (float) $headerCharges['tds_amount']), 2)
            : null;

        $headerParts = PurchaseOrderLineAmounts::computeHeaderTotals([
            'subtotal' => $lineTotals['subtotal'],
            'tax_amount' => $lineTotals['tax_amount'],
            'total_cess_amount' => $lineTotals['total_cess_amount'],
            'transportation_charge' => $transport,
            'loading_unloading_charge' => $loading,
        ]);

        return [
            'subtotal' => round($lineTotals['subtotal'], 2),
            'tax_amount' => round($lineTotals['tax_amount'], 2),
            'total_cess_amount' => round($lineTotals['total_cess_amount'], 2),
            'transportation_charge' => $transport,
            'loading_unloading_charge' => $loading,
            'total_amount' => $headerParts['total_amount'],
            'grand_total_payable' => $headerParts['grand_total_payable'],
            'tds_amount' => $tds,
        ];
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
        $lineTotals = self::applyLineAmountsToItems($validated['items']);
        $financials = self::buildHeaderFinancials($lineTotals, $validated);

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
            'subtotal' => $financials['subtotal'],
            'tax_amount' => $financials['tax_amount'],
            'total_cess_amount' => $financials['total_cess_amount'],
            'transportation_charge' => $financials['transportation_charge'],
            'loading_unloading_charge' => $financials['loading_unloading_charge'],
            'total_amount' => $financials['total_amount'],
            'grand_total_payable' => $financials['grand_total_payable'],
            'tds_amount' => $financials['tds_amount'],
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
                'unit_cess' => $line['unit_cess'],
                'total_cess' => $line['total_cess'],
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
     * Per-unit landed cost (exclusive merchandise + cess per bottle) for WAC / loss valuation.
     */
    public static function landedUnitCostPerPurchaseUom(PurchaseOrderItem $poItem): float
    {
        $qty = (float) $poItem->quantity_ordered;
        if ($qty <= 0) {
            return 0.0;
        }

        $merchandise = (float) ($poItem->subtotal ?? 0);
        $cess = (float) ($poItem->total_cess ?? 0);

        return round(($merchandise + $cess) / $qty, 4);
    }

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
