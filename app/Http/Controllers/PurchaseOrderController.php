<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
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
            PurchaseOrder::with('vendor', 'items.inventoryItem')->latest()->get()
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
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0',
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

            // Generate PO Number: PO-YYYY-XXX (Safely lock last record)
            $year = date('Y', strtotime($validated['order_date']));
            $lastPO = PurchaseOrder::whereYear('order_date', $year)
                ->orderBy('po_number', 'desc')
                ->lockForUpdate()
                ->first();
                
            $nextNum = 1;
            if ($lastPO && preg_match('/PO-\d{4}-(\d+)/', $lastPO->po_number, $matches)) {
                $nextNum = (int)$matches[1] + 1;
            }
            $poNumber = "PO-{$year}-".str_pad($nextNum, 3, '0', STR_PAD_LEFT);

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
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0',
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
                    'subtotal' => $line['subtotal'],
                    'tax_rate' => $line['tax_rate'] ?? 0,
                    'tax_amount' => $line['tax_amount'],
                    'total_amount' => $line['total_amount'],
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
        $this->checkPermission('manage-inventory');
        $purchaseOrder->delete();

        return response()->json(null, 204);
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

        DB::beginTransaction();
        try {
            // Lock the PO to serialize concurrent receipt requests
            $lockedPo = PurchaseOrder::lockForUpdate()->findOrFail($purchaseOrder->id);
            if ($lockedPo->status === 'received') {
                throw new \Exception('PO already received');
            }

            $updateData = ['status' => 'received', 'received_at' => now()];

            if ($request->hasFile('document')) {
                $path = $request->file('document')->store('po_documents', 'public');
                $updateData['received_document_path'] = $path;
            }

            $lockedPo->update($updateData);

            foreach ($lockedPo->items as $poItem) {
                // Lock the underlying inventory item to serialize WAC calculations globally
                /** @var \App\Models\InventoryItem|null $item */
                $item = \App\Models\InventoryItem::lockForUpdate()->find($poItem->inventory_item_id);
                if ($item) {
                    // Convert quantity based on conversion factor (e.g., 1 KG -> 1000 Grams)
                    $conversionFactor = floatval($item->conversion_factor ?? 1);
                    $convertedQuantity = $poItem->quantity_ordered * $conversionFactor;
                    $poUnitPrice = floatval($poItem->unit_price ?? 0);

                    // Unit cost for this transaction (per issue unit) — for auditing
                    $unitCostPerIssue = $conversionFactor > 0 ? $poUnitPrice / $conversionFactor : $poUnitPrice;
                    $totalCost = round($poItem->quantity_ordered * $poUnitPrice, 2);

                    // WAC uses sum of locations as on-hand qty. 
                    // Use max(0, stockBefore) to prevent skewed averages when correcting negative stock exceptions.
                    $stockBefore = \App\Models\InventoryItem::sumQuantityAcrossLocations($item->id);
                    $onHandForWac = max(0, $stockBefore);
                    $currentCost = (float) ($item->cost_price ?? 0);
                    
                    $denominator = $onHandForWac + $convertedQuantity;
                    $newCostPrice = $denominator > 0
                        ? (($onHandForWac * $currentCost) + ($poItem->quantity_ordered * $poUnitPrice)) / $denominator
                        : $unitCostPerIssue;

                    // ── 1. Update Location Stock atomically ──
                    DB::table('inventory_item_locations')->updateOrInsert(
                        ['inventory_item_id' => $item->id, 'inventory_location_id' => $locationId],
                        ['updated_at' => now(), 'created_at' => now()]
                    );
                    DB::table('inventory_item_locations')
                        ->where('inventory_item_id', $item->id)
                        ->where('inventory_location_id', $locationId)
                        ->increment('quantity', $convertedQuantity);

                    $item->update(['cost_price' => round($newCostPrice, 4)]);
                    InventoryItem::syncStoredCurrentStockFromLocations($item->id);

                    \App\Models\InventoryTransaction::create([
                        'inventory_item_id' => $item->id,
                        'inventory_location_id' => $locationId,
                        'type' => 'in',
                        'quantity' => $convertedQuantity,
                        'unit_cost' => round($unitCostPerIssue, 4),
                        'total_cost' => $totalCost,
                        'reason' => 'Purchase Receipt',
                        'notes' => 'From PO: '.$purchaseOrder->po_number.' at '.\App\Models\InventoryLocation::find($locationId)->name.' (Ordered: '.$poItem->quantity_ordered.' '.($item->purchaseUom->short_name ?? '').')',
                        'user_id' => auth()->id(),
                        'reference_type' => 'purchase_order',
                        'reference_id' => (string) $purchaseOrder->id,
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

            return response()->json($lockedPo->load('vendor', 'items.inventoryItem'));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
