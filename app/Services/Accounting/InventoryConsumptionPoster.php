<?php

namespace App\Services\Accounting;

use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\Schema;

/**
 * Manual consumption (non-POS) and recovery wastage lines.
 */
final class InventoryConsumptionPoster
{
    public function __construct(
        private readonly JournalPostingService $journal,
    ) {}

    public function post(InventoryTransaction $transaction, ?int $postedBy = null): ?JournalEntry
    {
        if (! Schema::hasTable('journal_entries')) {
            return null;
        }

        if ($transaction->type !== 'out') {
            return null;
        }

        $allowed = ['Consumption', 'Wastage'];
        if (! in_array($transaction->reason, $allowed, true)) {
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

        if ($transaction->reason === 'Wastage') {
            $cogsCode = AccountCodes::EXP_WASTAGE;
        }

        $sourceType = $transaction->reason === 'Wastage' && $transaction->reference_type === 'recovery_breakdown'
            ? 'inventory_recovery_wastage'
            : 'inventory_consumption';

        return $this->journal->post(
            sourceType: $sourceType,
            sourceId: (int) $transaction->id,
            entryDate: $transaction->created_at->toDateString(),
            businessDate: null,
            sourceRef: 'INV-TX #'.$transaction->id,
            memo: $transaction->reason.' — '.($transaction->notes ?? ''),
            lines: [
                [
                    'account_code' => $cogsCode,
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
}
