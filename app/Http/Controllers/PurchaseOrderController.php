<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\PurchaseOrderLineAmounts;
use App\Services\PurchaseOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    private function checkPermission(string $permission)
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index()
    {
        return response()->json(
            PurchaseOrder::with(['vendor', 'items.inventoryItem', 'creator'])->latest()->get()
        );
    }

    public function store(Request $request)
    {
        $this->checkPermission('manage-inventory');
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'location_id' => 'required|exists:inventory_locations,id',
            'order_date' => 'required|date',
            'expected_delivery_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0',
            'items.*.tax_price_basis' => 'nullable|string|in:' . PurchaseOrderLineAmounts::BASIS_EXCLUSIVE . ',' . PurchaseOrderLineAmounts::BASIS_INCLUSIVE . ',' . PurchaseOrderLineAmounts::BASIS_NON_TAXABLE,
        ]);

        try {
            $po = app(PurchaseOrderService::class)->createFromValidatedData($validated);

            return response()->json($po, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        return response()->json($purchaseOrder->load('vendor', 'items.inventoryItem', 'location', 'creator'));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->checkPermission('manage-inventory');
        if ($purchaseOrder->status !== 'draft') {
            return response()->json(['message' => 'Only draft orders can be edited'], 422);
        }

        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'location_id' => 'required|exists:inventory_locations,id',
            'order_date' => 'required|date',
            'expected_delivery_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0',
            'items.*.tax_price_basis' => 'nullable|string|in:' . PurchaseOrderLineAmounts::BASIS_EXCLUSIVE . ',' . PurchaseOrderLineAmounts::BASIS_INCLUSIVE . ',' . PurchaseOrderLineAmounts::BASIS_NON_TAXABLE,
        ]);

        DB::beginTransaction();
        try {
            $totals = PurchaseOrderService::applyLineAmountsToItems($validated['items']);
            $subtotal = $totals['subtotal'];
            $taxAmount = $totals['tax_amount'];

            PurchaseOrderService::subtractStockExpectedForPurchaseOrderLines($purchaseOrder);

            $purchaseOrder->update([
                'vendor_id' => $validated['vendor_id'],
                'location_id' => $validated['location_id'],
                'order_date' => $validated['order_date'],
                'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $subtotal + $taxAmount,
            ]);

            // Replace items
            $purchaseOrder->items()->delete();
            foreach ($validated['items'] as $line) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $purchaseOrder->id,
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

            PurchaseOrderService::addStockExpectedForPurchaseOrder($purchaseOrder->fresh(['items']));

            DB::commit();

            return response()->json($purchaseOrder->load('vendor', 'items.inventoryItem', 'location', 'creator'));
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function destroy(PurchaseOrder $purchaseOrder)
    {
        $this->checkPermission('manage-inventory');
        if ($purchaseOrder->status !== 'draft') {
            return response()->json(['message' => 'Only draft orders can be deleted. Use cancel instead.'], 422);
        }

        $reqId = $purchaseOrder->procurement_requisition_id;
        PurchaseOrderService::subtractStockExpectedForPurchaseOrderLines($purchaseOrder);
        $purchaseOrder->delete();
        PurchaseOrderService::syncProcurementRequisitionStatus($reqId);

        return response()->json(null, 204);
    }

    public function send(PurchaseOrder $purchaseOrder)
    {
        $this->checkPermission('manage-inventory');
        if ($purchaseOrder->status !== 'draft') {
            return response()->json(['message' => 'Only draft orders can be sent'], 422);
        }

        $purchaseOrder->update(['status' => 'sent']);
        PurchaseOrderService::syncProcurementRequisitionStatus($purchaseOrder->procurement_requisition_id);

        return response()->json($purchaseOrder->fresh()->load('vendor', 'items.inventoryItem', 'location', 'creator'));
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->checkPermission('manage-inventory');

        if (in_array($purchaseOrder->status, ['received', 'partial'], true)) {
            return response()->json(['message' => 'Received orders cannot be cancelled'], 422);
        }

        if ($purchaseOrder->status === 'cancelled') {
            return response()->json($purchaseOrder->load('vendor', 'items.inventoryItem', 'location', 'creator'));
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        if (in_array($purchaseOrder->status, ['draft', 'sent'], true)) {
            PurchaseOrderService::subtractStockExpectedForPurchaseOrderLines($purchaseOrder);
        }

        $reason = trim((string) ($validated['reason'] ?? ''));
        if ($reason !== '') {
            $purchaseOrder->notes = trim((string) ($purchaseOrder->notes ?? '') . "\nCancelled: " . $reason);
        }

        $purchaseOrder->status = 'cancelled';
        $purchaseOrder->save();

        PurchaseOrderService::syncProcurementRequisitionStatus($purchaseOrder->procurement_requisition_id);

        return response()->json($purchaseOrder->fresh()->load('vendor', 'items.inventoryItem', 'location', 'creator'));
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->checkPermission('manage-inventory');
        $validated = $request->validate([
            'location_id' => 'nullable|exists:inventory_locations,id',
            'document' => 'nullable|file|max:4096',
        ]);

        // Default to Main Store if no location provided
        $locationId = $validated['location_id'] ?? \App\Models\InventoryLocation::where('type', 'main_store')->first()?->id;

        if (! $locationId) {
            return response()->json(['message' => 'No target location available'], 422);
        }

        try {
            $po = app(PurchaseOrderService::class)->receivePurchaseOrder(
                $purchaseOrder,
                (int) $locationId,
                auth()->id()
            );

            if ($request->hasFile('document')) {
                $path = $request->file('document')->store('po_documents', 'public');
                $po->update(['received_document_path' => $path]);
            }

            return response()->json($po->load('vendor', 'items.inventoryItem', 'creator'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function pay(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->checkPermission('manage-inventory');
        $validated = $request->validate([
            'payment_method' => 'required|string',
            'payment_reference' => 'nullable|string',
            'paid_amount' => 'required|numeric|min:0',
            'invoice' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:4096',
        ]);

        DB::beginTransaction();
        try {
            // Lock PO for serialized payment assignments
            /** @var PurchaseOrder $lockedPo */
            $lockedPo = PurchaseOrder::lockForUpdate()->findOrFail($purchaseOrder->id);

            if ($lockedPo->status !== 'received' && $lockedPo->status !== 'partial') {
                throw new \Exception('Only received or partial orders can be paid');
            }
            if ($lockedPo->payment_status === 'paid') {
                throw new \Exception('Order is already fully paid');
            }

            if ($request->hasFile('invoice')) {
                $path = $request->file('invoice')->store('po_invoices', 'public');
                $lockedPo->invoice_path = $path;
            }

            $totalPaid = floatval($lockedPo->paid_amount) + floatval($validated['paid_amount']);

            $lockedPo->payment_status = $totalPaid >= floatval($lockedPo->total_amount) - 0.01 ? 'paid' : 'partially_paid';
            $lockedPo->payment_method = $validated['payment_method'];
            $lockedPo->payment_reference = $validated['payment_reference'];
            $lockedPo->paid_amount = $totalPaid;
            $lockedPo->paid_at = now();
            $lockedPo->save();

            DB::commit();

            return response()->json($lockedPo->load('vendor', 'items.inventoryItem', 'creator'));
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
