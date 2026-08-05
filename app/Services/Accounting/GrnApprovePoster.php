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

            $inventoryAmount = round((float) $line->landed_unit_cost * $accepted, 2);
            $isAlcohol = (bool) $line->inventoryItem?->is_alcohol;

            if ($isAlcohol) {
                $inventoryLiquor += $inventoryAmount;
            } else {
                $inventoryFood += $inventoryAmount;
            }

            $recoverableTax = (float) ($line->line_recoverable_tax_accepted ?? 0);
            if ($recoverableTax <= 0 && $legacyExclusive) {
                $taxType = strtolower((string) ($line->purchaseOrderItem?->tax_type ?? 'gst'));
                $lineTax = (float) $line->line_tax_accepted;
                if ($taxType !== 'vat') {
                    $recoverableTax = $lineTax;
                }
            } elseif ($recoverableTax <= 0 && ! $legacyExclusive) {
                $eligible = $line->tax_input_credit_eligible;
                $lineTax = (float) $line->line_tax_accepted;
                if ($eligible === true || ($eligible === null && strtolower((string) ($line->purchaseOrderItem?->tax_type ?? 'gst')) !== 'vat')) {
                    $recoverableTax = $lineTax;
                }
            }

            $inputGst += $recoverableTax > 0 && ! $this->isRecoverableVatLine($line, $legacyExclusive)
                ? $recoverableTax
                : 0.0;
            $inputVat += $recoverableTax > 0 && $this->isRecoverableVatLine($line, $legacyExclusive)
                ? $recoverableTax
                : 0.0;

            $grniTotal += round(
                (float) $line->line_subtotal_accepted
                + (float) $line->line_tax_accepted
                + (float) $line->line_cess_accepted
                + (float) $line->line_freight_allocated,
                2
            );
        }

        $lines = [];

        if ($inventoryFood > 0) {
            $lines[] = [
                'account_code' => AccountCodes::INVENTORY_FOOD,
                'debit' => round($inventoryFood, 2),
                'meta' => ['grn_id' => $grn->id],
            ];
        }
        if ($inventoryLiquor > 0) {
            $lines[] = [
                'account_code' => AccountCodes::INVENTORY_LIQUOR,
                'debit' => round($inventoryLiquor, 2),
                'meta' => ['grn_id' => $grn->id],
            ];
        }
        if ($inputGst > 0) {
            $lines[] = [
                'account_code' => AccountCodes::INPUT_GST,
                'debit' => round($inputGst, 2),
                'tax_tag' => 'input_gst',
                'meta' => ['grn_id' => $grn->id],
            ];
        }
        if ($inputVat > 0) {
            $lines[] = [
                'account_code' => AccountCodes::INPUT_VAT,
                'debit' => round($inputVat, 2),
                'tax_tag' => 'input_vat',
                'meta' => ['grn_id' => $grn->id],
            ];
        }
        if ($grniTotal > 0) {
            $lines[] = [
                'account_code' => AccountCodes::GRNI,
                'credit' => round($grniTotal, 2),
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
