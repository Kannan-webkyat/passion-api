<?php

namespace App\Services\Accounting;

use App\Models\InventoryTransaction;
use App\Models\JournalEntry;

/**
 * Posts inventory shrinkage to expense when stock is reduced for loss reasons.
 */
final class InventoryAdjustmentPoster
{
    private const LOSS_REASONS = [
        'Wastage',
        'Expired',
        'Breakage',
        'Theft',
        'Staff meal',
        'Manual Adjustment',
        'Correction',
    ];

    private const GAIN_REASONS = [
        'Opening Stock',
        'Manual Adjustment',
        'Correction',
    ];

    public function __construct(
        private readonly JournalPostingService $journal,
    ) {}

    public function isJournalRequired(InventoryTransaction $transaction): bool
    {
        if ($transaction->type === 'out') {
            if ($transaction->reason === 'Opening Stock') {
                return round((float) $transaction->total_cost, 2) > 0;
            }

            if (! in_array($transaction->reason, self::LOSS_REASONS, true)) {
                return false;
            }

            if ($transaction->reason === 'Wastage' && $transaction->reference_type === 'recovery_breakdown') {
                return false;
            }

            return round((float) $transaction->total_cost, 2) > 0;
        }

        if ($transaction->type === 'in') {
            if ($transaction->reason === 'Opening Stock') {
                return round((float) $transaction->total_cost, 2) > 0;
            }

            if (! in_array($transaction->reason, self::GAIN_REASONS, true)) {
                return false;
            }

            return round((float) $transaction->total_cost, 2) > 0;
        }

        return false;
    }

    public function postStrict(InventoryTransaction $transaction, ?int $postedBy = null): ?JournalEntry
    {
        if ($this->isJournalRequired($transaction)) {
            LedgerPostingGuard::assertInfrastructure();
        }

        $entry = $this->post($transaction, $postedBy);

        if ($this->isJournalRequired($transaction)) {
            LedgerPostingGuard::requireEntry($entry, "inventory_adjustment:{$transaction->id}");
        }

        return $entry;
    }

    public function post(InventoryTransaction $transaction, ?int $postedBy = null): ?JournalEntry
    {
        if ($transaction->type === 'out') {
            return $this->postDecrease($transaction, $postedBy);
        }

        if ($transaction->type === 'in') {
            return $this->postIncrease($transaction, $postedBy);
        }

        return null;
    }

    private function postDecrease(InventoryTransaction $transaction, ?int $postedBy): ?JournalEntry
    {
        if ($transaction->reason === 'Opening Stock') {
            return $this->postOpeningStockReversal($transaction, $postedBy);
        }

        if (! in_array($transaction->reason, self::LOSS_REASONS, true)) {
            return null;
        }

        // Wastage from recovery breakdown uses InventoryConsumptionPoster.
        if ($transaction->reason === 'Wastage' && $transaction->reference_type === 'recovery_breakdown') {
            return null;
        }

        $amount = round((float) $transaction->total_cost, 2);
        if ($amount <= 0) {
            return null;
        }

        $transaction->loadMissing('item');
        $isAlcohol = (bool) $transaction->item?->is_alcohol;
        $inventoryCode = $isAlcohol ? AccountCodes::INVENTORY_LIQUOR : AccountCodes::INVENTORY_FOOD;
        $expenseCode = match ($transaction->reason) {
            'Staff meal' => AccountCodes::EXP_STAFF_MEALS,
            'Manual Adjustment', 'Correction' => AccountCodes::GENERAL_EXPENSE,
            default => AccountCodes::EXP_WASTAGE,
        };

        return $this->journal->post(
            sourceType: 'inventory_adjustment',
            sourceId: (int) $transaction->id,
            entryDate: $transaction->created_at->toDateString(),
            businessDate: null,
            sourceRef: 'INV-ADJ #'.$transaction->id,
            memo: 'Inventory '.$transaction->reason,
            lines: [
                [
                    'account_code' => $expenseCode,
                    'debit' => $amount,
                    'meta' => [
                        'inventory_transaction_id' => $transaction->id,
                        'reason' => $transaction->reason,
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

    private function postIncrease(InventoryTransaction $transaction, ?int $postedBy): ?JournalEntry
    {
        if ($transaction->reason === 'Opening Stock') {
            return $this->postOpeningStock($transaction, $postedBy);
        }

        if (! in_array($transaction->reason, self::GAIN_REASONS, true)) {
            return null;
        }

        $amount = round((float) $transaction->total_cost, 2);
        if ($amount <= 0) {
            return null;
        }

        $transaction->loadMissing('item');
        $isAlcohol = (bool) $transaction->item?->is_alcohol;
        $inventoryCode = $isAlcohol ? AccountCodes::INVENTORY_LIQUOR : AccountCodes::INVENTORY_FOOD;

        return $this->journal->post(
            sourceType: 'inventory_adjustment_in',
            sourceId: (int) $transaction->id,
            entryDate: $transaction->created_at->toDateString(),
            businessDate: null,
            sourceRef: 'INV-ADJ-IN #'.$transaction->id,
            memo: 'Inventory increase — '.$transaction->reason,
            lines: [
                [
                    'account_code' => $inventoryCode,
                    'debit' => $amount,
                    'meta' => [
                        'inventory_transaction_id' => $transaction->id,
                        'inventory_item_id' => $transaction->inventory_item_id,
                    ],
                ],
                [
                    'account_code' => AccountCodes::GENERAL_EXPENSE,
                    'credit' => $amount,
                    'meta' => [
                        'inventory_transaction_id' => $transaction->id,
                        'reason' => $transaction->reason,
                    ],
                ],
            ],
            postedBy: $postedBy,
        );
    }

    private function postOpeningStock(InventoryTransaction $transaction, ?int $postedBy): ?JournalEntry
    {
        $amount = round((float) $transaction->total_cost, 2);
        if ($amount <= 0) {
            return null;
        }

        $transaction->loadMissing('item');
        $isAlcohol = (bool) $transaction->item?->is_alcohol;
        $inventoryCode = $isAlcohol ? AccountCodes::INVENTORY_LIQUOR : AccountCodes::INVENTORY_FOOD;

        return $this->journal->post(
            sourceType: 'inventory_opening_stock',
            sourceId: (int) $transaction->id,
            entryDate: $transaction->created_at->toDateString(),
            businessDate: null,
            sourceRef: 'OPEN-STK #'.$transaction->id,
            memo: 'Opening stock — '.$transaction->notes,
            lines: [
                [
                    'account_code' => $inventoryCode,
                    'debit' => $amount,
                    'meta' => [
                        'inventory_transaction_id' => $transaction->id,
                        'inventory_item_id' => $transaction->inventory_item_id,
                    ],
                ],
                [
                    'account_code' => AccountCodes::OPENING_BALANCE_EQUITY,
                    'credit' => $amount,
                    'meta' => [
                        'inventory_transaction_id' => $transaction->id,
                        'reason' => 'Opening Stock',
                    ],
                ],
            ],
            postedBy: $postedBy,
        );
    }

    /**
     * Reverses an opening stock entry against equity instead of expense, so that
     * fixing a count typo before go-live leaves no phantom operating cost behind.
     */
    private function postOpeningStockReversal(InventoryTransaction $transaction, ?int $postedBy): ?JournalEntry
    {
        $amount = round((float) $transaction->total_cost, 2);
        if ($amount <= 0) {
            return null;
        }

        $transaction->loadMissing('item');
        $isAlcohol = (bool) $transaction->item?->is_alcohol;
        $inventoryCode = $isAlcohol ? AccountCodes::INVENTORY_LIQUOR : AccountCodes::INVENTORY_FOOD;

        return $this->journal->post(
            sourceType: 'inventory_opening_stock_reversal',
            sourceId: (int) $transaction->id,
            entryDate: $transaction->created_at->toDateString(),
            businessDate: null,
            sourceRef: 'OPEN-STK-REV #'.$transaction->id,
            memo: 'Opening stock reversal — '.$transaction->notes,
            lines: [
                [
                    'account_code' => AccountCodes::OPENING_BALANCE_EQUITY,
                    'debit' => $amount,
                    'meta' => [
                        'inventory_transaction_id' => $transaction->id,
                        'reason' => 'Opening Stock',
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
}
