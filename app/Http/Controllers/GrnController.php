<?php

namespace App\Http\Controllers;

use App\Models\GRN;
use App\Models\InventoryLocation;
use App\Models\PurchaseOrder;
use App\Services\GrnApiPresenter;
use App\Services\GrnService;
use App\Services\InventoryAuthorization;
use App\Services\InventoryCostingConfig;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GrnController extends Controller
{
    private function grnResponse(GRN $grn, int $status = 200)
    {
        return response()->json(GrnApiPresenter::withCostSnapshots($grn), $status);
    }

    public function meta()
    {
        InventoryAuthorization::assertViewGrn();

        return response()->json([
            'statuses' => [
                GRN::STATUS_DRAFT => 'Draft',
                GRN::STATUS_PENDING => 'Pending',
                GRN::STATUS_APPROVED => 'Approved',
                GRN::STATUS_CANCELLED => 'Cancelled',
            ],
            'rejection_reasons' => GRN::REJECTION_REASONS,
            'quality_statuses' => GRN::QUALITY_STATUSES,
            'document_types' => GRN::DOCUMENT_TYPES,
            'inventory_costing' => InventoryCostingConfig::publicMeta(),
        ]);
    }

    public function index(Request $request)
    {
        InventoryAuthorization::assertViewGrn();

        $query = GRN::with(['vendor', 'location', 'purchaseOrder', 'creator', 'approver', 'inspector'])
            ->withCount('items')
            ->latest();

        if ($request->filled('purchase_order_id')) {
            $query->where('purchase_order_id', (int) $request->purchase_order_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->get());
    }

    public function show(GRN $grn)
    {
        InventoryAuthorization::assertViewGrn();

        return response()->json(
            GrnApiPresenter::withCostSnapshots($grn->load([
                'items.inventoryItem.purchaseUom',
                'items.inventoryItem.issueUom',
                'vendor',
                'location',
                'purchaseOrder',
                'creator',
                'submitter',
                'receiver',
                'inspector',
                'approver',
                'canceller',
                'attachments.uploader',
                'auditLogs.user',
            ]))
        );
    }

    public function store(Request $request)
    {
        InventoryAuthorization::assertInspectGrn();
        $validated = $this->validatePayload($request);

        try {
            $grn = app(GrnService::class)->createDraft(
                $validated['header'],
                $validated['lines'],
                auth()->id()
            );

            if ($request->hasFile('document')) {
                app(GrnService::class)->attachDocument(
                    $grn,
                    $request->file('document'),
                    $request->input('document_type', 'delivery_note'),
                    $request->input('document_notes'),
                    auth()->id()
                );
            }

            if ($request->boolean('submit')) {
                $grn = app(GrnService::class)->submit($grn, auth()->id());
            }
            if ($request->boolean('inspect')) {
                if ($grn->status === GRN::STATUS_DRAFT) {
                    $grn = app(GrnService::class)->submit($grn, auth()->id());
                }
                $grn = app(GrnService::class)->inspect($grn, $this->inspectLinesFromRequest($request, $grn), auth()->id());
            }
            if ($request->boolean('approve')) {
                InventoryAuthorization::assertApproveGrn();
                if ($grn->status === GRN::STATUS_DRAFT) {
                    $grn = app(GrnService::class)->submit($grn, auth()->id());
                }
                if (! $grn->inspected_at) {
                    $grn = app(GrnService::class)->inspect(
                        $grn,
                        $this->inspectLinesFromRequest($request, $grn),
                        auth()->id()
                    );
                }
                $grn = app(GrnService::class)->approve($grn, auth()->id());
            }

            return $this->grnResponse($grn, 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request, GRN $grn)
    {
        InventoryAuthorization::assertInspectGrn();

        if ($grn->isImmutable()) {
            return response()->json([
                'message' => 'Approved GRNs are immutable. Use inventory adjustment or a reversal GRN to correct mistakes.',
            ], 422);
        }

        $validated = $this->validatePayload($request);

        try {
            $grn = app(GrnService::class)->updateDraft(
                $grn,
                $validated['header'],
                $validated['lines'],
                auth()->id()
            );

            if ($request->hasFile('document')) {
                app(GrnService::class)->attachDocument(
                    $grn,
                    $request->file('document'),
                    $request->input('document_type', 'delivery_note'),
                    $request->input('document_notes'),
                    auth()->id()
                );
            }

            return $this->grnResponse($grn);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function submit(GRN $grn)
    {
        InventoryAuthorization::assertInspectGrn();
        try {
            return $this->grnResponse(app(GrnService::class)->submit($grn, auth()->id()));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function inspect(Request $request, GRN $grn)
    {
        InventoryAuthorization::assertInspectGrn();
        $validated = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.id' => 'required|integer|exists:grn_items,id',
            'lines.*.quantity_received' => 'nullable|numeric|min:0',
            'lines.*.quantity_rejected' => 'nullable|numeric|min:0',
            'lines.*.rejection_reason' => ['nullable', 'string', Rule::in(array_keys(GRN::REJECTION_REASONS))],
            'lines.*.rejection_notes' => 'nullable|string|max:500',
            'lines.*.quality_status' => ['nullable', 'string', Rule::in(array_keys(GRN::QUALITY_STATUSES))],
            'lines.*.expiry_date' => 'nullable|date',
            'lines.*.batch_number' => 'nullable|string|max:100',
            'lines.*.manufacture_date' => 'nullable|date',
            'lines.*.storage_condition' => 'nullable|string|max:255',
        ]);

        try {
            return $this->grnResponse(
                app(GrnService::class)->inspect($grn, $validated['lines'], auth()->id())
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function approve(GRN $grn)
    {
        InventoryAuthorization::assertApproveGrn();
        try {
            return $this->grnResponse(app(GrnService::class)->approve($grn, auth()->id()));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancel(Request $request, GRN $grn)
    {
        InventoryAuthorization::assertInspectGrn();
        $validated = $request->validate(['reason' => 'nullable|string|max:500']);
        try {
            return response()->json(
                app(GrnService::class)->cancel($grn, $validated['reason'] ?? null, auth()->id())
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function storeAttachment(Request $request, GRN $grn)
    {
        InventoryAuthorization::assertInspectGrn();
        $validated = $request->validate([
            'document_type' => ['required', 'string', Rule::in(array_keys(GRN::DOCUMENT_TYPES))],
            'document' => 'required|file|max:10240',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $grn = app(GrnService::class)->attachDocument(
                $grn,
                $validated['document'],
                $validated['document_type'],
                $validated['notes'] ?? null,
                auth()->id()
            );

            return $this->grnResponse($grn, 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Prefill lines for a new GRN from PO remaining quantities. */
    public function poRemaining(PurchaseOrder $purchaseOrder)
    {
        InventoryAuthorization::assertInspectGrn();
        $po = $purchaseOrder->load('items.inventoryItem.purchaseUom');
        $service = app(GrnService::class);
        $defaultLines = $service->defaultLinesForRemaining($po);

        return response()->json([
            'purchase_order' => $po,
            'default_lines' => $defaultLines,
            'default_location_id' => InventoryLocation::where('type', 'main_store')->value('id')
                ?? $po->location_id,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function inspectLinesFromRequest(Request $request, GRN $grn): array
    {
        $grn->loadMissing('items');
        $requestLines = $request->input('lines');
        if (is_array($requestLines) && count($requestLines) > 0) {
            return $requestLines;
        }

        return $grn->items->map(fn ($line) => [
            'id' => $line->id,
            'quantity_received' => $line->quantity_received,
            'quantity_rejected' => $line->quantity_rejected,
            'rejection_reason' => $line->rejection_reason,
        ])->all();
    }

    /** @return array{header: array<string, mixed>, lines: array<int, array<string, mixed>>} */
    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'purchase_order_id' => 'required|exists:purchase_orders,id',
            'inventory_location_id' => 'nullable|exists:inventory_locations,id',
            'received_date' => 'nullable|date',
            'delivery_note_number' => 'nullable|string|max:100',
            'supplier_invoice_number' => 'nullable|string|max:100',
            'invoice_date' => 'nullable|date',
            'payment_due_date' => 'nullable|date',
            'currency' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'allow_over_receive' => 'nullable|boolean',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.purchase_order_item_id' => 'required|integer|exists:purchase_order_items,id',
            'lines.*.quantity_received' => 'required|numeric|min:0',
            'lines.*.quantity_rejected' => 'nullable|numeric|min:0',
            'lines.*.rejection_reason' => ['nullable', 'string', Rule::in(array_keys(GRN::REJECTION_REASONS))],
            'lines.*.rejection_notes' => 'nullable|string|max:500',
            'lines.*.expiry_date' => 'nullable|date',
            'lines.*.batch_number' => 'nullable|string|max:100',
            'lines.*.manufacture_date' => 'nullable|date',
            'lines.*.storage_condition' => 'nullable|string|max:255',
        ]);

        return [
            'header' => [
                'purchase_order_id' => (int) $validated['purchase_order_id'],
                'inventory_location_id' => $validated['inventory_location_id'] ?? null,
                'received_date' => $validated['received_date'] ?? null,
                'delivery_note_number' => $validated['delivery_note_number'] ?? null,
                'supplier_invoice_number' => $validated['supplier_invoice_number'] ?? null,
                'invoice_date' => $validated['invoice_date'] ?? null,
                'payment_due_date' => $validated['payment_due_date'] ?? null,
                'currency' => $validated['currency'] ?? null,
                'exchange_rate' => $validated['exchange_rate'] ?? null,
                'allow_over_receive' => (bool) ($validated['allow_over_receive'] ?? false),
                'notes' => $validated['notes'] ?? null,
            ],
            'lines' => $validated['lines'],
        ];
    }
}
