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
            'location_id'              => 'required|exists:inventory_locations,id',
            'order_date'               => 'required|date',
            'expected_delivery_date'   => 'nullable|date',
            'notes'                    => 'nullable|string',
            'items'                    => 'required|array|min:1',
            'items.*.inventory_item_id'=> 'required|exists:inventory_items,id',
            'items.*.quantity'         => 'required|integer|min:1',
            'items.*.unit_price'       => 'required|numeric|min:0',
            'items.*.tax_rate'         => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $subtotal = 0;
            $taxAmount = 0;
            
            foreach ($validated['items'] as &$line) {
                $line['subtotal'] = $line['quantity'] * $line['unit_price'];
                $line['tax_amount'] = ($line['subtotal'] * ($line['tax_rate'] ?? 0)) / 100;
                $line['total_amount'] = $line['subtotal'] + $line['tax_amount'];
                
                $subtotal += $line['subtotal'];
                $taxAmount += $line['tax_amount'];
            }

            // Generate PO Number: PO-YYYY-XXX
            $year = date('Y', strtotime($validated['order_date']));
            $lastPO = PurchaseOrder::whereYear('order_date', $year)->orderBy('id', 'desc')->first();
            $nextNum = $lastPO ? ((int)explode('-', $lastPO->po_number)[2] + 1) : 1;
            $poNumber = "PO-{$year}-" . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

            $po = PurchaseOrder::create([
                'vendor_id'              => $validated['vendor_id'],
                'location_id'            => $validated['location_id'],
                'order_date'             => $validated['order_date'],
                'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                'notes'                  => $validated['notes'] ?? null,
                'status'                 => 'draft',
                'subtotal'               => $subtotal,
                'tax_amount'             => $taxAmount,
                'total_amount'           => $subtotal + $taxAmount,
                'created_by'             => auth()->id(),
                'po_number'              => $poNumber,
            ]);

            foreach ($validated['items'] as $line) {
                PurchaseOrderItem::create([
                    'purchase_order_id'  => $po->id,
                    'inventory_item_id'  => $line['inventory_item_id'],
                    'quantity_ordered'   => $line['quantity'],
                    'unit_price'         => $line['unit_price'],
                    'subtotal'           => $line['subtotal'],
                    'tax_rate'           => $line['tax_rate'] ?? 0,
                    'tax_amount'         => $line['tax_amount'],
                    'total_amount'       => $line['total_amount'],
                ]);
            }

            DB::commit();
            return response()->json($po->load('vendor', 'items.inventoryItem', 'location'), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        return response()->json($purchaseOrder->load('vendor', 'items.inventoryItem', 'location'));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status !== 'draft') {
            return response()->json(['message' => 'Only draft orders can be edited'], 422);
        }

        $validated = $request->validate([
            'vendor_id'                => 'required|exists:vendors,id',
            'location_id'              => 'required|exists:inventory_locations,id',
            'order_date'               => 'required|date',
            'expected_delivery_date'   => 'nullable|date',
            'notes'                    => 'nullable|string',
            'items'                    => 'required|array|min:1',
            'items.*.inventory_item_id'=> 'required|exists:inventory_items,id',
            'items.*.quantity'         => 'required|integer|min:1',
            'items.*.unit_price'       => 'required|numeric|min:0',
            'items.*.tax_rate'         => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $subtotal = 0;
            $taxAmount = 0;
            
            foreach ($validated['items'] as &$line) {
                $line['subtotal'] = $line['quantity'] * $line['unit_price'];
                $line['tax_amount'] = ($line['subtotal'] * ($line['tax_rate'] ?? 0)) / 100;
                $line['total_amount'] = $line['subtotal'] + $line['tax_amount'];
                
                $subtotal += $line['subtotal'];
                $taxAmount += $line['tax_amount'];
            }

            $purchaseOrder->update([
                'vendor_id'              => $validated['vendor_id'],
                'location_id'            => $validated['location_id'],
                'order_date'             => $validated['order_date'],
                'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                'notes'                  => $validated['notes'] ?? null,
                'subtotal'               => $subtotal,
                'tax_amount'             => $taxAmount,
                'total_amount'           => $subtotal + $taxAmount,
            ]);

            // Replace items
            $purchaseOrder->items()->delete();
            foreach ($validated['items'] as $line) {
                PurchaseOrderItem::create([
                    'purchase_order_id'  => $purchaseOrder->id,
                    'inventory_item_id'  => $line['inventory_item_id'],
                    'quantity_ordered'   => $line['quantity'],
                    'unit_price'         => $line['unit_price'],
                    'subtotal'           => $line['subtotal'],
                    'tax_rate'           => $line['tax_rate'] ?? 0,
                    'tax_amount'         => $line['tax_amount'],
                    'total_amount'       => $line['total_amount'],
                ]);
            }

            DB::commit();
            return response()->json($purchaseOrder->load('vendor', 'items.inventoryItem', 'location'));
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

        $validated = $request->validate([
            'location_id' => 'nullable|exists:inventory_locations,id',
            'document'    => 'nullable|file|max:4096'
        ]);

        // Default to Main Store if no location provided
        $locationId = $validated['location_id'] ?? \App\Models\InventoryLocation::where('type', 'main_store')->first()?->id;

        if (!$locationId) {
            return response()->json(['message' => 'No target location available'], 422);
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
                $item = $poItem->inventoryItem;
                if ($item) {
                    // 1. Update Global Total Stock
                    $item->increment('current_stock', $poItem->quantity_ordered);

                    // 2. Update Location Specific Stock
                    DB::table('inventory_item_locations')->updateOrInsert(
                        [
                            'inventory_item_id' => $item->id,
                            'inventory_location_id' => $locationId
                        ],
                        [
                            'quantity' => DB::raw('quantity + ' . $poItem->quantity_ordered),
                            'updated_at' => now(),
                            'created_at' => now()
                        ]
                    );

                    // 3. Create Transaction log
                    \App\Models\InventoryTransaction::create([
                        'inventory_item_id' => $item->id,
                        'inventory_location_id' => $locationId,
                        'type' => 'in',
                        'quantity' => $poItem->quantity_ordered,
                        'reason' => 'Purchase Receipt',
                        'notes' => 'From PO: ' . $purchaseOrder->po_number . ' at ' . \App\Models\InventoryLocation::find($locationId)->name,
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
