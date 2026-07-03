<?php

namespace App\Services;

use App\Models\GRN;
use App\Models\GrnAttachment;
use App\Models\GrnAuditLog;
use App\Models\GrnItem;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTransaction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Accounting\GrnApprovePoster;
use App\Services\InventoryCostingConfig;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GrnService
{
    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function createDraft(array $header, array $lines, ?int $userId = null): GRN
    {
        return DB::transaction(function () use ($header, $lines, $userId) {
            $po = $this->lockReceivablePurchaseOrder((int) $header['purchase_order_id']);
            $mainStoreId = self::resolveMainStoreLocationId(
                isset($header['inventory_location_id']) ? (int) $header['inventory_location_id'] : null
            );

            $grn = GRN::create([
                'grn_number' => $this->nextGrnNumber($header['received_date'] ?? now()->toDateString()),
                'purchase_order_id' => $po->id,
                'vendor_id' => $po->vendor_id,
                'inventory_location_id' => $mainStoreId,
                'received_date' => $header['received_date'] ?? now()->toDateString(),
                'delivery_note_number' => $header['delivery_note_number'] ?? null,
                'supplier_invoice_number' => $header['supplier_invoice_number'] ?? null,
                'invoice_date' => $header['invoice_date'] ?? null,
                'payment_due_date' => $header['payment_due_date'] ?? null,
                'currency' => $header['currency'] ?? 'INR',
                'exchange_rate' => $header['exchange_rate'] ?? 1,
                'allow_over_receive' => (bool) ($header['allow_over_receive'] ?? false),
                'notes' => $header['notes'] ?? null,
                'status' => GRN::STATUS_DRAFT,
                'created_by' => $userId,
                'received_by' => $header['received_by'] ?? $userId,
            ]);

            $this->syncGrnLines($grn, $po, $lines);

            $this->audit($grn, 'created', null, GRN::STATUS_DRAFT, $userId, null, 'GRN draft created');

            return $grn->fresh(['items.inventoryItem', 'purchaseOrder.items', 'vendor', 'location', 'creator']);
        });
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function updateDraft(GRN $grn, array $header, array $lines, ?int $userId = null): GRN
    {
        return DB::transaction(function () use ($grn, $header, $lines, $userId) {
            $locked = GRN::lockForUpdate()->findOrFail($grn->id);
            if (! $locked->isEditable()) {
                throw new RuntimeException('Only draft GRNs can be edited.');
            }

            $po = $this->lockReceivablePurchaseOrder((int) $locked->purchase_order_id);
            $mainStoreId = self::resolveMainStoreLocationId(
                isset($header['inventory_location_id'])
                    ? (int) $header['inventory_location_id']
                    : (int) $locked->inventory_location_id
            );

            $locked->update([
                'inventory_location_id' => $mainStoreId,
                'received_date' => $header['received_date'] ?? $locked->received_date,
                'delivery_note_number' => $header['delivery_note_number'] ?? $locked->delivery_note_number,
                'supplier_invoice_number' => $header['supplier_invoice_number'] ?? $locked->supplier_invoice_number,
                'invoice_date' => $header['invoice_date'] ?? $locked->invoice_date,
                'payment_due_date' => $header['payment_due_date'] ?? $locked->payment_due_date,
                'currency' => $header['currency'] ?? $locked->currency,
                'exchange_rate' => $header['exchange_rate'] ?? $locked->exchange_rate,
                'allow_over_receive' => (bool) ($header['allow_over_receive'] ?? $locked->allow_over_receive),
                'notes' => $header['notes'] ?? $locked->notes,
                'received_by' => $header['received_by'] ?? $locked->received_by,
            ]);

            $locked->items()->delete();
            $this->syncGrnLines($locked, $po, $lines);

            $this->audit($locked, 'updated', GRN::STATUS_DRAFT, GRN::STATUS_DRAFT, $userId, ['lines' => count($lines)], 'GRN draft updated');

            return $locked->fresh(['items.inventoryItem', 'purchaseOrder.items', 'vendor', 'location', 'creator', 'auditLogs.user']);
        });
    }

    public function submit(GRN $grn, ?int $userId = null): GRN
    {
        return DB::transaction(function () use ($grn, $userId) {
            $locked = GRN::lockForUpdate()->with('items')->findOrFail($grn->id);
            if ($locked->status !== GRN::STATUS_DRAFT) {
                throw new RuntimeException('Only draft GRNs can be submitted.');
            }
            if ($locked->items->isEmpty()) {
                throw new RuntimeException('GRN must have at least one line.');
            }

            $this->validateGrnLines($locked);

            $old = $locked->status;
            $locked->update([
                'status' => GRN::STATUS_PENDING,
                'submitted_by' => $userId,
                'submitted_at' => now(),
            ]);

            $this->audit($locked, 'submitted', $old, GRN::STATUS_PENDING, $userId, null, 'GRN submitted — goods received at dock');

            return $locked->fresh(['items.inventoryItem', 'purchaseOrder.items', 'vendor', 'location', 'creator', 'auditLogs.user']);
        });
    }

    /**
     * Quality inspection after physical receipt. Required before approval.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function inspect(GRN $grn, array $lines, ?int $userId = null): GRN
    {
        return DB::transaction(function () use ($grn, $lines, $userId) {
            $locked = GRN::lockForUpdate()->with(['items.inventoryItem', 'purchaseOrder.items'])->findOrFail($grn->id);
            if ($locked->status !== GRN::STATUS_PENDING) {
                throw new RuntimeException('Only pending GRNs can be inspected.');
            }
            if ($locked->inspected_at !== null) {
                throw new RuntimeException('GRN has already been inspected.');
            }

            $lineMap = collect($lines)->keyBy(fn ($line) => (int) ($line['id'] ?? $line['grn_item_id'] ?? 0));

            foreach ($locked->items as $grnLine) {
                $payload = $lineMap->get($grnLine->id);
                if (! $payload) {
                    continue;
                }

                $received = max(0, (float) ($payload['quantity_received'] ?? $grnLine->quantity_received));
                $rejected = max(0, (float) ($payload['quantity_rejected'] ?? $grnLine->quantity_rejected));
                if ($rejected > $received + 0.0001) {
                    throw new RuntimeException("Rejected quantity cannot exceed received for {$grnLine->inventoryItem?->name}.");
                }

                $accepted = max(0, $received - $rejected);
                $qualityStatus = $payload['quality_status'] ?? self::computeQualityStatus($received, $rejected, $accepted);

                $grnLine->update([
                    'quantity_received' => $received,
                    'quantity_rejected' => $rejected,
                    'quantity_accepted' => $accepted,
                    'rejection_reason' => $payload['rejection_reason'] ?? $grnLine->rejection_reason,
                    'rejection_notes' => $payload['rejection_notes'] ?? $grnLine->rejection_notes,
                    'quality_status' => $qualityStatus,
                    'expiry_date' => $payload['expiry_date'] ?? $grnLine->expiry_date,
                    'batch_number' => $payload['batch_number'] ?? $grnLine->batch_number,
                    'manufacture_date' => $payload['manufacture_date'] ?? $grnLine->manufacture_date,
                    'storage_condition' => $payload['storage_condition'] ?? $grnLine->storage_condition,
                ]);
            }

            $locked->load(['items', 'purchaseOrder.items']);
            $this->validateGrnLines($locked);
            $this->validateRejectionReasons($locked);

            foreach ($locked->items as $grnLine) {
                if ($grnLine->quality_status === null) {
                    $grnLine->update([
                        'quality_status' => self::computeQualityStatus(
                            (float) $grnLine->quantity_received,
                            (float) $grnLine->quantity_rejected,
                            (float) $grnLine->quantity_accepted
                        ),
                    ]);
                }
            }

            $locked->update([
                'inspected_by' => $userId,
                'inspected_at' => now(),
            ]);

            $this->audit($locked, 'inspected', GRN::STATUS_PENDING, GRN::STATUS_PENDING, $userId, null, 'GRN quality inspection completed');

            return $locked->fresh(['items.inventoryItem', 'purchaseOrder.items', 'vendor', 'location', 'inspector', 'auditLogs.user']);
        });
    }

    public function approve(GRN $grn, ?int $userId = null): GRN
    {
        return DB::transaction(function () use ($grn, $userId) {
            $locked = GRN::lockForUpdate()->with(['items.inventoryItem', 'purchaseOrder.items'])->findOrFail($grn->id);
            if ($locked->status !== GRN::STATUS_PENDING) {
                throw new RuntimeException('Only pending GRNs can be approved.');
            }
            if ($locked->inspected_at === null) {
                throw new RuntimeException('GRN must be inspected before approval.');
            }

            $po = PurchaseOrder::lockForUpdate()->with('items')->findOrFail($locked->purchase_order_id);
            $this->validateGrnLines($locked);

            $locationId = $this->assertGrnMainStoreLocation($locked);
            $refId = (string) $locked->id;
            $costingMode = InventoryCostingConfig::mode();
            $grnMerchandiseSubtotalSum = LandedCostAllocator::grnMerchandiseSubtotalSum($locked->items, $po);

            foreach ($locked->items as $grnLine) {
                $accepted = (float) $grnLine->quantity_accepted;
                if ($accepted <= 0) {
                    continue;
                }

                $poItem = $po->items->firstWhere('id', $grnLine->purchase_order_item_id);
                if (! $poItem) {
                    throw new RuntimeException('PO line not found for GRN item.');
                }

                $landed = LandedCostAllocator::forGrnLine($po, $poItem, $accepted, $grnMerchandiseSubtotalSum);
                $postedUnit = InventoryCostingConfig::postedUnitCostFromAllocation($landed);
                $ordered = max(0.000001, (float) $poItem->quantity_ordered);
                $lineTaxAccepted = round((float) ($poItem->tax_amount ?? 0) * ($accepted / $ordered), 2);
                $grnLine->update([
                    'line_subtotal_accepted' => $landed['line_subtotal_accepted'],
                    'line_tax_accepted' => $lineTaxAccepted,
                    'line_cess_accepted' => $landed['line_cess_accepted'],
                    'line_freight_allocated' => $landed['line_freight_allocated'],
                    'merchandise_unit_cost' => $landed['merchandise_unit'],
                    'cess_unit_cost' => $landed['cess_unit'],
                    'freight_unit_cost' => $landed['freight_unit'],
                    'landed_unit_cost' => $postedUnit,
                ]);

                $this->postAcceptedLineStock(
                    $grnLine,
                    $poItem,
                    $po,
                    $locked,
                    $locationId,
                    $refId,
                    $userId
                );

                $poItem->increment('quantity_received', $accepted);
                PurchaseOrderService::subtractStockExpectedForQuantity(
                    (int) $poItem->inventory_item_id,
                    $accepted
                );
            }

            $old = $locked->status;
            $locked->update([
                'status' => GRN::STATUS_APPROVED,
                'approved_by' => $userId,
                'approved_at' => now(),
                'inventory_costing_mode' => $costingMode,
            ]);

            self::syncPurchaseOrderStatus($po->fresh(['items']));

            $this->audit($locked, 'approved', $old, GRN::STATUS_APPROVED, $userId, null, 'GRN approved — stock posted ('.InventoryCostingConfig::publicMeta()['label'].' costing)');

            app(GrnApprovePoster::class)->post($locked->fresh(['items.inventoryItem', 'items.purchaseOrderItem']), $userId);

            return $locked->fresh(['items.inventoryItem', 'purchaseOrder.items', 'vendor', 'location', 'creator', 'approver', 'auditLogs.user']);
        });
    }

    public function cancel(GRN $grn, ?string $reason = null, ?int $userId = null): GRN
    {
        return DB::transaction(function () use ($grn, $reason, $userId) {
            $locked = GRN::lockForUpdate()->findOrFail($grn->id);
            if (! in_array($locked->status, [GRN::STATUS_DRAFT, GRN::STATUS_PENDING], true)) {
                throw new RuntimeException('Only draft or pending GRNs can be cancelled.');
            }

            $old = $locked->status;
            $locked->update([
                'status' => GRN::STATUS_CANCELLED,
                'cancelled_by' => $userId,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            $this->audit($locked, 'cancelled', $old, GRN::STATUS_CANCELLED, $userId, null, $reason);

            return $locked->fresh(['items.inventoryItem', 'purchaseOrder', 'vendor', 'location', 'auditLogs.user']);
        });
    }

    /**
     * Create a GRN from a PO and submit for quality inspection (stock posts only after approve).
     *
     * @param  array<int, array<string, mixed>>|null  $lines  null = receive all remaining quantities
     */
    public function receivePurchaseOrderForInspection(
        PurchaseOrder $purchaseOrder,
        int $locationId,
        ?array $lines = null,
        ?int $userId = null,
        ?UploadedFile $document = null
    ): GRN {
        $mainStoreId = self::resolveMainStoreLocationId($locationId);
        $po = $purchaseOrder->load('items.inventoryItem');
        $builtLines = $lines ?? $this->defaultLinesForRemaining($po);

        $grn = $this->createDraft([
            'purchase_order_id' => $po->id,
            'inventory_location_id' => $mainStoreId,
            'received_date' => now()->toDateString(),
            'notes' => 'PO receive — pending inspection',
            'received_by' => $userId,
        ], $builtLines, $userId);

        if ($document) {
            $this->attachDocument($grn, $document, GrnAttachment::TYPE_DELIVERY_NOTE, null, $userId);
        }

        return $this->submit($grn, $userId);
    }

    /**
     * Backward-compatible full receive: create, submit, and approve a GRN in one step.
     *
     * @param  array<int, array<string, mixed>>|null  $lines  null = receive all remaining quantities
     * @deprecated Use receivePurchaseOrderForInspection and inspect → approve in GrnWorkspace.
     */
    public function receivePurchaseOrderLegacy(
        PurchaseOrder $purchaseOrder,
        int $locationId,
        ?array $lines = null,
        ?int $userId = null,
        ?UploadedFile $document = null
    ): GRN {
        $mainStoreId = self::resolveMainStoreLocationId($locationId);
        $po = $purchaseOrder->load('items.inventoryItem');
        $builtLines = $lines ?? $this->defaultLinesForRemaining($po);

        $grn = $this->createDraft([
            'purchase_order_id' => $po->id,
            'inventory_location_id' => $mainStoreId,
            'received_date' => now()->toDateString(),
            'notes' => 'Legacy PO receive',
            'received_by' => $userId,
        ], $builtLines, $userId);

        if ($document) {
            $this->attachDocument($grn, $document, GrnAttachment::TYPE_DELIVERY_NOTE, null, $userId);
        }

        $grn = $this->submit($grn, $userId);
        $grn = $this->autoInspectFromCurrentLines($grn, $userId);

        return $this->approve($grn, $userId);
    }

    public function attachDocument(GRN $grn, UploadedFile $document, string $documentType, ?string $notes = null, ?int $userId = null): GRN
    {
        if (! $grn->allowsAttachments()) {
            throw new RuntimeException('Documents cannot be attached to cancelled GRNs.');
        }
        if (! array_key_exists($documentType, GRN::DOCUMENT_TYPES)) {
            throw new RuntimeException('Invalid document type.');
        }

        $path = $document->store('grn_documents', 'public');

        GrnAttachment::create([
            'grn_id' => $grn->id,
            'document_type' => $documentType,
            'file_path' => $path,
            'original_filename' => $document->getClientOriginalName(),
            'notes' => $notes,
            'uploaded_by' => $userId,
        ]);

        if ($documentType === GrnAttachment::TYPE_DELIVERY_NOTE && ! $grn->document_path && ! $grn->isImmutable()) {
            $grn->update(['document_path' => $path]);
        }

        if ($grn->isImmutable()) {
            $this->audit($grn, 'attachment_added', $grn->status, $grn->status, $userId, [
                'document_type' => $documentType,
                'filename' => $document->getClientOriginalName(),
            ], 'Supplementary document attached to immutable GRN');
        }

        return $grn->fresh(['attachments.uploader']);
    }

    /** @deprecated Use attachDocument with document_type */
    public function attachLegacyDocument(GRN $grn, UploadedFile $document): GRN
    {
        return $this->attachDocument($grn, $document, GrnAttachment::TYPE_DELIVERY_NOTE, null, null);
    }

    public static function syncPurchaseOrderStatus(PurchaseOrder $po): void
    {
        $po->loadMissing('items');
        $anyReceived = false;
        $allComplete = true;

        foreach ($po->items as $line) {
            $ordered = (float) $line->quantity_ordered;
            $received = (float) $line->quantity_received;
            if ($received > 0) {
                $anyReceived = true;
            }
            if ($received + 0.0001 < $ordered) {
                $allComplete = false;
            }
        }

        if (! $anyReceived) {
            return;
        }

        if ($allComplete) {
            $po->update([
                'status' => 'received',
                'received_at' => $po->received_at ?? now(),
            ]);
        } else {
            $po->update(['status' => 'partial']);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function defaultLinesForRemaining(PurchaseOrder $po): array
    {
        $lines = [];
        foreach ($po->items as $poItem) {
            $remaining = max(0, (float) $poItem->quantity_ordered - (float) $poItem->quantity_received);
            if ($remaining <= 0) {
                continue;
            }
            $lines[] = [
                'purchase_order_item_id' => $poItem->id,
                'quantity_received' => $remaining,
                'quantity_rejected' => 0,
            ];
        }

        return $lines;
    }

    /**
     * Supplier receipts must post to Main Store only — never Kitchen, Bar, or Housekeeping.
     */
    public static function resolveMainStoreLocationId(?int $requestedId = null): int
    {
        if ($requestedId) {
            $location = InventoryLocation::find($requestedId);
            if (! $location || $location->type !== 'main_store') {
                throw new RuntimeException(
                    'GRN stock-in is only allowed at Main Store. Use a store requisition to move stock to Kitchen, Bar, or Housekeeping.'
                );
            }

            return (int) $location->id;
        }

        $mainStoreId = InventoryLocation::where('type', 'main_store')->value('id');
        if (! $mainStoreId) {
            throw new RuntimeException('Main Store location is not configured.');
        }

        return (int) $mainStoreId;
    }

    private function assertGrnMainStoreLocation(GRN $grn): int
    {
        $locationId = (int) $grn->inventory_location_id;
        if (! $locationId) {
            throw new RuntimeException('Receive location is required.');
        }

        return self::resolveMainStoreLocationId($locationId);
    }

    private function lockReceivablePurchaseOrder(int $purchaseOrderId): PurchaseOrder
    {
        $po = PurchaseOrder::lockForUpdate()->with('items.inventoryItem')->findOrFail($purchaseOrderId);
        if (! in_array($po->status, ['sent', 'partial'], true)) {
            throw new RuntimeException('Purchase order must be sent or partially received before creating a GRN.');
        }

        return $po;
    }

    private function nextGrnNumber(string $date): string
    {
        $year = date('Y', strtotime($date));
        $last = GRN::whereYear('received_date', $year)->orderByDesc('grn_number')->lockForUpdate()->first();
        $next = 1;
        if ($last && preg_match('/GRN-\d{4}-(\d+)/', $last->grn_number, $m)) {
            $next = (int) $m[1] + 1;
        }

        return 'GRN-'.$year.'-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function syncGrnLines(GRN $grn, PurchaseOrder $po, array $lines): void
    {
        foreach ($lines as $line) {
            $poItem = $po->items->firstWhere('id', (int) $line['purchase_order_item_id']);
            if (! $poItem) {
                throw new RuntimeException('Invalid purchase order line on GRN.');
            }

            $received = max(0, (float) ($line['quantity_received'] ?? 0));
            $rejected = max(0, (float) ($line['quantity_rejected'] ?? 0));
            $accepted = max(0, $received - $rejected);

            $ordered = (float) $poItem->quantity_ordered;
            $previously = (float) $poItem->quantity_received;
            $unitPrice = (float) $poItem->unit_price;
            $taxRate = (float) ($poItem->tax_rate ?? 0);

            $share = $ordered > 0 ? $accepted / $ordered : 0;
            $lineSubtotal = round((float) ($poItem->subtotal ?? 0) * $share, 2);
            $lineTax = round((float) ($poItem->tax_amount ?? 0) * $share, 2);
            $lineCess = round((float) ($poItem->total_cess ?? 0) * $share, 2);

            GrnItem::create([
                'grn_id' => $grn->id,
                'purchase_order_item_id' => $poItem->id,
                'inventory_item_id' => $poItem->inventory_item_id,
                'quantity_ordered' => $ordered,
                'quantity_previously_received' => $previously,
                'quantity_received' => $received,
                'quantity_rejected' => $rejected,
                'quantity_accepted' => $accepted,
                'unit_price' => $unitPrice,
                'tax_rate' => $taxRate,
                'line_subtotal_accepted' => $lineSubtotal,
                'line_tax_accepted' => $lineTax,
                'line_cess_accepted' => $lineCess,
                'rejection_reason' => $this->normalizeRejectionReason($line['rejection_reason'] ?? null),
                'rejection_notes' => $line['rejection_notes'] ?? null,
                'quality_status' => $accepted > 0 && $rejected <= 0
                    ? 'accepted'
                    : ($accepted <= 0 && $rejected > 0 ? 'rejected' : ($rejected > 0 ? 'partial_acceptance' : null)),
                'expiry_date' => $line['expiry_date'] ?? null,
                'batch_number' => $line['batch_number'] ?? null,
                'manufacture_date' => $line['manufacture_date'] ?? null,
                'storage_condition' => $line['storage_condition'] ?? null,
            ]);
        }
    }

    private function validateGrnLines(GRN $grn): void
    {
        $grn->loadMissing(['items', 'purchaseOrder.items']);
        $hasAccepted = false;

        foreach ($grn->items as $line) {
            $received = (float) $line->quantity_received;
            $rejected = (float) $line->quantity_rejected;
            if ($rejected > $received + 0.0001) {
                throw new RuntimeException("Rejected quantity cannot exceed received for {$line->inventoryItem?->name}.");
            }

            $accepted = (float) $line->quantity_accepted;
            if ($accepted > 0) {
                $hasAccepted = true;
            }

            $poItem = $grn->purchaseOrder->items->firstWhere('id', $line->purchase_order_item_id);
            if (! $poItem) {
                continue;
            }

            $remaining = (float) $poItem->quantity_ordered - (float) $poItem->quantity_received;
            if (! $grn->allow_over_receive && $accepted > $remaining + 0.0001) {
                throw new RuntimeException(
                    "Accepted quantity exceeds remaining PO quantity for {$line->inventoryItem?->name}. ".
                    "Remaining: {$remaining}, accepted: {$accepted}."
                );
            }
        }

        if (! $hasAccepted) {
            throw new RuntimeException('At least one line must have accepted quantity greater than zero.');
        }

        $this->validateRejectionReasons($grn);
    }

    private function validateRejectionReasons(GRN $grn): void
    {
        foreach ($grn->items as $line) {
            $rejected = (float) $line->quantity_rejected;
            if ($rejected <= 0) {
                continue;
            }

            $reason = $line->rejection_reason;
            if ($reason === null || trim((string) $reason) === '') {
                throw new RuntimeException(
                    "Rejection reason is required for {$line->inventoryItem?->name} when quantity is rejected."
                );
            }

            if (! array_key_exists($reason, GRN::REJECTION_REASONS)) {
                throw new RuntimeException(
                    "Invalid rejection reason for {$line->inventoryItem?->name}. ".
                    'Choose a system reason only — use rejection notes for extra detail.'
                );
            }
        }
    }

    private function normalizeRejectionReason(mixed $reason): ?string
    {
        if ($reason === null || trim((string) $reason) === '') {
            return null;
        }

        $key = trim((string) $reason);
        if (! array_key_exists($key, GRN::REJECTION_REASONS)) {
            throw new RuntimeException(
                'Rejection reason must be a system constant. Use rejection notes for additional detail.'
            );
        }

        return $key;
    }

    public static function computeQualityStatus(float $received, float $rejected, float $accepted): string
    {
        if ($rejected <= 0 && $accepted > 0) {
            return 'accepted';
        }
        if ($accepted <= 0 && $rejected > 0) {
            return 'rejected';
        }
        if ($rejected > 0 && $accepted > 0) {
            return 'partial_acceptance';
        }

        return 'accepted';
    }

    private function autoInspectFromCurrentLines(GRN $grn, ?int $userId): GRN
    {
        $grn->loadMissing(['items']);
        $lines = $grn->items->map(fn (GrnItem $line) => [
            'id' => $line->id,
            'quantity_received' => $line->quantity_received,
            'quantity_rejected' => $line->quantity_rejected,
            'rejection_reason' => $line->rejection_reason,
            'rejection_notes' => $line->rejection_notes,
        ])->all();

        return $this->inspect($grn, $lines, $userId);
    }

    private function postAcceptedLineStock(
        GrnItem $grnLine,
        PurchaseOrderItem $poItem,
        PurchaseOrder $po,
        GRN $grn,
        int $locationId,
        string $refId,
        ?int $userId
    ): void {
        $acceptedPurchaseQty = (float) $grnLine->quantity_accepted;
        if ($acceptedPurchaseQty <= 0) {
            return;
        }

        /** @var InventoryItem|null $item */
        $item = InventoryItem::lockForUpdate()->find($poItem->inventory_item_id);
        if (! $item) {
            return;
        }

        $conversionFactor = max(0.000001, (float) ($item->conversion_factor ?? 1));
        $convertedQuantity = $acceptedPurchaseQty * $conversionFactor;

        $grnLine->loadMissing('grn');
        $snapshot = GrnItemCostSnapshot::fromGrnItem($grnLine, $grnLine->grn?->inventory_costing_mode);
        $postedUnitInPurchaseUom = (float) $snapshot['posted_unit_cost'];
        if ($postedUnitInPurchaseUom <= 0) {
            $postedUnitInPurchaseUom = (float) $snapshot['merchandise_unit_cost'];
        }
        if ($postedUnitInPurchaseUom <= 0) {
            throw new RuntimeException(
                'GRN line is missing frozen cost fields (landed_unit_cost / merchandise_unit_cost).'
            );
        }
        $linePostedTotal = round($postedUnitInPurchaseUom * $acceptedPurchaseQty, 2);
        $unitCostPerIssue = $postedUnitInPurchaseUom / $conversionFactor;

        $stockBeforeIssue = InventoryItem::sumQuantityAcrossLocations($item->id);
        $onHandForWacIssue = max(0, $stockBeforeIssue);
        $onHandForWacPurchase = $onHandForWacIssue / $conversionFactor;
        $currentPurchasePrice = (float) ($item->cost_price ?? 0);

        $denominatorPurchase = $onHandForWacPurchase + $acceptedPurchaseQty;
        $newPurchaseCost = $denominatorPurchase > 0
            ? (($onHandForWacPurchase * $currentPurchasePrice) + ($acceptedPurchaseQty * $postedUnitInPurchaseUom)) / $denominatorPurchase
            : $postedUnitInPurchaseUom;

        DB::table('inventory_item_locations')->updateOrInsert(
            ['inventory_item_id' => $item->id, 'inventory_location_id' => $locationId],
            ['updated_at' => now(), 'created_at' => now()]
        );
        DB::table('inventory_item_locations')
            ->where('inventory_item_id', $item->id)
            ->where('inventory_location_id', $locationId)
            ->increment('quantity', $convertedQuantity);

        $item->update(['cost_price' => round($newPurchaseCost, 4)]);
        InventoryItem::syncStoredCurrentStockFromLocations($item->id);

        $locationName = InventoryLocation::find($locationId)?->name ?? 'Store';
        $rejectedNote = (float) $grnLine->quantity_rejected > 0
            ? " · Rejected: {$grnLine->quantity_rejected}".($grnLine->rejection_reason
                ? ' ('.(GRN::REJECTION_REASONS[$grnLine->rejection_reason] ?? $grnLine->rejection_reason).')'
                : '')
            .($grnLine->rejection_notes ? " — {$grnLine->rejection_notes}" : '')
            : '';

        InventoryTransaction::create([
            'inventory_item_id' => $item->id,
            'inventory_location_id' => $locationId,
            'type' => 'in',
            'quantity' => $convertedQuantity,
            'unit_cost' => round($unitCostPerIssue, 4),
            'total_cost' => $linePostedTotal,
            'reason' => 'GRN Receipt',
            'notes' => "{$grn->grn_number} · PO {$po->po_number} @ {$locationName} · Accepted: {$acceptedPurchaseQty} ".($item->purchaseUom?->short_name ?? '').$rejectedNote,
            'user_id' => $userId,
            'reference_type' => 'grn',
            'reference_id' => $refId,
        ]);
    }

    private function audit(
        GRN $grn,
        string $action,
        ?string $oldStatus,
        ?string $newStatus,
        ?int $userId,
        ?array $payload,
        ?string $remarks
    ): void {
        GrnAuditLog::create([
            'grn_id' => $grn->id,
            'user_id' => $userId,
            'action' => $action,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'payload' => $payload,
            'remarks' => $remarks,
            'created_at' => now(),
        ]);
    }
}
