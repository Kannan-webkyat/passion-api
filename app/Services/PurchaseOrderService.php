<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function createFromValidatedData(array $validated, ?int $procurementRequisitionId = null): PurchaseOrder
    {
        return DB::transaction(function () use ($validated, $procurementRequisitionId) {
            return $this->createFromValidatedDataWithinTransaction($validated, $procurementRequisitionId);
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function createFromValidatedDataWithinTransaction(array $validated, ?int $procurementRequisitionId = null): PurchaseOrder
    {
        $subtotal = 0;
        $taxAmount = 0;

        foreach ($validated['items'] as &$line) {
            $line['subtotal'] = $line['quantity'] * $line['unit_price'];
            $line['tax_amount'] = ($line['subtotal'] * ($line['tax_rate'] ?? 0)) / 100;
            $line['total_amount'] = $line['subtotal'] + $line['tax_amount'];

            $subtotal += $line['subtotal'];
            $taxAmount += $line['tax_amount'];
        }

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
            'status' => 'draft',
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
                'subtotal' => $line['subtotal'],
                'tax_rate' => $line['tax_rate'] ?? 0,
                'tax_amount' => $line['tax_amount'],
                'total_amount' => $line['total_amount'],
            ]);
        }

        self::addStockExpectedForPurchaseOrder($po->fresh(['items']));

        return $po->load('vendor', 'items.inventoryItem', 'location');
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
}
