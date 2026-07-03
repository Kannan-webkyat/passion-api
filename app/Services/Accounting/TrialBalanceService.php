<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

final class TrialBalanceService
{
    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     date_basis: string,
     *     totals: array{debit: float, credit: float, balanced: bool},
     *     accounts: list<array{code: string, name: string, type: string, debit: float, credit: float, balance: float}>
     * }
     */
    public function forPeriod(
        string $from,
        string $to,
        bool $includeZero = false,
        string $dateBasis = 'entry_date',
    ): array {
        $useBusinessDate = $dateBasis === 'business_date';

        $rows = DB::table('chart_of_accounts as coa')
            ->leftJoin('journal_lines as jl', 'jl.account_id', '=', 'coa.id')
            ->leftJoin('journal_entries as je', function ($join) use ($from, $to, $useBusinessDate) {
                $join->on('je.id', '=', 'jl.journal_entry_id')
                    ->where('je.status', '=', JournalEntry::STATUS_POSTED);

                if ($useBusinessDate) {
                    $join->where(function ($dated) use ($from, $to) {
                        $dated->where(function ($bd) use ($from, $to) {
                            $bd->whereNotNull('je.business_date')
                                ->whereDate('je.business_date', '>=', $from)
                                ->whereDate('je.business_date', '<=', $to);
                        })->orWhere(function ($legacy) use ($from, $to) {
                            $legacy->whereNull('je.business_date')
                                ->whereDate('je.entry_date', '>=', $from)
                                ->whereDate('je.entry_date', '<=', $to);
                        });
                    });
                } else {
                    $join->whereDate('je.entry_date', '>=', $from)
                        ->whereDate('je.entry_date', '<=', $to);
                }
            })
            ->where('coa.is_active', true)
            ->where('coa.is_posting', true)
            ->groupBy('coa.id', 'coa.code', 'coa.name', 'coa.type')
            ->orderBy('coa.code')
            ->selectRaw('
                coa.code as code,
                coa.name as name,
                coa.type as type,
                COALESCE(SUM(jl.debit), 0) as debit,
                COALESCE(SUM(jl.credit), 0) as credit
            ')
            ->get();

        $accounts = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($rows as $row) {
            $debit = round((float) $row->debit, 2);
            $credit = round((float) $row->credit, 2);

            if (! $includeZero && $debit <= 0 && $credit <= 0) {
                continue;
            }

            $balance = $this->signedBalance((string) $row->type, $debit, $credit);

            $accounts[] = [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'type' => (string) $row->type,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $balance,
            ];

            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        $totalDebit = round($totalDebit, 2);
        $totalCredit = round($totalCredit, 2);

        return [
            'from' => $from,
            'to' => $to,
            'date_basis' => $useBusinessDate ? 'business_date' : 'entry_date',
            'totals' => [
                'debit' => $totalDebit,
                'credit' => $totalCredit,
                'balanced' => abs($totalDebit - $totalCredit) <= 0.01,
            ],
            'accounts' => $accounts,
        ];
    }

    private function signedBalance(string $accountType, float $debit, float $credit): float
    {
        $debitNormal = in_array($accountType, ['asset', 'expense', 'cogs'], true);

        return round($debitNormal ? ($debit - $credit) : ($credit - $debit), 2);
    }
}
