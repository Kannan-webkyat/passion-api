<?php

namespace App\Console\Commands;

use App\Http\Controllers\PosController;
use App\Models\PosOrder;
use App\Models\Setting;
use App\Services\KgstBarTotPolicy;
use Illuminate\Console\Command;

class RecalculatePosOrdersForKgst extends Command
{
    protected $signature = 'pos:recalculate-kgst
                            {--from= : Business date from (default: kgst cutover)}
                            {--to= : Business date to (default: today)}
                            {--dry-run : Count only, do not update}';

    protected $description = 'Recalculate POS order tax splits from KGST bar-turnover cutover date';

    public function handle(PosController $pos): int
    {
        $from = $this->option('from') ?: KgstBarTotPolicy::cutoverDate();
        $to = $this->option('to') ?: now()->toDateString();

        if (! $from) {
            $this->error('No cutover date. Set setting kgst_bar_tot_cutover_date or pass --from=YYYY-MM-DD');

            return self::FAILURE;
        }

        $this->info("Recalculating paid/refunded orders from {$from} to {$to}…");

        $q = PosOrder::query()
            ->whereIn('status', ['paid', 'refunded'])
            ->whereDate('business_date', '>=', $from)
            ->whereDate('business_date', '<=', $to)
            ->orderBy('id');

        $count = (clone $q)->count();
        $this->line("Orders matched: {$count}");

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $q->chunkById(100, function ($orders) use ($pos, $bar) {
            foreach ($orders as $order) {
                $pos->maintenanceRecalculateOrderTotals($order);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Done. vat_net_taxable = bar turnover; vat_tax_amount = 0 under KGST model.');

        return self::SUCCESS;
    }
}
