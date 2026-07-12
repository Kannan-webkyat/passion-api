<?php

namespace App\Services\Accounting;

use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Services\InventoryCostLayerService;

final class InventoryCogsPoster
{
    public function __construct(
        private readonly JournalPostingService $journal,
        private readonly PosCogsBusinessDateResolver $businessDateResolver,
        private readonly InventoryCostLayerService $costLayers,
    ) {}

    public function isJournalRequired(InventoryTransaction $transaction): bool
    {
        $isCogsOut = $transaction->type === 'out' && $transaction->reason === 'POS Order';
        $isCogsReversal = $transaction->type === 'in' && $transaction->reason === 'Inventory Reversal';

        if (! $isCogsOut && ! $isCogsReversal) {
            return false;
        }

        return round((float) $transaction->total_cost, 2) > 0;
    }

    public function postStrict(InventoryTransaction $transaction, ?int $postedBy = null, ?string $businessDate = null): ?JournalEntry
    {
        if ($this->isJournalRequired($transaction)) {
            LedgerPostingGuard::assertInfrastructure();
        }

        $entry = $this->post($transaction, $postedBy, $businessDate);

        if ($this->isJournalRequired($transaction)) {
            LedgerPostingGuard::requireEntry($entry, "inventory_cogs:{$transaction->id}");
        }

        return $entry;
    }

    public function postReversalStrict(InventoryTransaction $transaction, ?int $postedBy = null, ?string $businessDate = null): ?JournalEntry
    {
        if ($this->isJournalRequired($transaction)) {
            LedgerPostingGuard::assertInfrastructure();
        }

        $entry = $this->postReversal($transaction, $postedBy, $businessDate);

        if ($this->isJournalRequired($transaction)) {
            LedgerPostingGuard::requireEntry($entry, "inventory_cogs_reversal:{$transaction->id}");
        }

        return $entry;
    }

    public function post(InventoryTransaction $transaction, ?int $postedBy = null, ?string $businessDate = null): ?JournalEntry
    {
        if ($transaction->type !== 'out' || $transaction->reason !== 'POS Order') {
            return null;
        }

        $amount = round((float) $transaction->total_cost, 2);
        if ($amount <= 0) {
            return null;
        }

        $transaction->loadMissing('item');

        $this->costLayers->consumeForPosCogs($transaction, $postedBy);

        $isAlcohol = (bool) $transaction->item?->is_alcohol;

        $cogsCode = $isAlcohol ? AccountCodes::COGS_BAR : AccountCodes::COGS_RESTAURANT;
        $inventoryCode = $isAlcohol ? AccountCodes::INVENTORY_LIQUOR : AccountCodes::INVENTORY_FOOD;

        $businessDate ??= $this->businessDateResolver->fromReference(
            $transaction->reference_type,
            $transaction->reference_id
        );

        return $this->journal->post(
            sourceType: 'inventory_cogs',
            sourceId: (int) $transaction->id,
            entryDate: $transaction->created_at->toDateString(),
            businessDate: $businessDate,
            sourceRef: 'INV-TX #'.$transaction->id,
            memo: 'POS COGS — '.$transaction->notes,
            lines: [
                [
                    'account_code' => $cogsCode,
                    'debit' => $amount,
                    'meta' => [
                        'inventory_transaction_id' => $transaction->id,
                        'inventory_item_id' => $transaction->inventory_item_id,
                        'reference_type' => $transaction->reference_type,
                        'reference_id' => $transaction->reference_id,
                    ],
                ],
                [
                    'account_code' => $inventoryCode,
                    'credit' => $amount,
                    'meta' => [
                        'inventory_transaction_id' => $transaction->id,
                        'inventory_item_id' => $transaction->inventory_item_id,
                    ],
                ],
            ],
            postedBy: $postedBy,
        );
    }

    public function postReversal(InventoryTransaction $transaction, ?int $postedBy = null, ?string $businessDate = null): ?JournalEntry
    {
        if ($transaction->type !== 'in' || $transaction->reason !== 'Inventory Reversal') {
            return null;
        }

        $amount = round((float) $transaction->total_cost, 2);
        if ($amount <= 0) {
            return null;
        }

        $transaction->loadMissing('item');
        $isAlcohol = (bool) $transaction->item?->is_alcohol;

        $cogsCode = $isAlcohol ? AccountCodes::COGS_BAR : AccountCodes::COGS_RESTAURANT;
        $inventoryCode = $isAlcohol ? AccountCodes::INVENTORY_LIQUOR : AccountCodes::INVENTORY_FOOD;

        $businessDate ??= $this->businessDateResolver->fromReference(
            $transaction->reference_type,
            $transaction->reference_id
        );

        return $this->journal->post(
            sourceType: 'inventory_cogs_reversal',
            sourceId: (int) $transaction->id,
            entryDate: $transaction->created_at->toDateString(),
            businessDate: $businessDate,
            sourceRef: 'INV-TX-R #'.$transaction->id,
            memo: 'POS COGS reversal — '.$transaction->notes,
            lines: [
                [
                    'account_code' => $inventoryCode,
                    'debit' => $amount,
                    'meta' => [
                        'inventory_transaction_id' => $transaction->id,
                        'inventory_item_id' => $transaction->inventory_item_id,
                        'reference_type' => $transaction->reference_type,
                        'reference_id' => $transaction->reference_id,
                    ],
                ],
                [
                    'account_code' => $cogsCode,
                    'credit' => $amount,
                    'meta' => [
                        'inventory_transaction_id' => $transaction->id,
                        'inventory_item_id' => $transaction->inventory_item_id,
                    ],
                ],
            ],
            postedBy: $postedBy,
        );
    }
}
