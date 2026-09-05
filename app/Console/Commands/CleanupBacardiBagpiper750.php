<?php

namespace App\Console\Commands;

use App\Services\Accounting\JournalPostingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fix Bacardi White 750 (#46) bottle-rate-on-ML COGS + residual Inv write-down,
 * and Bagpiper Gold 750 (#32) phantom 1-bottle opening (₹910).
 *
 * Dry-run by default. Pass --force to apply.
 */
class CleanupBacardiBagpiper750 extends Command
{
    protected $signature = 'accounting:cleanup-bacardi-bagpiper-750
                            {--force : Apply txn + journal fixes}';

    protected $description = 'Fix Bacardi 750 ml-rate COGS and Bagpiper 750 phantom opening';

    public function handle(JournalPostingService $journal): int
    {
        $apply = (bool) $this->option('force');
        $today = now()->toDateString();

        $this->info('======== #46 BACARDI WHITE 750 ========');
        $item = DB::table('inventory_items')->where('id', 46)->first();
        if (! $item) {
            $this->error('Item #46 not found');

            return self::FAILURE;
        }

        $fairUc = round((float) $item->cost_price / 750.0, 4);
        $this->line("fairUc={$fairUc}");

        $inflated = DB::table('inventory_transactions')
            ->where('inventory_item_id', 46)
            ->where('unit_cost', '>', 10)
            ->where('reason', '!=', 'GRN Receipt')
            ->orderBy('id')
            ->get();

        $posExcessJe = 0.0;

        foreach ($inflated as $t) {
            $fairTotal = round((float) $t->quantity * $fairUc, 2);
            $oldTotal = round((float) $t->total_cost, 2);
            $this->line("tx#{$t->id} {$t->type} {$t->reason} {$oldTotal}→{$fairTotal}");

            $je = null;
            $jeAmt = 0.0;
            if ($t->type === 'out' && $t->reason === 'POS Order') {
                $je = DB::table('journal_entries')
                    ->where('source_type', 'inventory_cogs')
                    ->where('source_id', $t->id)
                    ->where('status', 'posted')
                    ->first();
                if ($je) {
                    $jeAmt = (float) DB::table('journal_lines as jl')
                        ->join('chart_of_accounts as a', 'a.id', '=', 'jl.account_id')
                        ->where('jl.journal_entry_id', $je->id)
                        ->where('a.code', '5110')
                        ->sum('jl.debit');
                    $ex = round($jeAmt - $fairTotal, 2);
                    $this->line("  JE#{$je->id} excess={$ex}");
                    if ($ex > 0.02) {
                        $posExcessJe += $ex;
                    }
                }
            }

            if (! $apply) {
                continue;
            }
            if (abs($oldTotal - $fairTotal) < 0.02 && abs((float) $t->unit_cost - $fairUc) < 0.0002) {
                continue;
            }

            DB::table('inventory_transactions')->where('id', $t->id)->update([
                'unit_cost' => $fairUc,
                'total_cost' => $fairTotal,
                'notes' => trim(($t->notes ?? '')." [ml-rate fix uc={$fairUc}; was {$t->unit_cost}/{$oldTotal}]"),
                'updated_at' => now(),
            ]);

            if ($je && ($jeAmt - $fairTotal) > 0.02) {
                $journal->replacePosted(
                    sourceType: 'inventory_cogs',
                    sourceId: (int) $t->id,
                    entryDate: Carbon::parse($t->created_at)->toDateString(),
                    businessDate: null,
                    sourceRef: 'INV-TX #'.$t->id,
                    memo: 'POS COGS fixed — Bacardi 750ml (bottle-rate on ML)',
                    lines: [
                        [
                            'account_code' => '5110',
                            'debit' => $fairTotal,
                            'meta' => [
                                'inventory_transaction_id' => $t->id,
                                'inventory_item_id' => 46,
                            ],
                        ],
                        [
                            'account_code' => '1311',
                            'credit' => $fairTotal,
                            'meta' => [
                                'inventory_transaction_id' => $t->id,
                                'inventory_item_id' => 46,
                            ],
                        ],
                    ],
                    postedBy: null,
                );
                $this->line("  replaced JE → {$fairTotal}");
            }
        }

        $in = (float) DB::table('inventory_transactions')->where('inventory_item_id', 46)->where('type', 'in')->sum('total_cost');
        $out = (float) DB::table('inventory_transactions')->where('inventory_item_id', 46)->where('type', 'out')->sum('total_cost');
        if (! $apply) {
            foreach ($inflated as $t) {
                $fairTotal = round((float) $t->quantity * $fairUc, 2);
                $oldTotal = round((float) $t->total_cost, 2);
                if ($t->type === 'in') {
                    $in = $in - $oldTotal + $fairTotal;
                } else {
                    $out = $out - $oldTotal + $fairTotal;
                }
            }
        }
        $book = round($in - $out, 2);
        $phys = round((float) $item->current_stock / 750.0 * (float) $item->cost_price, 2);
        $gap = round($book - $phys, 2);
        $this->line("book={$book} phys={$phys} gap={$gap}");
        $this->line("COGS JE excess to replace≈{$posExcessJe}");

        $existsB = DB::table('journal_entries')
            ->where('source_type', 'inventory_cleanup_bacardi_750')
            ->where('source_id', 46)
            ->where('status', 'posted')
            ->exists();

        if ($gap > 1 && ! $existsB) {
            $this->line("Cleanup: Dr6100/Cr1311 {$gap}");
            if ($apply) {
                $je = $journal->post(
                    sourceType: 'inventory_cleanup_bacardi_750',
                    sourceId: 46,
                    entryDate: $today,
                    businessDate: $today,
                    sourceRef: 'CLEANUP-BACARDI-750',
                    memo: 'Write down Bacardi 750 Inv: GRN bottle-value + correction ml-value overlap',
                    lines: [
                        ['account_code' => '6100', 'debit' => $gap, 'meta' => ['inventory_item_id' => 46]],
                        ['account_code' => '1311', 'credit' => $gap, 'meta' => ['inventory_item_id' => 46]],
                    ],
                    postedBy: null,
                );
                $this->info("Posted Bacardi JE#{$je->id}");
            }
        } else {
            $this->line('Bacardi residual cleanup skip');
        }

        $this->newLine();
        $this->info('======== #32 BAGPIPER GOLD 750 ========');
        $existsP = DB::table('journal_entries')
            ->where('source_type', 'inventory_cleanup_bagpiper_750')
            ->where('source_id', 32)
            ->where('status', 'posted')
            ->exists();
        $this->line('Dr3900/Cr1311 910 exists='.($existsP ? 'yes' : 'no'));

        if (! $existsP) {
            if ($apply) {
                $je = $journal->post(
                    sourceType: 'inventory_cleanup_bagpiper_750',
                    sourceId: 32,
                    entryDate: $today,
                    businessDate: $today,
                    sourceRef: 'CLEANUP-BAGPIPER-750',
                    memo: 'Reverse 1 bottle phantom opening; stock already 1470ml',
                    lines: [
                        ['account_code' => '3900', 'debit' => 910, 'meta' => ['inventory_item_id' => 32]],
                        ['account_code' => '1311', 'credit' => 910, 'meta' => ['inventory_item_id' => 32]],
                    ],
                    postedBy: null,
                );
                $this->info("Posted Bagpiper JE#{$je->id}");
            } else {
                $this->line('Will post Bagpiper 910');
            }
        }

        $this->newLine();
        $this->comment($apply ? 'APPLIED' : 'DRY-RUN — re-run with --force to apply');

        return self::SUCCESS;
    }
}
