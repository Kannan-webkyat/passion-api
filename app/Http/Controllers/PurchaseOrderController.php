<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\VendorPayment;
use App\Exceptions\LiquorTaxValidationException;
use App\Services\Accounting\VendorPaymentPoster;
use App\Services\GrnService;
use App\Services\InventoryAuthorization;
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
        InventoryAuthorization::assertViewProcurement();

        return response()->json(
            PurchaseOrder::with([
                'vendor',
                'items.inventoryItem.tax',
                'creator',
                'vendorPayments' => fn ($q) => $q->orderByDesc('paid_at'),
                'vendorPayments.paidByUser:id,name',
            ])->latest()->get()
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
        ], $this->poHeaderChargeRules(), $this->poLineRules()));

        try {
            $po = app(PurchaseOrderService::class)->createFromValidatedData($validated);

            return response()->json($po, 201);
        } catch (LiquorTaxValidationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        InventoryAuthorization::assertViewProcurement();

        return response()->json($purchaseOrder->load([
            'vendor',
            'items.inventoryItem.tax',
            'location',
            'creator',
            'vendorPayments' => fn ($q) => $q->orderByDesc('paid_at'),
            'vendorPayments.paidByUser:id,name',
        ]));
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
        ], $this->poHeaderChargeRules(), $this->poLineRules()));

        DB::beginTransaction();
        try {
            $lineTotals = PurchaseOrderService::applyLineAmountsToItems($validated['items'], (int) $validated['vendor_id']);
            $financials = PurchaseOrderService::buildHeaderFinancials($lineTotals, $validated);

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
                    'tax_type' => $line['tax_type'] ?? \App\Services\PurchaseOrderLineAmounts::resolveTaxType(null),
                    'tax_amount' => $line['tax_amount'],
                    'unit_cess' => $line['unit_cess'],
                    'total_cess' => $line['total_cess'],
                    'total_amount' => $line['total_amount'],
                ]);
            }

            DB::commit();

            return response()->json($purchaseOrder->load('vendor', 'items.inventoryItem.tax', 'location', 'creator'));
        } catch (LiquorTaxValidationException $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
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
        PurchaseOrderService::addStockExpectedForPurchaseOrder($purchaseOrder->fresh(['items']));
        PurchaseOrderService::syncProcurementRequisitionStatus($purchaseOrder->procurement_requisition_id);

        return response()->json($purchaseOrder->fresh()->load('vendor', 'items.inventoryItem.tax', 'location', 'creator'));
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->checkPermission('manage-inventory');

        if (in_array($purchaseOrder->status, ['received', 'partial'], true)) {
            return response()->json(['message' => 'Received orders cannot be cancelled'], 422);
        }

        if ($purchaseOrder->status === 'cancelled') {
            return response()->json($purchaseOrder->load('vendor', 'items.inventoryItem.tax', 'location', 'creator'));
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        if (in_array($purchaseOrder->status, ['sent', 'partial'], true)) {
            PurchaseOrderService::subtractStockExpectedForPurchaseOrderLines($purchaseOrder);
        }

        $reason = trim((string) ($validated['reason'] ?? ''));
        if ($reason !== '') {
            $purchaseOrder->notes = trim((string) ($purchaseOrder->notes ?? '') . "\nCancelled: " . $reason);
        }

        $purchaseOrder->status = 'cancelled';
        $purchaseOrder->save();

        PurchaseOrderService::syncProcurementRequisitionStatus($purchaseOrder->procurement_requisition_id);

        return response()->json($purchaseOrder->fresh()->load('vendor', 'items.inventoryItem.tax', 'location', 'creator'));
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder)
    {
        InventoryAuthorization::assertInspectGrn();

        $validated = $request->validate([
            'location_id' => 'nullable|exists:inventory_locations,id',
            'document' => 'nullable|file|max:4096',
            'items' => 'nullable|array|min:1',
            'items.*.purchase_order_item_id' => 'required_with:items|integer',
            'items.*.quantity_received' => 'required_with:items|numeric|min:0',
            'items.*.quantity_rejected' => 'nullable|numeric|min:0',
            'items.*.rejection_reason' => 'nullable|string|max:255',
        ]);

        $locationId = GrnService::resolveMainStoreLocationId(
            isset($validated['location_id']) ? (int) $validated['location_id'] : null
        );

        try {
            $lines = null;
            if (! empty($validated['items'])) {
                $lines = array_map(fn ($row) => [
                    'purchase_order_item_id' => (int) $row['purchase_order_item_id'],
                    'quantity_received' => (float) $row['quantity_received'],
                    'quantity_rejected' => (float) ($row['quantity_rejected'] ?? 0),
                    'rejection_reason' => $row['rejection_reason'] ?? null,
                ], $validated['items']);
            }

            $grn = app(GrnService::class)->receivePurchaseOrderForInspection(
                $purchaseOrder,
                (int) $locationId,
                $lines,
                auth()->id(),
                $request->file('document')
            );

            $po = $purchaseOrder->fresh(['vendor', 'items.inventoryItem.tax', 'location', 'creator', 'grns']);

            return response()->json([
                'purchase_order' => $po,
                'grn' => $grn->load('items.inventoryItem.tax'),
                'message' => 'GRN '.$grn->grn_number.' submitted — complete quality inspection before approval.',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function pay(Request $request, PurchaseOrder $purchaseOrder)
    {
        InventoryAuthorization::assertPayVendor();
        $validated = $request->validate([
            'payment_method' => 'required|string',
            'payment_reference' => 'nullable|string',
            'paid_amount' => 'required|numeric|min:0.01',
            'invoice' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:4096',
            'notes' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            /** @var PurchaseOrder $lockedPo */
            $lockedPo = PurchaseOrder::lockForUpdate()->findOrFail($purchaseOrder->id);

            if (! in_array($lockedPo->status, ['received', 'partial'], true)) {
                throw new \Exception('Only received or partial orders can be paid');
            }

            $payable = $lockedPo->payableAmount();
            $alreadyPaid = round((float) $lockedPo->paid_amount, 2);
            $paymentAmount = round((float) $validated['paid_amount'], 2);

            if ($alreadyPaid + $paymentAmount > $payable + 0.01) {
                throw new \Exception('Payment exceeds remaining payable amount');
            }

            $invoicePath = $lockedPo->invoice_path;
            if ($request->hasFile('invoice')) {
                $invoicePath = $request->file('invoice')->store('po_invoices', 'public');
            }

            $vendorPayment = VendorPayment::create([
                'purchase_order_id' => $lockedPo->id,
                'vendor_id' => $lockedPo->vendor_id,
                'amount' => $paymentAmount,
                'payment_method' => $validated['payment_method'],
                'payment_reference' => $validated['payment_reference'] ?? null,
                'paid_at' => now(),
                'paid_by' => auth()->id(),
                'invoice_path' => $invoicePath,
                'notes' => $validated['notes'] ?? null,
            ]);

            $totalPaid = round($alreadyPaid + $paymentAmount, 2);

            $lockedPo->payment_status = $totalPaid >= $payable - 0.01 ? 'paid' : 'partially_paid';
            $lockedPo->payment_method = $validated['payment_method'];
            $lockedPo->payment_reference = $validated['payment_reference'] ?? null;
            $lockedPo->paid_amount = $totalPaid;
            $lockedPo->paid_at = now();
            if ($invoicePath) {
                $lockedPo->invoice_path = $invoicePath;
            }
            $lockedPo->save();

            app(VendorPaymentPoster::class)->post($vendorPayment, auth()->id());

            DB::commit();

            return response()->json(
                $lockedPo->fresh()->load([
                    'vendor',
                    'items.inventoryItem.tax',
                    'creator',
                    'vendorPayments' => fn ($q) => $q->orderByDesc('paid_at'),
                    'vendorPayments.paidByUser:id,name',
                ])
            );
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
