<?php

namespace App\Services;

use App\Models\GRN;
use App\Models\GrnItem;
use App\Models\InventoryCostAuditLog;
use App\Models\InventoryCostLayer;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Writes inventory_cost_layers and inventory_cost_audit_log for procurement receipts and consumption.
 */
final class InventoryCostLayerService
{
    public const EVENT_GRN_RECEIPT = 'grn_receipt';

    public const EVENT_POS_COGS = 'pos_cogs';

    public const SOURCE_GRN = 'grn';

    public const SOURCE_POS = 'pos_order';

    /**
     * FIFO decrement across the oldest cost layers for an item (issue UOM quantities).
     *
     * @return array{
     *     quantity_requested: float,
     *     quantity_from_layers: float,
     *     quantity_unlayered: float,
     *     fifo_unit_cost: float,
     *     fifo_total_cost: float,
     *     slices: list<array{layer_id: int, quantity: float, unit_cost: float, total_cost: float}>
     * }
     */
    public function consume(int $inventoryItemId, float $quantity): array
    {
        $this->assertTablesExist();

        $quantity = max(0, round($quantity, 4));
        if ($quantity <= 0) {
            return $this->emptyConsumeResult(0);
        }

        $remaining = $quantity;
        $slices = [];
        $fifoTotal = 0.0;

        $layers = InventoryCostLayer::query()
            ->where('inventory_item_id', $inventoryItemId)
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($layers as $layer) {
            if ($remaining <= 0) {
                break;
            }

            $available = round((float) $layer->quantity_remaining, 4);
            if ($available <= 0) {
                continue;
            }

            $take = round(min($remaining, $available), 4);
            $unitCost = round((float) $layer->landed_unit_cost, 4);
            $sliceTotal = round($take * $unitCost, 2);

            $layer->update([
                'quantity_remaining' => round($available - $take, 4),
            ]);

            $slices[] = [
                'layer_id' => (int) $layer->id,
                'quantity' => $take,
                'unit_cost' => $unitCost,
                'total_cost' => $sliceTotal,
            ];

            $fifoTotal += $sliceTotal;
            $remaining = round($remaining - $take, 4);
        }

        $fromLayers = round($quantity - $remaining, 4);

        return [
            'quantity_requested' => $quantity,
            'quantity_from_layers' => $fromLayers,
            'quantity_unlayered' => round(max(0, $remaining), 4),
            'fifo_unit_cost' => $fromLayers > 0 ? round($fifoTotal / $fromLayers, 4) : 0.0,
            'fifo_total_cost' => round($fifoTotal, 2),
            'slices' => $slices,
        ];
    }

    /**
     * FIFO layer consumption + audit trail for a POS inventory deduction.
     *
     * @return array{
     *     quantity_requested: float,
     *     quantity_from_layers: float,
     *     quantity_unlayered: float,
     *     fifo_unit_cost: float,
     *     fifo_total_cost: float,
     *     slices: list<array{layer_id: int, quantity: float, unit_cost: float, total_cost: float}>
     * }
     */
    public function consumeForPosCogs(InventoryTransaction $transaction, ?int $userId = null): array
    {
        if ($transaction->type !== 'out' || $transaction->reason !== 'POS Order') {
            return $this->emptyConsumeResult(0);
        }

        if (! Schema::hasTable('inventory_cost_layers')) {
            return $this->emptyConsumeResult((float) $transaction->quantity);
        }

        $quantity = round((float) $transaction->quantity, 4);
        if ($quantity <= 0) {
            return $this->emptyConsumeResult(0);
        }

        if ($this->hasPosCogsAudit($transaction)) {
            return $this->consumeResultFromAudit($transaction);
        }

        $result = $this->consume((int) $transaction->inventory_item_id, $quantity);
        $this->recordPosCogsAudit($transaction, $result, $userId);

        return $result;
    }

    /**
     * @param  list<array{grn_item: GrnItem, inventory_transaction: InventoryTransaction}>  $lineReceipts
     */
    public function recordGrnReceipt(GRN $grn, array $lineReceipts, ?int $userId = null): void
    {
        $this->assertTablesExist();

        if ($lineReceipts === []) {
            return;
        }

        $grn->loadMissing(['items.inventoryItem']);
        $occurredAt = $grn->approved_at ?? now();
        $locationId = (int) $grn->inventory_location_id;

        foreach ($lineReceipts as $receipt) {
            $grnItem = $receipt['grn_item'];
            $transaction = $receipt['inventory_transaction'];

            if (! $grnItem instanceof GrnItem || ! $transaction instanceof InventoryTransaction) {
                throw new RuntimeException('Invalid GRN line receipt payload for cost layer recording.');
            }

            $this->recordGrnLineReceipt(
                $grn,
                $grnItem,
                $transaction,
                $locationId,
                $occurredAt,
                $userId
            );
        }
    }

    private function recordGrnLineReceipt(
        GRN $grn,
        GrnItem $grnItem,
        InventoryTransaction $transaction,
        int $locationId,
        \DateTimeInterface $occurredAt,
        ?int $userId
    ): void {
        if (InventoryCostLayer::query()->where('grn_item_id', $grnItem->id)->exists()) {
            return;
        }

        $acceptedPurchaseQty = (float) $grnItem->quantity_accepted;
        if ($acceptedPurchaseQty <= 0) {
            return;
        }

        $item = $grnItem->inventoryItem ?? InventoryItem::query()->find($transaction->inventory_item_id);
        if (! $item) {
            throw new RuntimeException("Inventory item not found for GRN line {$grnItem->id}.");
        }

        $conversionFactor = max(0.000001, (float) ($item->conversion_factor ?? 1));
        $issueQuantity = (float) $transaction->quantity;
        $snapshot = GrnItemCostSnapshot::fromGrnItem($grnItem, $grn->inventory_costing_mode);

        $wacAfterPurchase = (float) ($item->cost_price ?? 0);
        $stockAfterIssue = InventoryItem::sumQuantityAcrossLocations($item->id);
        $stockBeforeIssue = max(0, $stockAfterIssue - $issueQuantity);
        $onHandPurchaseBefore = $stockBeforeIssue / $conversionFactor;

        $postedUnitPurchase = (float) $snapshot['posted_unit_cost'];
        $wacBeforePurchase = $this->deriveWacBefore(
            $wacAfterPurchase,
            $onHandPurchaseBefore,
            $acceptedPurchaseQty,
            $postedUnitPurchase
        );

        $layer = InventoryCostLayer::create([
            'inventory_item_id' => $item->id,
            'inventory_location_id' => $locationId,
            'source_type' => self::SOURCE_GRN,
            'source_id' => $grn->id,
            'grn_item_id' => $grnItem->id,
            'inventory_transaction_id' => $transaction->id,
            'quantity_received' => round($issueQuantity, 4),
            'quantity_remaining' => round($issueQuantity, 4),
            'landed_unit_cost' => round((float) $transaction->unit_cost, 4),
            'merchandise_unit_cost' => round((float) $snapshot['merchandise_unit_cost'], 4),
            'cess_unit_cost' => round((float) $snapshot['cess_unit_cost'], 4),
            'freight_unit_cost' => round((float) $snapshot['freight_unit_cost'], 4),
            'non_recoverable_tax_unit_cost' => round((float) $snapshot['non_recoverable_tax_unit_cost'], 4),
            'inventory_costing_mode' => $grn->inventory_costing_mode,
            'received_at' => $occurredAt,
        ]);

        InventoryCostAuditLog::create([
            'inventory_item_id' => $item->id,
            'inventory_location_id' => $locationId,
            'event_type' => self::EVENT_GRN_RECEIPT,
            'source_type' => self::SOURCE_GRN,
            'source_id' => $grn->id,
            'inventory_transaction_id' => $transaction->id,
            'inventory_cost_layer_id' => $layer->id,
            'quantity_delta' => round($issueQuantity, 4),
            'unit_cost' => round((float) $transaction->unit_cost, 4),
            'total_cost' => round((float) $transaction->total_cost, 2),
            'wac_before' => round($wacBeforePurchase, 4),
            'wac_after' => round($wacAfterPurchase, 4),
            'stock_before' => round($stockBeforeIssue, 4),
            'stock_after' => round($stockAfterIssue, 4),
            'cost_breakdown' => [
                'purchase_uom' => [
                    'quantity_accepted' => $acceptedPurchaseQty,
                    'posted_unit_cost' => round($postedUnitPurchase, 4),
                    'merchandise_unit_cost' => round((float) $snapshot['merchandise_unit_cost'], 4),
                    'cess_unit_cost' => round((float) $snapshot['cess_unit_cost'], 4),
                    'freight_unit_cost' => round((float) $snapshot['freight_unit_cost'], 4),
                    'non_recoverable_tax_unit_cost' => round((float) $snapshot['non_recoverable_tax_unit_cost'], 4),
                    'line_recoverable_tax_accepted' => round((float) $snapshot['line_recoverable_tax_accepted'], 2),
                    'line_non_recoverable_tax_accepted' => round((float) $snapshot['line_non_recoverable_tax_accepted'], 2),
                ],
                'issue_uom' => [
                    'conversion_factor' => $conversionFactor,
                    'quantity_received' => round($issueQuantity, 4),
                    'landed_unit_cost' => round((float) $transaction->unit_cost, 4),
                ],
                'uses_landed_cost' => (bool) $snapshot['uses_landed_cost'],
                'tax_input_credit_eligible' => $snapshot['tax_input_credit_eligible'],
            ],
            'meta' => [
                'grn_id' => $grn->id,
                'grn_number' => $grn->grn_number,
                'grn_item_id' => $grnItem->id,
                'purchase_order_id' => $grn->purchase_order_id,
            ],
            'user_id' => $userId,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function deriveWacBefore(
        float $wacAfter,
        float $onHandPurchaseBefore,
        float $acceptedPurchaseQty,
        float $postedUnitPurchase
    ): float {
        $denominator = $onHandPurchaseBefore + $acceptedPurchaseQty;

        if ($denominator <= 0) {
            return $wacAfter;
        }

        if ($onHandPurchaseBefore <= 0) {
            return $postedUnitPurchase;
        }

        $numerator = ($wacAfter * $denominator) - ($acceptedPurchaseQty * $postedUnitPurchase);

        return $numerator / $onHandPurchaseBefore;
    }

    private function recordPosCogsAudit(
        InventoryTransaction $transaction,
        array $result,
        ?int $userId
    ): void {
        $transaction->loadMissing('item');
        $item = $transaction->item;
        $conversionFactor = max(0.000001, (float) ($item?->conversion_factor ?? 1));
        $wacPurchase = round((float) ($item?->cost_price ?? 0), 4);

        $stockAfterIssue = InventoryItem::sumQuantityAcrossLocations((int) $transaction->inventory_item_id);
        $stockBeforeIssue = round($stockAfterIssue + (float) $transaction->quantity, 4);
        $occurredAt = $transaction->created_at ?? now();

        $sourceType = $this->normalizePosSourceType($transaction->reference_type);
        $sourceId = $this->parsePosSourceId($transaction->reference_id);

        foreach ($result['slices'] as $slice) {
            InventoryCostAuditLog::create([
                'inventory_item_id' => $transaction->inventory_item_id,
                'inventory_location_id' => $transaction->inventory_location_id,
                'event_type' => self::EVENT_POS_COGS,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'inventory_transaction_id' => $transaction->id,
                'inventory_cost_layer_id' => $slice['layer_id'],
                'quantity_delta' => round(-$slice['quantity'], 4),
                'unit_cost' => $slice['unit_cost'],
                'total_cost' => round(-$slice['total_cost'], 2),
                'wac_before' => $wacPurchase,
                'wac_after' => $wacPurchase,
                'stock_before' => $stockBeforeIssue,
                'stock_after' => round($stockAfterIssue, 4),
                'cost_breakdown' => [
                    'method' => 'fifo',
                    'layer_id' => $slice['layer_id'],
                    'fifo_unit_cost' => $slice['unit_cost'],
                    'wac_issue_unit_cost' => round($wacPurchase / $conversionFactor, 4),
                    'transaction_unit_cost' => round((float) $transaction->unit_cost, 4),
                ],
                'meta' => [
                    'inventory_transaction_id' => $transaction->id,
                    'reference_type' => $transaction->reference_type,
                    'reference_id' => $transaction->reference_id,
                    'notes' => $transaction->notes,
                ],
                'user_id' => $userId ?? $transaction->user_id,
                'occurred_at' => $occurredAt,
            ]);
        }

        if ($result['quantity_unlayered'] > 0) {
            $unlayeredQty = round((float) $result['quantity_unlayered'], 4);
            $issueUnitCost = round((float) $transaction->unit_cost, 4);

            InventoryCostAuditLog::create([
                'inventory_item_id' => $transaction->inventory_item_id,
                'inventory_location_id' => $transaction->inventory_location_id,
                'event_type' => self::EVENT_POS_COGS,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'inventory_transaction_id' => $transaction->id,
                'inventory_cost_layer_id' => null,
                'quantity_delta' => round(-$unlayeredQty, 4),
                'unit_cost' => $issueUnitCost,
                'total_cost' => round(-$unlayeredQty * $issueUnitCost, 2),
                'wac_before' => $wacPurchase,
                'wac_after' => $wacPurchase,
                'stock_before' => $stockBeforeIssue,
                'stock_after' => round($stockAfterIssue, 4),
                'cost_breakdown' => [
                    'method' => 'wac_fallback',
                    'reason' => 'no_cost_layer_available',
                    'wac_issue_unit_cost' => $issueUnitCost,
                    'fifo_unit_cost' => $result['fifo_unit_cost'],
                ],
                'meta' => [
                    'inventory_transaction_id' => $transaction->id,
                    'reference_type' => $transaction->reference_type,
                    'reference_id' => $transaction->reference_id,
                    'quantity_from_layers' => $result['quantity_from_layers'],
                    'quantity_unlayered' => $unlayeredQty,
                ],
                'user_id' => $userId ?? $transaction->user_id,
                'occurred_at' => $occurredAt,
            ]);
        }
    }

    private function hasPosCogsAudit(InventoryTransaction $transaction): bool
    {
        return InventoryCostAuditLog::query()
            ->where('inventory_transaction_id', $transaction->id)
            ->where('event_type', self::EVENT_POS_COGS)
            ->exists();
    }

    /**
     * @return array{
     *     quantity_requested: float,
     *     quantity_from_layers: float,
     *     quantity_unlayered: float,
     *     fifo_unit_cost: float,
     *     fifo_total_cost: float,
     *     slices: list<array{layer_id: int, quantity: float, unit_cost: float, total_cost: float}>
     * }
     */
    private function consumeResultFromAudit(InventoryTransaction $transaction): array
    {
        $entries = InventoryCostAuditLog::query()
            ->where('inventory_transaction_id', $transaction->id)
            ->where('event_type', self::EVENT_POS_COGS)
            ->get();

        $slices = [];
        $fromLayers = 0.0;
        $fifoTotal = 0.0;
        $unlayered = 0.0;

        foreach ($entries as $entry) {
            $qty = round(abs((float) $entry->quantity_delta), 4);
            if ($entry->inventory_cost_layer_id) {
                $slices[] = [
                    'layer_id' => (int) $entry->inventory_cost_layer_id,
                    'quantity' => $qty,
                    'unit_cost' => round((float) $entry->unit_cost, 4),
                    'total_cost' => round(abs((float) $entry->total_cost), 2),
                ];
                $fromLayers += $qty;
                $fifoTotal += abs((float) $entry->total_cost);
            } else {
                $unlayered += $qty;
            }
        }

        $requested = round((float) $transaction->quantity, 4);

        return [
            'quantity_requested' => $requested,
            'quantity_from_layers' => round($fromLayers, 4),
            'quantity_unlayered' => round($unlayered, 4),
            'fifo_unit_cost' => $fromLayers > 0 ? round($fifoTotal / $fromLayers, 4) : 0.0,
            'fifo_total_cost' => round($fifoTotal, 2),
            'slices' => $slices,
        ];
    }

    /**
     * @return array{
     *     quantity_requested: float,
     *     quantity_from_layers: float,
     *     quantity_unlayered: float,
     *     fifo_unit_cost: float,
     *     fifo_total_cost: float,
     *     slices: list<array{layer_id: int, quantity: float, unit_cost: float, total_cost: float}>
     * }
     */
    private function emptyConsumeResult(float $quantityRequested): array
    {
        return [
            'quantity_requested' => round($quantityRequested, 4),
            'quantity_from_layers' => 0.0,
            'quantity_unlayered' => round(max(0, $quantityRequested), 4),
            'fifo_unit_cost' => 0.0,
            'fifo_total_cost' => 0.0,
            'slices' => [],
        ];
    }

    private function normalizePosSourceType(?string $referenceType): string
    {
        if ($referenceType === null || $referenceType === '') {
            return self::SOURCE_POS;
        }

        return str_starts_with($referenceType, 'pos_order') ? self::SOURCE_POS : $referenceType;
    }

    private function parsePosSourceId(mixed $referenceId): ?int
    {
        if ($referenceId === null || $referenceId === '') {
            return null;
        }

        if (is_numeric($referenceId)) {
            return (int) $referenceId;
        }

        if (preg_match('/(\d+)/', (string) $referenceId, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function assertTablesExist(): void
    {
        if (! Schema::hasTable('inventory_cost_layers') || ! Schema::hasTable('inventory_cost_audit_log')) {
            throw new RuntimeException(
                'Cost traceability tables are not migrated. Run migrations before approving GRNs.'
            );
        }
    }
}
