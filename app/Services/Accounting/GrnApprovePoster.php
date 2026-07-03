<?php

namespace App\Services\Accounting;

use App\Models\GRN;
use App\Models\JournalEntry;
use App\Services\InventoryCostingConfig;

final class GrnApprovePoster
{
    public function __construct(
        private readonly JournalPostingService $journal,
    ) {}

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
        $deferredCharges = 0.0;
        $grniTotal = 0.0;

        $exclusiveOnly = ($grn->inventory_costing_mode ?? InventoryCostingConfig::MODE_EXCLUSIVE_ONLY)
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

            $lineTax = (float) $line->line_tax_accepted;
            $taxType = strtolower((string) ($line->purchaseOrderItem?->tax_type ?? 'gst'));
            if ($taxType === 'vat') {
                $inputVat += $lineTax;
            } else {
                $inputGst += $lineTax;
            }

            if ($exclusiveOnly) {
                $deferredCharges += (float) $line->line_cess_accepted + (float) $line->line_freight_allocated;
            }

            $grniTotal += round(
                (float) $line->line_subtotal_accepted
                + $lineTax
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
        if ($deferredCharges > 0) {
            $lines[] = [
                'account_code' => AccountCodes::DEFERRED_PROCUREMENT,
                'debit' => round($deferredCharges, 2),
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
            memo: 'GRN approved — inventory + GRNI',
            lines: $lines,
            postedBy: $postedBy,
        );
    }
}
