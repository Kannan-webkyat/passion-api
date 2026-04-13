<?php

namespace App\Http\Controllers;

use App\Models\ProcurementRequisition;
use App\Models\ProcurementRequisitionItem;
use App\Models\Setting;
use App\Models\Vendor;
use App\Services\PurchaseOrderLineAmounts;
use App\Services\PurchaseOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProcurementRequisitionController extends Controller
{
    private function checkPermission(string $permission): void
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index()
    {
        return response()->json(
            ProcurementRequisition::with([
                'location',
                'items.inventoryItem.purchaseUom',
                'items.vendors',
                'creator',
            ])->latest()->get()
        );
    }

    public function store(Request $request)
    {
        $this->checkPermission('manage-inventory');
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'location_id' => 'required|exists:inventory_locations,id',
            'order_date' => 'required|date',
            'expected_delivery_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.vendor_ids' => 'required|array|min:1',
            'items.*.vendor_ids.*' => 'required|exists:vendors,id',
        ]);

        return DB::transaction(function () use ($validated) {
            $year = date('Y', strtotime($validated['order_date']));
            $last = ProcurementRequisition::whereYear('order_date', $year)
                ->orderBy('reference_number', 'desc')
                ->lockForUpdate()
                ->first();
            $nextNum = 1;
            if ($last && preg_match('/PR-\d{4}-(\d+)/', $last->reference_number, $m)) {
                $nextNum = (int) $m[1] + 1;
            }
            $ref = 'PR-'.$year.'-'.str_pad((string) $nextNum, 3, '0', STR_PAD_LEFT);

            $req = ProcurementRequisition::create([
                'reference_number' => $ref,
                'title' => $validated['title'] ?? null,
                'status' => 'draft',
                'location_id' => $validated['location_id'],
                'order_date' => $validated['order_date'],
                'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($validated['items'] as $idx => $row) {
                $item = ProcurementRequisitionItem::create([
                    'procurement_requisition_id' => $req->id,
                    'inventory_item_id' => $row['inventory_item_id'],
                    'quantity' => $row['quantity'],
                    'sort_order' => $idx,
                ]);
                $uniqueVendorIds = array_values(array_unique($row['vendor_ids']));
                $item->vendors()->sync($uniqueVendorIds);
            }

            return response()->json($req->load([
                'location',
                'items.inventoryItem.purchaseUom',
                'items.vendors',
                'creator',
            ]), 201);
        });
    }

    public function show(ProcurementRequisition $procurementRequisition)
    {
        return response()->json($procurementRequisition->load([
            'location',
            'items.inventoryItem.tax',
            'items.inventoryItem.purchaseUom',
            'items.vendors',
            'creator',
            'purchaseOrders.vendor',
            'purchaseOrders.creator',
        ]));
    }

    public function update(Request $request, ProcurementRequisition $procurementRequisition)
    {
        $this->checkPermission('manage-inventory');
        if ($procurementRequisition->status === 'po_generated') {
            return response()->json(['message' => 'Cannot edit after purchase orders are generated'], 422);
        }
        if (! in_array($procurementRequisition->status, ['draft', 'quotation_requested'], true)) {
            return response()->json(['message' => 'Use comparison tools to adjust vendors and prices'], 422);
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'location_id' => 'required|exists:inventory_locations,id',
            'order_date' => 'required|date',
            'expected_delivery_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.vendor_ids' => 'required|array|min:1',
            'items.*.vendor_ids.*' => 'required|exists:vendors,id',
        ]);

        return DB::transaction(function () use ($validated, $procurementRequisition) {
            $procurementRequisition->update([
                'title' => $validated['title'] ?? null,
                'location_id' => $validated['location_id'],
                'order_date' => $validated['order_date'],
                'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            $procurementRequisition->items()->delete();

            foreach ($validated['items'] as $idx => $row) {
                $item = ProcurementRequisitionItem::create([
                    'procurement_requisition_id' => $procurementRequisition->id,
                    'inventory_item_id' => $row['inventory_item_id'],
                    'quantity' => $row['quantity'],
                    'sort_order' => $idx,
                ]);
                $item->vendors()->sync(array_values(array_unique($row['vendor_ids'])));
            }

            return response()->json($procurementRequisition->fresh()->load([
                'location',
                'items.inventoryItem.purchaseUom',
                'items.vendors',
                'creator',
            ]));
        });
    }

    public function destroy(ProcurementRequisition $procurementRequisition)
    {
        $this->checkPermission('manage-inventory');
        if ($procurementRequisition->status === 'po_generated') {
            return response()->json(['message' => 'Cannot delete after POs are generated'], 422);
        }
        $procurementRequisition->delete();

        return response()->json(null, 204);
    }

    public function requestQuotes(ProcurementRequisition $procurementRequisition)
    {
        $this->checkPermission('manage-inventory');
        if ($procurementRequisition->status === 'po_generated') {
            return response()->json(['message' => 'Invalid status'], 422);
        }
        if ($procurementRequisition->status !== 'draft') {
            return response()->json([
                'message' => $procurementRequisition->status === 'comparison'
                    ? 'Comparison is already in progress.'
                    : 'Quotation request can only be sent while the requisition is in draft.',
            ], 422);
        }
        // Treat quote request as the start of comparison.
        $procurementRequisition->update(['status' => 'comparison']);

        return response()->json($procurementRequisition->fresh()->load([
            'location',
            'items.inventoryItem.purchaseUom',
            'items.vendors',
            'creator',
        ]));
    }

    public function startComparison(ProcurementRequisition $procurementRequisition)
    {
        $this->checkPermission('manage-inventory');
        if (! in_array($procurementRequisition->status, ['draft', 'quotation_requested'], true)) {
            return response()->json(['message' => 'Open comparison from draft or after quote requests'], 422);
        }
        $procurementRequisition->update(['status' => 'comparison']);

        return response()->json($procurementRequisition->fresh()->load([
            'location',
            'items.inventoryItem.purchaseUom',
            'items.vendors',
            'creator',
        ]));
    }

    public function quoteSlips(ProcurementRequisition $procurementRequisition)
    {
        $procurementRequisition->load([
            'location',
            'items.inventoryItem.purchaseUom',
            'items.vendors',
        ]);

        $groups = [];
        foreach ($procurementRequisition->items as $line) {
            foreach ($line->vendors as $vendor) {
                if (! isset($groups[$vendor->id])) {
                    $groups[$vendor->id] = [
                        'vendor' => [
                            'id' => $vendor->id,
                            'name' => $vendor->name,
                            'contact_person' => $vendor->contact_person,
                            'phone' => $vendor->phone,
                        ],
                        'lines' => [],
                    ];
                }
                $groups[$vendor->id]['lines'][] = [
                    'item_name' => $line->inventoryItem->name,
                    'sku' => $line->inventoryItem->sku,
                    'quantity' => $line->quantity,
                    'uom' => $line->inventoryItem->purchaseUom->short_name ?? $line->inventoryItem->purchaseUom->name ?? '',
                ];
            }
        }

        // Header: document title → settings company name → receive location → app name.
        $propertyLabel = trim((string) ($procurementRequisition->title ?? ''));
        if ($propertyLabel === '') {
            $propertyLabel = trim((string) Setting::get('receipt_company_name', ''));
        }
        if ($propertyLabel === '') {
            $propertyLabel = (string) ($procurementRequisition->location?->name ?? '');
        }
        if ($propertyLabel === '') {
            $propertyLabel = (string) config('app.name', '');
        }

        return response()->json([
            'reference_number' => $procurementRequisition->reference_number,
            'title' => $procurementRequisition->title,
            'property_label' => $propertyLabel,
            /** Canonical org identity (same source as Settings → receipt / company profile). */
            'company_profile' => Setting::getCompanyProfile(),
            'groups' => array_values($groups),
        ]);
    }

    public function removeVendor(
        ProcurementRequisitionItem $procurementRequisitionItem,
        Vendor $vendor
    ) {
        $this->checkPermission('manage-inventory');
        $req = $procurementRequisitionItem->procurementRequisition;
        if ($req->status === 'po_generated') {
            return response()->json(['message' => 'Cannot change vendors after PO generation'], 422);
        }
        if (! in_array($req->status, ['comparison', 'quotation_requested', 'draft'], true)) {
            return response()->json(['message' => 'Vendor removal is not allowed for this status'], 422);
        }
        if ($procurementRequisitionItem->vendors()->count() <= 1) {
            return response()->json(['message' => 'Each item must keep at least one vendor until a price is set'], 422);
        }
        $procurementRequisitionItem->vendors()->detach($vendor->id);
        $procurementRequisitionItem->update(['winning_unit_price' => null]);

        return response()->json($procurementRequisitionItem->fresh()->load(['vendors', 'inventoryItem.purchaseUom']));
    }

    public function updateItemPrice(Request $request, ProcurementRequisitionItem $procurementRequisitionItem)
    {
        $this->checkPermission('manage-inventory');
        $req = $procurementRequisitionItem->procurementRequisition;
        if ($req->status === 'po_generated') {
            return response()->json(['message' => 'Cannot edit'], 422);
        }

        $validated = $request->validate([
            'winning_unit_price' => 'required|numeric|min:0',
        ]);

        if ($procurementRequisitionItem->vendors()->count() !== 1) {
            return response()->json(['message' => 'Exactly one vendor must remain before entering a price'], 422);
        }

        $procurementRequisitionItem->update([
            'winning_unit_price' => $validated['winning_unit_price'],
        ]);

        return response()->json($procurementRequisitionItem->fresh()->load(['vendors', 'inventoryItem.purchaseUom']));
    }

    public function generatePurchaseOrders(Request $request, ProcurementRequisition $procurementRequisition)
    {
        $this->checkPermission('manage-inventory');
        if ($procurementRequisition->status === 'po_generated') {
            return response()->json(['message' => 'Purchase orders already generated'], 422);
        }
        if ($procurementRequisition->status !== 'comparison') {
            return response()->json(['message' => 'Start comparison and complete vendor selection before generating POs'], 422);
        }

        $procurementRequisition->load([
            'items.inventoryItem.tax',
            'items.vendors',
        ]);

        foreach ($procurementRequisition->items as $line) {
            if ($line->vendors->count() !== 1) {
                return response()->json([
                    'message' => 'Each line must have exactly one vendor. Item: '.$line->inventoryItem->name,
                ], 422);
            }
            if ($line->winning_unit_price === null || floatval($line->winning_unit_price) < 0) {
                return response()->json([
                    'message' => 'Winning price required for: '.$line->inventoryItem->name,
                ], 422);
            }
        }

        $service = app(PurchaseOrderService::class);

        $created = DB::transaction(function () use ($procurementRequisition, $service) {
            $byVendor = [];
            foreach ($procurementRequisition->items as $line) {
                $vendorId = $line->vendors->first()->id;
                if (! isset($byVendor[$vendorId])) {
                    $byVendor[$vendorId] = [];
                }
                $byVendor[$vendorId][] = $line;
            }

            $pos = [];
            foreach ($byVendor as $vendorId => $lines) {
                $vendorBasis = Vendor::find($vendorId)?->default_tax_price_basis ?? PurchaseOrderLineAmounts::BASIS_EXCLUSIVE;
                $itemsPayload = [];
                foreach ($lines as $line) {
                    $inv = $line->inventoryItem;
                    $taxRate = floatval($inv->tax?->rate ?? 0);
                    $itemsPayload[] = [
                        'inventory_item_id' => $inv->id,
                        'quantity' => $line->quantity,
                        'unit_price' => floatval($line->winning_unit_price),
                        'tax_rate' => $taxRate,
                        'tax_price_basis' => $vendorBasis,
                    ];
                }

                $validated = [
                    'vendor_id' => $vendorId,
                    'location_id' => $procurementRequisition->location_id,
                    'order_date' => $procurementRequisition->order_date->format('Y-m-d'),
                    'expected_delivery_date' => $procurementRequisition->expected_delivery_date?->format('Y-m-d'),
                    'notes' => trim('From '.$procurementRequisition->reference_number.($procurementRequisition->notes ? ' — '.$procurementRequisition->notes : '')),
                    'items' => $itemsPayload,
                ];

                // Create as "sent" so it can be received without an extra edit step.
                $pos[] = $service->createFromValidatedData($validated, $procurementRequisition->id, 'sent');
            }

            $procurementRequisition->update(['status' => 'po_generated']);

            return $pos;
        });

        return response()->json([
            'message' => 'Purchase orders created',
            'purchase_orders' => collect($created)->map(fn ($po) => $po->load('vendor', 'items.inventoryItem', 'creator')),
        ], 201);
    }
}
