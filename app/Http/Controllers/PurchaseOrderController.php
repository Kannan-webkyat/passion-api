<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function index()
    {
        return response()->json(
            PurchaseOrder::with('vendor', 'items.inventoryItem')->latest()->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'vendor_id'                => 'required|exists:vendors,id',
            'order_date'               => 'required|date',
            'expected_delivery_date'   => 'nullable|date',
            'notes'                    => 'nullable|string',
            'items'                    => 'required|array|min:1',
            'items.*.inventory_item_id'=> 'required|exists:inventory_items,id',
            'items.*.quantity'         => 'required|integer|min:1',
            'items.*.unit_price'       => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $total = collect($validated['items'])->sum(fn($l) => $l['quantity'] * $l['unit_price']);

            $po = PurchaseOrder::create([
                'vendor_id'              => $validated['vendor_id'],
                'order_date'             => $validated['order_date'],
                'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                'notes'                  => $validated['notes'] ?? null,
                'status'                 => 'draft',
                'total_amount'           => $total,
                'created_by'             => auth()->id(),
                'po_number'              => 'PO-' . strtoupper(uniqid()),
            ]);

            foreach ($validated['items'] as $line) {
                PurchaseOrderItem::create([
                    'purchase_order_id'  => $po->id,
                    'inventory_item_id'  => $line['inventory_item_id'],
                    'quantity_ordered'   => $line['quantity'],
                    'unit_price'         => $line['unit_price'],
                    'subtotal'           => $line['quantity'] * $line['unit_price'],
                ]);
            }

            DB::commit();
            return response()->json($po->load('vendor', 'items.inventoryItem'), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        return response()->json($purchaseOrder->load('vendor', 'items.inventoryItem'));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status !== 'draft') {
            return response()->json(['message' => 'Only draft orders can be edited'], 422);
        }

        $validated = $request->validate([
            'vendor_id'                => 'required|exists:vendors,id',
            'order_date'               => 'required|date',
            'expected_delivery_date'   => 'nullable|date',
            'notes'                    => 'nullable|string',
            'items'                    => 'required|array|min:1',
            'items.*.inventory_item_id'=> 'required|exists:inventory_items,id',
            'items.*.quantity'         => 'required|integer|min:1',
            'items.*.unit_price'       => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $total = collect($validated['items'])->sum(fn($l) => $l['quantity'] * $l['unit_price']);

            $purchaseOrder->update([
                'vendor_id'              => $validated['vendor_id'],
                'order_date'             => $validated['order_date'],
                'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                'notes'                  => $validated['notes'] ?? null,
                'total_amount'           => $total,
            ]);

            // Replace items
            $purchaseOrder->items()->delete();
            foreach ($validated['items'] as $line) {
                PurchaseOrderItem::create([
                    'purchase_order_id'  => $purchaseOrder->id,
                    'inventory_item_id'  => $line['inventory_item_id'],
                    'quantity_ordered'   => $line['quantity'],
                    'unit_price'         => $line['unit_price'],
                    'subtotal'           => $line['quantity'] * $line['unit_price'],
                ]);
            }

            DB::commit();
            return response()->json($purchaseOrder->load('vendor', 'items.inventoryItem'));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function destroy(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->delete();
        return response()->json(null, 204);
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status === 'received') {
            return response()->json(['message' => 'PO already received'], 422);
        }

        DB::beginTransaction();
        try {
            $updateData = ['status' => 'received', 'received_at' => now()];

            if ($request->hasFile('document')) {
                $path = $request->file('document')->store('po_documents', 'public');
                $updateData['received_document_path'] = $path;
            }

            $purchaseOrder->update($updateData);

            foreach ($purchaseOrder->items as $poItem) {
                // Update Inventory Item stock
                $item = $poItem->inventoryItem;
                if ($item) {
                    $item->increment('current_stock', $poItem->quantity_ordered);

                    // Create Transaction
                    \App\Models\InventoryTransaction::create([
                        'inventory_item_id' => $item->id,
                        'type' => 'in',
                        'quantity' => $poItem->quantity_ordered,
                        'reason' => 'Purchase Receipt',
                        'notes' => 'From PO: ' . $purchaseOrder->po_number,
                        'user_id' => auth()->id(),
                    ]);
                }
            }

            DB::commit();
            return response()->json($purchaseOrder->load('vendor', 'items.inventoryItem'));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function pay(Request $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status !== 'received' && $purchaseOrder->status !== 'partial') {
            return response()->json(['message' => 'Only received or partial orders can be paid'], 422);
        }

        $validated = $request->validate([
            'payment_method'    => 'required|string',
            'payment_reference' => 'nullable|string',
            'paid_amount'       => 'required|numeric|min:0',
            'invoice'           => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:4096'
        ]);

        if ($request->hasFile('invoice')) {
            $path = $request->file('invoice')->store('po_invoices', 'public');
            $purchaseOrder->invoice_path = $path;
        }

        $purchaseOrder->payment_status = $validated['paid_amount'] >= $purchaseOrder->total_amount ? 'paid' : 'partially_paid';
        $purchaseOrder->payment_method = $validated['payment_method'];
        $purchaseOrder->payment_reference = $validated['payment_reference'];
        $purchaseOrder->paid_amount = $validated['paid_amount'];
        $purchaseOrder->paid_at = now();
        $purchaseOrder->save();

        return response()->json($purchaseOrder->load('vendor', 'items.inventoryItem'));
    }
}
