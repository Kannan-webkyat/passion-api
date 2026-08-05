<?php

namespace App\Console\Commands;

use App\Services\Accounting\AccountCodes;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Opening stock entered before the reversal fix could only be corrected with
 * Correction / Manual Adjustment, which post to operating expense. This moves
 * that stranded balance back to opening balance equity so that inventory and
 * equity agree and no phantom expense is reported before trading starts.
 */
class ReclassOpeningStockCorrections extends Command
{
    protected $signature = 'accounting:reclass-opening-corrections
                            {--force : Post the journal (otherwise preview only)}
                            {--date= : Entry date, defaults to today}';

    protected $description = 'Move inventory correction expense (6100) back to opening balance equity (3900)';

    /**
     * Prior reclasses are netted in as well, so re-running the command settles
     * only what is still outstanding instead of posting the same amount twice.
     *
     * @var list<string>
     */
    private const INVENTORY_ADJUSTMENT_SOURCES = [
        'inventory_adjustment',
        'inventory_adjustment_in',
        'opening_stock_correction_reclass',
    ];

    public function handle(JournalPostingService $journal): int
    {
        $expenseAccountId = DB::table('chart_of_accounts')
            ->where('code', AccountCodes::GENERAL_EXPENSE)
            ->value('id');

        if (! $expenseAccountId) {
            $this->error('Account '.AccountCodes::GENERAL_EXPENSE.' not found in chart of accounts.');

            return self::FAILURE;
        }

        $breakdown = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.account_id', $expenseAccountId)
            ->where('je.status', 'posted')
            ->selectRaw('je.source_type, COUNT(*) n, SUM(jl.debit) dr, SUM(jl.credit) cr')
            ->groupBy('je.source_type')
            ->get();

        if ($breakdown->isEmpty()) {
            $this->info('Account '.AccountCodes::GENERAL_EXPENSE.' has no activity. Nothing to reclass.');

            return self::SUCCESS;
        }

        $this->line('Activity on '.AccountCodes::GENERAL_EXPENSE.' (General Operating Expenses):');
        $this->table(
            ['Source', 'Lines', 'Debit', 'Credit', 'Net'],
            $breakdown->map(fn ($r) => [
                $r->source_type,
                $r->n,
                number_format((float) $r->dr, 2),
                number_format((float) $r->cr, 2),
                number_format((float) $r->dr - (float) $r->cr, 2),
            ])->all()
        );

        $inventoryNet = 0.0;
        $otherNet = 0.0;
        foreach ($breakdown as $row) {
            $net = (float) $row->dr - (float) $row->cr;
            if (in_array($row->source_type, self::INVENTORY_ADJUSTMENT_SOURCES, true)) {
                $inventoryNet += $net;
            } else {
                $otherNet += $net;
            }
        }

        $inventoryNet = round($inventoryNet, 2);

        if (abs($otherNet) > 0.005) {
            $this->warn('Non-inventory activity of '.number_format($otherNet, 2).' will be left untouched.');
        }

        if (abs($inventoryNet) < 0.005) {
            $this->info('No inventory correction expense to reclass.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Proposed correcting journal:');
        if ($inventoryNet > 0) {
            $this->line(sprintf('   Dr  %s  Opening Balance Equity   %s', AccountCodes::OPENING_BALANCE_EQUITY, number_format($inventoryNet, 2)));
            $this->line(sprintf('   Cr  %s  General Operating Exp.   %s', AccountCodes::GENERAL_EXPENSE, number_format($inventoryNet, 2)));
        } else {
            $this->line(sprintf('   Dr  %s  General Operating Exp.   %s', AccountCodes::GENERAL_EXPENSE, number_format(abs($inventoryNet), 2)));
            $this->line(sprintf('   Cr  %s  Opening Balance Equity   %s', AccountCodes::OPENING_BALANCE_EQUITY, number_format(abs($inventoryNet), 2)));
        }

        $this->newLine();
        $this->showBalances('Before');

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('Preview only. Re-run with --force to post.');

            return self::SUCCESS;
        }

        $amount = abs($inventoryNet);
        $equityLine = ['account_code' => AccountCodes::OPENING_BALANCE_EQUITY];
        $expenseLine = ['account_code' => AccountCodes::GENERAL_EXPENSE];

        if ($inventoryNet > 0) {
            $equityLine['debit'] = $amount;
            $expenseLine['credit'] = $amount;
        } else {
            $equityLine['credit'] = $amount;
            $expenseLine['debit'] = $amount;
        }

        $sourceId = (int) DB::table('journal_entries')
            ->where('source_type', 'opening_stock_correction_reclass')
            ->max('source_id') + 1;

        $entry = $journal->post(
            sourceType: 'opening_stock_correction_reclass',
            sourceId: $sourceId,
            entryDate: $this->option('date') ?: now()->toDateString(),
            businessDate: null,
            sourceRef: 'OPEN-STK-RECLASS #'.$sourceId,
            memo: 'Reclass inventory correction expense to opening balance equity',
            lines: [$equityLine, $expenseLine],
        );

        $this->newLine();
        $this->info('Posted journal entry '.$entry->entry_number.'.');
        $this->newLine();
        $this->showBalances('After');

        return self::SUCCESS;
    }

    private function showBalances(string $label): void
    {
        $net = function (string $code): float {
            $id = DB::table('chart_of_accounts')->where('code', $code)->value('id');
            if (! $id) {
                return 0.0;
            }

            $row = DB::table('journal_lines as jl')
                ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                ->where('jl.account_id', $id)
                ->where('je.status', 'posted')
                ->selectRaw('SUM(jl.debit) dr, SUM(jl.credit) cr')
                ->first();

            return round((float) $row->dr - (float) $row->cr, 2);
        };

        $inventory = $net(AccountCodes::INVENTORY_FOOD) + $net(AccountCodes::INVENTORY_LIQUOR);
        $equity = -$net(AccountCodes::OPENING_BALANCE_EQUITY);
        $expense = $net(AccountCodes::GENERAL_EXPENSE);

        $this->line($label.':');
        $this->line('   Inventory (1310 + 1311)      '.number_format($inventory, 2));
        $this->line('   Opening Balance Equity 3900  '.number_format($equity, 2));
        $this->line('   General Operating Exp. 6100  '.number_format($expense, 2));
        $this->line('   Inventory vs equity gap      '.number_format($inventory - $equity, 2));
    }
}
