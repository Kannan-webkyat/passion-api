<?php

namespace App\Services\Accounting;

use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\ProductionLog;
use Illuminate\Support\Facades\Schema;

/**
 * Posts production: Dr finished/prep inventory, Cr ingredient inventory (by food vs liquor).
 */
final class ProductionPoster
{
    public function __construct(
        private readonly JournalPostingService $journal,
    ) {}

    public function post(ProductionLog $log, ?int $postedBy = null): ?JournalEntry
    {
        if (! Schema::hasTable('journal_entries')) {
            return null;
        }

        $totalCost = round((float) $log->total_cost, 2);
        if ($totalCost <= 0 || ! $log->reference_id) {
            return null;
        }

        $ingredients = InventoryTransaction::with('item')
            ->where('reference_id', $log->reference_id)
            ->where('reference_type', 'production')
            ->get();

        $finished = InventoryTransaction::with('item')
            ->where('reference_id', $log->reference_id)
            ->where('reference_type', 'production_finished')
            ->first();

        $foodCredit = 0.0;
        $liquorCredit = 0.0;

        foreach ($ingredients as $tx) {
            $amt = round((float) $tx->total_cost, 2);
            if ($amt <= 0) {
                continue;
            }
            if ((bool) $tx->item?->is_alcohol) {
                $liquorCredit += $amt;
            } else {
                $foodCredit += $amt;
            }
        }

        $foodCredit = round($foodCredit, 2);
        $liquorCredit = round($liquorCredit, 2);
        $creditsTotal = round($foodCredit + $liquorCredit, 2);

        if ($creditsTotal <= 0) {
            return null;
        }

        $outputAlcohol = (bool) ($finished?->item?->is_alcohol);
        $debitCode = $outputAlcohol ? AccountCodes::INVENTORY_LIQUOR : AccountCodes::INVENTORY_FOOD;

        $lines = [
            [
                'account_code' => $debitCode,
                'debit' => $creditsTotal,
                'meta' => [
                    'production_log_id' => $log->id,
                    'reference_id' => $log->reference_id,
                ],
            ],
        ];

        if ($foodCredit > 0) {
            $lines[] = [
                'account_code' => AccountCodes::INVENTORY_FOOD,
                'credit' => $foodCredit,
                'meta' => ['production_log_id' => $log->id],
            ];
        }
        if ($liquorCredit > 0) {
            $lines[] = [
                'account_code' => AccountCodes::INVENTORY_LIQUOR,
                'credit' => $liquorCredit,
                'meta' => ['production_log_id' => $log->id],
            ];
        }

        $entryDate = ($log->production_date ?? now())->toDateString();

        return $this->journal->post(
            sourceType: 'production',
            sourceId: (int) $log->id,
            entryDate: $entryDate,
            businessDate: $log->business_date?->toDateString(),
            sourceRef: 'PROD #'.$log->id,
            memo: 'Kitchen production — recipe #'.$log->recipe_id,
            lines: $lines,
            postedBy: $postedBy,
        );
    }
}
