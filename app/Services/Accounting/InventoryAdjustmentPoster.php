<?php

namespace App\Services\Accounting;

use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\Schema;

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
        'Manual Adjustment',
        'Correction',
    ];

    public function __construct(
        private readonly JournalPostingService $journal,
    ) {}

    public function post(InventoryTransaction $transaction, ?int $postedBy = null): ?JournalEntry
    {
        if (! Schema::hasTable('journal_entries')) {
            return null;
        }

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
}
