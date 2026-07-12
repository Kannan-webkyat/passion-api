<?php

namespace App\Services;

use App\Models\JournalLine;
use App\Models\PosOrder;
use App\Services\Accounting\AccountCodes;
use Illuminate\Support\Facades\DB;

/**
 * Kerala GST (GSTR-1 prep) and KVAT (KITIS prep) summary data — not portal upload format.
 */
final class KeralaComplianceService
{
    /**
     * @return array{
     *     period: array{from: string, to: string},
     *     gst_taxable_supplies: array<string, mixed>,
     *     table_8_non_gst_liquor: array<string, mixed>,
     *     notes: list<string>,
     * }
     */
    public function gstr1Summary(string $from, string $to, ?int $restaurantId = null): array
    {
        $orders = $this->paidOrdersQuery($from, $to, $restaurantId)->get();

        $gstTaxable = round((float) $orders->sum(fn ($o) => (float) ($o->gst_net_taxable ?? 0)), 2);
        $cgst = round((float) $orders->sum(fn ($o) => (float) ($o->cgst_amount ?? 0)), 2);
        $sgst = round((float) $orders->sum(fn ($o) => (float) ($o->sgst_amount ?? 0)), 2);
        $igst = round((float) $orders->sum(fn ($o) => (float) ($o->igst_amount ?? 0)), 2);
        $gstBills = $orders->filter(fn ($o) => (float) ($o->gst_net_taxable ?? 0) > 0.01)->count();

        $liquorTaxable = round((float) $orders->sum(fn ($o) => (float) ($o->vat_net_taxable ?? 0)), 2);
        $outputVat = round((float) $orders->sum(fn ($o) => (float) ($o->vat_tax_amount ?? 0)), 2);
        $liquorBills = $orders->filter(fn ($o) => (float) ($o->vat_net_taxable ?? 0) > 0.01)->count();

        return [
            'period' => ['from' => $from, 'to' => $to],
            'gst_taxable_supplies' => [
                'description' => 'Taxable supplies (food & non-alcohol) — report in GSTR-1 B2C / applicable tables',
                'bill_count' => $gstBills,
                'taxable_value' => $gstTaxable,
                'cgst' => $cgst,
                'sgst' => $sgst,
                'igst' => $igst,
                'total_gst' => round($cgst + $sgst + $igst, 2),
            ],
            'table_8_non_gst_liquor' => [
                'description' => 'Alcoholic liquor — Non-GST supply (GSTR-1 Table 8)',
                'bill_count' => $liquorBills,
                'taxable_value' => $liquorTaxable,
                'vat_amount' => $outputVat,
                'total_non_gst_supply' => round($liquorTaxable + $outputVat, 2),
            ],
            'notes' => [
                'Management summary only — validate with your CA before GST portal upload.',
                'Liquor must not be included in GST taxable turnover.',
                'Complimentary and voided orders are excluded.',
            ],
        ];
    }

    /**
     * @return array{
     *     period: array{from: string, to: string},
     *     output_vat: array<string, mixed>,
     *     input_vat_purchases: array<string, mixed>,
     *     input_gst_purchases: array<string, mixed>,
     *     notes: list<string>,
     * }
     */
    public function kvatSummary(string $from, string $to): array
    {
        $orders = $this->paidOrdersQuery($from, $to, null)->get();

        $outputVatTaxable = round((float) $orders->sum(fn ($o) => (float) ($o->vat_net_taxable ?? 0)), 2);
        $outputVat = round((float) $orders->sum(fn ($o) => (float) ($o->vat_tax_amount ?? 0)), 2);

        $grnLines = DB::table('grn_items')
            ->join('grns', 'grns.id', '=', 'grn_items.grn_id')
            ->join('inventory_items', 'inventory_items.id', '=', 'grn_items.inventory_item_id')
            ->join('purchase_order_items', 'purchase_order_items.id', '=', 'grn_items.purchase_order_item_id')
            ->where('grns.status', 'approved')
            ->whereDate('grns.approved_at', '>=', $from)
            ->whereDate('grns.approved_at', '<=', $to)
            ->where('grn_items.quantity_accepted', '>', 0)
            ->select([
                'grn_items.line_subtotal_accepted',
                'grn_items.line_tax_accepted',
                'grn_items.line_recoverable_tax_accepted',
                'grn_items.line_non_recoverable_tax_accepted',
                'grn_items.tax_input_credit_eligible',
                'purchase_order_items.tax_type',
                'inventory_items.is_alcohol',
            ])
            ->get();

        $inputVatRecoverable = 0.0;
        $inputVatCapitalized = 0.0;
        $inputGstRecoverable = 0.0;
        $liquorPurchaseTaxable = 0.0;
        $foodPurchaseTaxable = 0.0;

        foreach ($grnLines as $line) {
            $taxType = strtolower((string) ($line->tax_type ?? 'gst'));
            $isAlcohol = (bool) $line->is_alcohol;
            $subtotal = (float) $line->line_subtotal_accepted;
            $recoverable = (float) ($line->line_recoverable_tax_accepted ?? 0);
            $nonRecoverable = (float) ($line->line_non_recoverable_tax_accepted ?? 0);
            $lineTax = (float) $line->line_tax_accepted;

            if ($recoverable <= 0 && $nonRecoverable <= 0 && $lineTax > 0) {
                $eligible = $line->tax_input_credit_eligible;
                if ($taxType === 'vat' || $isAlcohol) {
                    $nonRecoverable = ($eligible === true) ? 0.0 : $lineTax;
                    $recoverable = ($eligible === true) ? $lineTax : 0.0;
                } else {
                    $recoverable = ($eligible === false) ? 0.0 : $lineTax;
                    $nonRecoverable = ($eligible === false) ? $lineTax : 0.0;
                }
            }

            if ($taxType === 'vat' || $isAlcohol) {
                $liquorPurchaseTaxable += $subtotal;
                $inputVatRecoverable += $recoverable;
                $inputVatCapitalized += $nonRecoverable;
            } else {
                $foodPurchaseTaxable += $subtotal;
                $inputGstRecoverable += $recoverable;
            }
        }

        $journalInputVat = $this->sumJournalByAccount(AccountCodes::INPUT_VAT, $from, $to);
        $journalInputGst = $this->sumJournalByAccount(AccountCodes::INPUT_GST, $from, $to);
        $journalOutputVat = $this->sumJournalCreditByAccount(AccountCodes::OUTPUT_VAT, $from, $to);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'output_vat' => [
                'description' => 'Bar / liquor sales (KVAT output) — from POS',
                'taxable_value' => $outputVatTaxable,
                'vat_amount' => $outputVat,
                'journal_output_vat_credit' => $journalOutputVat,
            ],
            'input_vat_purchases' => [
                'description' => 'Liquor purchases — recoverable vs capitalized VAT',
                'purchase_taxable_value' => round($liquorPurchaseTaxable, 2),
                'recoverable_vat_grn' => round($inputVatRecoverable, 2),
                'capitalized_vat_grn' => round($inputVatCapitalized, 2),
                'journal_input_vat_debit' => $journalInputVat,
            ],
            'input_gst_purchases' => [
                'description' => 'Food & supplies — Input GST (GSTR-3B / ITC, not KVAT)',
                'purchase_taxable_value' => round($foodPurchaseTaxable, 2),
                'recoverable_gst_grn' => round($inputGstRecoverable, 2),
                'journal_input_gst_debit' => $journalInputGst,
            ],
            'notes' => [
                'KITIS filing prep summary — not a substitute for Form 9 / KVAT return.',
                'Reconcile journal balances with GRN lines before filing.',
                'Liquor input VAT ITC depends on KVAT registration — toggle on Tax Master.',
            ],
        ];
    }

    /** @return list<array<string, scalar|null>> */
    public function gstr1ExportRows(string $from, string $to, ?int $restaurantId = null): array
    {
        $s = $this->gstr1Summary($from, $to, $restaurantId);

        return [
            ['section', 'metric', 'value'],
            ['GST Taxable Supplies', 'Bill count', $s['gst_taxable_supplies']['bill_count']],
            ['GST Taxable Supplies', 'Taxable value', $s['gst_taxable_supplies']['taxable_value']],
            ['GST Taxable Supplies', 'CGST', $s['gst_taxable_supplies']['cgst']],
            ['GST Taxable Supplies', 'SGST', $s['gst_taxable_supplies']['sgst']],
            ['GST Taxable Supplies', 'IGST', $s['gst_taxable_supplies']['igst']],
            ['GSTR-1 Table 8 Non-GST', 'Bill count', $s['table_8_non_gst_liquor']['bill_count']],
            ['GSTR-1 Table 8 Non-GST', 'Liquor taxable value', $s['table_8_non_gst_liquor']['taxable_value']],
            ['GSTR-1 Table 8 Non-GST', 'Liquor VAT (info)', $s['table_8_non_gst_liquor']['vat_amount']],
        ];
    }

    /** @return list<array<string, scalar|null>> */
    public function kvatExportRows(string $from, string $to): array
    {
        $s = $this->kvatSummary($from, $to);

        return [
            ['section', 'metric', 'value'],
            ['Output VAT', 'Sales taxable value', $s['output_vat']['taxable_value']],
            ['Output VAT', 'VAT amount (POS)', $s['output_vat']['vat_amount']],
            ['Output VAT', 'Journal OUTPUT_VAT credit', $s['output_vat']['journal_output_vat_credit']],
            ['Input VAT', 'Purchase taxable', $s['input_vat_purchases']['purchase_taxable_value']],
            ['Input VAT', 'Recoverable (GRN)', $s['input_vat_purchases']['recoverable_vat_grn']],
            ['Input VAT', 'Capitalized (GRN)', $s['input_vat_purchases']['capitalized_vat_grn']],
            ['Input VAT', 'Journal INPUT_VAT debit', $s['input_vat_purchases']['journal_input_vat_debit']],
            ['Input GST (ref)', 'Purchase taxable', $s['input_gst_purchases']['purchase_taxable_value']],
            ['Input GST (ref)', 'Recoverable (GRN)', $s['input_gst_purchases']['recoverable_gst_grn']],
        ];
    }

    private function paidOrdersQuery(string $from, string $to, ?int $restaurantId)
    {
        $q = PosOrder::query()
            ->where('status', 'paid')
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('business_date', [$from, $to])
                    ->orWhere(function ($q2) use ($from, $to) {
                        $q2->whereNull('business_date')
                            ->whereBetween(DB::raw('DATE(closed_at)'), [$from, $to]);
                    });
            })
            ->where(function ($q) {
                $q->where('is_complimentary', false)->orWhereNull('is_complimentary');
            });

        if ($restaurantId) {
            $q->where('restaurant_id', $restaurantId);
        }

        return $q;
    }

    private function sumJournalByAccount(string $code, string $from, string $to): float
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('journal_lines')) {
            return 0.0;
        }

        return round((float) JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->where('chart_of_accounts.code', $code)
            ->where('journal_entries.status', 'posted')
            ->whereBetween('journal_entries.entry_date', [$from, $to])
            ->sum('journal_lines.debit'), 2);
    }

    private function sumJournalCreditByAccount(string $code, string $from, string $to): float
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('journal_lines')) {
            return 0.0;
        }

        return round((float) JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->where('chart_of_accounts.code', $code)
            ->where('journal_entries.status', 'posted')
            ->whereBetween('journal_entries.entry_date', [$from, $to])
            ->sum('journal_lines.credit'), 2);
    }
}
