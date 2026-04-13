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
        $poNumber = 'PO-'.$year.'-'.str_pad((string) $nextNum, 3, '0', STR_PAD_LEFT);

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
                    'stock_expected' => DB::raw('GREATEST(0, COALESCE(stock_expected, 0) - '.$q.')'),
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
}
