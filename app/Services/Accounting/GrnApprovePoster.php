<?php

namespace App\Services\Accounting;

use App\Models\GRN;
use App\Models\JournalEntry;
use App\Services\InventoryCostingConfig;

/**
 * GRN approve journal: tax-aware routing.
 * - Non-recoverable tax (liquor VAT) → capitalized in inventory (1311/1310).
 * - Recoverable tax (food GST) → Input GST asset (1420).
 * - Account 1360 (Deferred Procurement) is not used.
 */
final class GrnApprovePoster
{
    public function __construct(
        private readonly JournalPostingService $journal,
    ) {}

    public function isJournalRequired(GRN $grn): bool
    {
        $grn->loadMissing('items');

        foreach ($grn->items as $line) {
            if ((float) $line->quantity_accepted > 0) {
                return true;
            }
        }

        return false;
    }

    public function postStrict(GRN $grn, ?int $postedBy = null): ?JournalEntry
    {
        if (! $this->isJournalRequired($grn)) {
            return null;
        }

        LedgerPostingGuard::assertInfrastructure();

        return $this->post($grn, $postedBy);
    }

    public function post(GRN $grn, ?int $postedBy = null): JournalEntry
    {
        $grn->loadMissing([
            'items.inventoryItem',
            'items.purchaseOrderItem',
            'purchaseOrder',
        ]);

        $inventoryFood = 0.0;
        $inventoryLiquor = 0.0;
        $inputGst = 0.0;
        $inputVat = 0.0;
        $grniTotal = 0.0;

        $legacyExclusive = ($grn->inventory_costing_mode ?? InventoryCostingConfig::MODE_TAX_AWARE)
            === InventoryCostingConfig::MODE_EXCLUSIVE_ONLY;

        foreach ($grn->items as $line) {
            $accepted = (float) $line->quantity_accepted;
            if ($accepted <= 0) {
                continue;
            }

            // landed_unit_cost is 4dp; per-line round to 2dp then sum (matches stock value).
            $inventoryAmount = round((float) $line->landed_unit_cost * $accepted, 2);
            $isAlcohol = (bool) $line->inventoryItem?->is_alcohol;

            if ($isAlcohol) {
                $inventoryLiquor = round($inventoryLiquor + $inventoryAmount, 2);
            } else {
                $inventoryFood = round($inventoryFood + $inventoryAmount, 2);
            }

            $recoverableTax = round((float) ($line->line_recoverable_tax_accepted ?? 0), 2);
            if ($recoverableTax <= 0 && $legacyExclusive) {
                $taxType = strtolower((string) ($line->purchaseOrderItem?->tax_type ?? 'gst'));
                $lineTax = (float) $line->line_tax_accepted;
                if ($taxType !== 'vat') {
                    $recoverableTax = round($lineTax, 2);
                }
            } elseif ($recoverableTax <= 0 && ! $legacyExclusive) {
                $eligible = $line->tax_input_credit_eligible;
                $lineTax = (float) $line->line_tax_accepted;
                if ($eligible === true || ($eligible === null && strtolower((string) ($line->purchaseOrderItem?->tax_type ?? 'gst')) !== 'vat')) {
                    $recoverableTax = round($lineTax, 2);
                }
            }

            if ($recoverableTax > 0 && ! $this->isRecoverableVatLine($line, $legacyExclusive)) {
                $inputGst = round($inputGst + $recoverableTax, 2);
            }
            if ($recoverableTax > 0 && $this->isRecoverableVatLine($line, $legacyExclusive)) {
                $inputVat = round($inputVat + $recoverableTax, 2);
            }

            $grniTotal = round(
                $grniTotal + round(
                    (float) $line->line_subtotal_accepted
                    + (float) $line->line_tax_accepted
                    + (float) $line->line_cess_accepted
                    + (float) $line->line_freight_allocated,
                    2
                ),
                2
            );
        }

        $inventoryFood = round($inventoryFood, 2);
        $inventoryLiquor = round($inventoryLiquor, 2);
        $inputGst = round($inputGst, 2);
        $inputVat = round($inputVat, 2);
        $grniTotal = round($grniTotal, 2);

        // Landed (4dp×qty→2dp) vs GRNI (2dp components) can drift a few paise on large Bevco GRNs.
        // Prefer trimming GRNI / inventory via JournalPostingService; here nudge inventory to match GRNI.
        $debitTotal = round($inventoryFood + $inventoryLiquor + $inputGst + $inputVat, 2);
        $diff = round($debitTotal - $grniTotal, 2);
        if (abs($diff) > 0 && abs($diff) <= 0.05) {
            if ($inventoryLiquor >= $inventoryFood && $inventoryLiquor > 0) {
                $inventoryLiquor = round($inventoryLiquor - $diff, 2);
            } elseif ($inventoryFood > 0) {
                $inventoryFood = round($inventoryFood - $diff, 2);
            } elseif ($diff < 0) {
                $grniTotal = round($grniTotal + $diff, 2);
            }
        }

        $lines = [];

        if ($inventoryFood > 0) {
            $lines[] = [
                'account_code' => AccountCodes::INVENTORY_FOOD,
                'debit' => $inventoryFood,
                'meta' => ['grn_id' => $grn->id],
            ];
        }
        if ($inventoryLiquor > 0) {
            $lines[] = [
                'account_code' => AccountCodes::INVENTORY_LIQUOR,
                'debit' => $inventoryLiquor,
                'meta' => ['grn_id' => $grn->id],
            ];
        }
        if ($inputGst > 0) {
            $lines[] = [
                'account_code' => AccountCodes::INPUT_GST,
                'debit' => $inputGst,
                'tax_tag' => 'input_gst',
                'meta' => ['grn_id' => $grn->id],
            ];
        }
        if ($inputVat > 0) {
            $lines[] = [
                'account_code' => AccountCodes::INPUT_VAT,
                'debit' => $inputVat,
                'tax_tag' => 'input_vat',
                'meta' => ['grn_id' => $grn->id],
            ];
        }
        if ($grniTotal > 0) {
            $lines[] = [
                'account_code' => AccountCodes::GRNI,
                'credit' => $grniTotal,
                'meta' => [
                    'grn_id' => $grn->id,
                    'purchase_order_id' => $grn->purchase_order_id,
                ],
            ];
        }

        $entryDate = ($grn->approved_at ?? now())->toDateString();

        return $this->journal->post(
            sourceType: 'grn_approve',
            sourceId: (int) $grn->id,
            entryDate: $entryDate,
            businessDate: $grn->received_date?->toDateString(),
            sourceRef: $grn->grn_number,
            memo: 'GRN approved — tax-aware inventory + GRNI',
            lines: $lines,
            postedBy: $postedBy,
        );
    }

    private function isRecoverableVatLine(mixed $line, bool $legacyExclusive): bool
    {
        if ($legacyExclusive) {
            return false;
        }

        return strtolower((string) ($line->purchaseOrderItem?->tax_type ?? 'gst')) === 'vat';
    }
}
