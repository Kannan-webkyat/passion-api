<?php

namespace App\Console\Commands;

use App\Http\Controllers\DayClosingController;
use App\Models\PosDayClosing;
use App\Services\KgstBarTotPolicy;
use Illuminate\Console\Command;

class RefreshDayClosingsKgst extends Command
{
    protected $signature = 'pos:refresh-day-closings-kgst
                            {--from= : Closed date from (default: kgst cutover)}
                            {--to= : Closed date to (default: today)}
                            {--dry-run : Count only, do not update}';

    protected $description = 'Recompute pos_day_closings tax totals from recalculated orders (KGST bar turnover)';

    public function handle(DayClosingController $dayClosing): int
    {
        $from = $this->option('from') ?: KgstBarTotPolicy::cutoverDate();
        $to = $this->option('to') ?: now()->toDateString();

        if (! $from) {
            $this->error('No cutover date. Set setting kgst_bar_tot_cutover_date or pass --from=YYYY-MM-DD');

            return self::FAILURE;
        }

        $q = PosDayClosing::query()
            ->whereDate('closed_date', '>=', $from)
            ->whereDate('closed_date', '<=', $to)
            ->orderBy('closed_date');

        $count = (clone $q)->count();
        $this->info("Day closings matched ({$from} → {$to}): {$count}");

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $updated = 0;
        foreach ($q->cursor() as $closing) {
            $date = $closing->closed_date?->format('Y-m-d');
            if (! $date) {
                $bar->advance();

                continue;
            }

            $summary = $dayClosing->maintenanceComputeSummary((int) $closing->restaurant_id, $date);

            $payload = [
                'total_sales' => $summary['total_sales'],
                'total_discount' => $summary['total_discount'],
                'total_tax' => $summary['total_tax'],
                'total_service_charge' => $summary['total_service_charge'],
                'total_tip' => $summary['total_tip'],
                'total_refunded' => $summary['total_refunded'] ?? 0,
                'total_paid' => $summary['total_paid'],
                'gst_net_taxable' => $summary['gst_net_taxable'] ?? 0,
                'vat_net_taxable' => $summary['vat_net_taxable'] ?? 0,
                'cgst_amount' => $summary['cgst_amount'] ?? 0,
                'sgst_amount' => $summary['sgst_amount'] ?? 0,
                'igst_amount' => $summary['igst_amount'] ?? 0,
                'vat_tax_amount' => $summary['vat_tax_amount'] ?? 0,
                'cash_total' => $summary['cash_total'],
                'card_total' => $summary['card_total'],
                'upi_total' => $summary['upi_total'],
                'room_charge_total' => $summary['room_charge_total'],
                'order_count' => $summary['order_count'],
                'void_count' => $summary['void_count'],
            ];

            if (\Illuminate\Support\Facades\Schema::hasColumn('pos_day_closings', 'total_packing_charge')) {
                $payload['total_packing_charge'] = $summary['total_packing_charge'] ?? 0;
            }

            $closing->update($payload);
            $updated++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Updated {$updated} day closing snapshot(s).");

        return self::SUCCESS;
    }
}
