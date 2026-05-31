<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTransaction;
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

    /** @return array<string, string> */
    private function poHeaderChargeRules(): array
    {
        return [
            'transportation_charge' => 'nullable|numeric|min:0',
            'loading_unloading_charge' => 'nullable|numeric|min:0',
            'tds_amount' => 'nullable|numeric|min:0',
        ];
    }

    /** @return array<string, string> */
    private function poLineRules(): array
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0',
            'items.*.tax_price_basis' => 'nullable|string|in:'.PurchaseOrderLineAmounts::BASIS_EXCLUSIVE.','.PurchaseOrderLineAmounts::BASIS_INCLUSIVE.','.PurchaseOrderLineAmounts::BASIS_NON_TAXABLE,
        ];
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
        $validated = $request->validate(array_merge([
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

        $validated = $request->validate(array_merge([
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
            $lineTotals = PurchaseOrderService::applyLineAmountsToItems($validated['items']);
            $financials = PurchaseOrderService::buildHeaderFinancials($lineTotals, $validated);

            PurchaseOrderService::subtractStockExpectedForPurchaseOrderLines($purchaseOrder);

            $purchaseOrder->update([
                'vendor_id' => $validated['vendor_id'],
                'location_id' => $validated['location_id'],
                'order_date' => $validated['order_date'],
                'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'subtotal' => $financials['subtotal'],
                'tax_amount' => $financials['tax_amount'],
                'total_cess_amount' => $financials['total_cess_amount'],
                'transportation_charge' => $financials['transportation_charge'],
                'loading_unloading_charge' => $financials['loading_unloading_charge'],
                'total_amount' => $financials['total_amount'],
                'grand_total_payable' => $financials['grand_total_payable'],
                'tds_amount' => $financials['tds_amount'],
            ]);

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
                    'unit_cess' => $line['unit_cess'],
                    'total_cess' => $line['total_cess'],
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
            'items' => 'required|array|min:1',
            'items.*.purchase_order_item_id' => 'required|integer',
            'items.*.quantity_received' => 'required|integer|min:0',
            'items.*.quantity_damaged_transit' => 'nullable|integer|min:0',
            'items.*.quantity_broken_transit' => 'nullable|integer|min:0',
        ]);

        $locationId = $validated['location_id'] ?? InventoryLocation::where('type', 'main_store')->first()?->id;
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
            /** @var PurchaseOrder $lockedPo */
            $lockedPo = PurchaseOrder::lockForUpdate()->findOrFail($purchaseOrder->id);

            if (! in_array($lockedPo->status, ['received', 'partial'], true)) {
                throw new \Exception('Only received or partial orders can be paid');
            }
            if ($lockedPo->payment_status === 'paid') {
                throw new \Exception('Order is already fully paid');
            }

            if ($request->hasFile('invoice')) {
                $path = $request->file('invoice')->store('po_invoices', 'public');
                $lockedPo->invoice_path = $path;
            }

            $payable = $lockedPo->payableAmount();
            $totalPaid = floatval($lockedPo->paid_amount) + floatval($validated['paid_amount']);

            $lockedPo->payment_status = $totalPaid >= $payable - 0.01 ? 'paid' : 'partially_paid';
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
