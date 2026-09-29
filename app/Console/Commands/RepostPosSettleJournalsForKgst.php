<?php

namespace App\Console\Commands;

use App\Models\JournalEntry;
use App\Models\PosOrder;
use App\Models\PosOrderRefund;
use App\Services\Accounting\PosRefundPoster;
use App\Services\Accounting\PosSettlePoster;
use App\Services\KgstBarTotPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RepostPosSettleJournalsForKgst extends Command
{
    protected $signature = 'pos:repost-settle-journals-kgst
                            {--from= : Business date from (default: kgst cutover)}
                            {--to= : Business date to (default: today)}
                            {--refunds : Also repost pos_refund journals}
                            {--dry-run : Count only, do not update}';

    protected $description = 'Replace pos_settle (and optionally pos_refund) journals from recalculated KGST order tax splits';

    public function handle(PosSettlePoster $settlePoster, PosRefundPoster $refundPoster): int
    {
        if (! Schema::hasTable('journal_entries')) {
            $this->error('journal_entries table missing — run accounting migrations first.');

            return self::FAILURE;
        }

        $from = $this->option('from') ?: KgstBarTotPolicy::cutoverDate();
        $to = $this->option('to') ?: now()->toDateString();

        if (! $from) {
            $this->error('No cutover date. Set setting kgst_bar_tot_cutover_date or pass --from=YYYY-MM-DD');

            return self::FAILURE;
        }

        $settleIds = PosOrder::query()
            ->whereIn('status', ['paid', 'refunded'])
            ->whereDate('business_date', '>=', $from)
            ->whereDate('business_date', '<=', $to)
            ->whereIn('id', JournalEntry::query()
                ->where('source_type', 'pos_settle')
                ->where('status', JournalEntry::STATUS_POSTED)
                ->pluck('source_id'))
            ->pluck('id');

        $this->info("pos_settle journals to repost ({$from} → {$to}): {$settleIds->count()}");

        $refundIds = collect();
        if ($this->option('refunds')) {
            $refundIds = PosOrderRefund::query()
                ->whereIn('order_id', PosOrder::query()
                    ->whereIn('status', ['paid', 'refunded'])
                    ->whereDate('business_date', '>=', $from)
                    ->whereDate('business_date', '<=', $to)
                    ->select('id'))
                ->whereIn('id', JournalEntry::query()
                    ->where('source_type', 'pos_refund')
                    ->where('status', JournalEntry::STATUS_POSTED)
                    ->pluck('source_id'))
                ->pluck('id');
            $this->info("pos_refund journals to repost: {$refundIds->count()}");
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $errors = 0;

        $bar = $this->output->createProgressBar($settleIds->count());
        $bar->start();

        PosOrder::query()
            ->whereIn('id', $settleIds)
            ->with('payments')
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($settlePoster, $bar, &$errors) {
                foreach ($orders as $order) {
                    try {
                        $settlePoster->repost($order);
                    } catch (\Throwable $e) {
                        $errors++;
                        $this->newLine();
                        $this->warn("Order #{$order->id}: {$e->getMessage()}");
                    }
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine();

        if ($this->option('refunds') && $refundIds->isNotEmpty()) {
            $refBar = $this->output->createProgressBar($refundIds->count());
            $refBar->start();

            PosOrderRefund::query()
                ->whereIn('id', $refundIds)
                ->with('order')
                ->orderBy('id')
                ->chunkById(50, function ($refunds) use ($refundPoster, $refBar, &$errors) {
                    foreach ($refunds as $refund) {
                        try {
                            $refundPoster->repost($refund);
                        } catch (\Throwable $e) {
                            $errors++;
                            $this->newLine();
                            $this->warn("Refund #{$refund->id}: {$e->getMessage()}");
                        }
                        $refBar->advance();
                    }
                });

            $refBar->finish();
            $this->newLine();
        }

        if ($errors > 0) {
            $this->warn("Completed with {$errors} error(s).");

            return self::FAILURE;
        }

        $this->info('Done. BAR_SALES should match vat_net_taxable; OUTPUT_VAT should be 0 post-cutover.');

        return self::SUCCESS;
    }
}
