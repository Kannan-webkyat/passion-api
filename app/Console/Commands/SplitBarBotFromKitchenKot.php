<?php

namespace App\Console\Commands;

use App\Models\PosOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Move legacy bar/direct-sale lines from kot_sent → bot_sent so they no longer
 * pollute kitchen_status / KDS. Safe to run multiple times (idempotent).
 */
class SplitBarBotFromKitchenKot extends Command
{
    protected $signature = 'app:split-bar-bot-from-kitchen-kot
                            {--dry-run : Preview counts only}
                            {--before= : Only lines on orders opened before this datetime (Y-m-d H:i:s)}';

    protected $description = 'Convert misclassified bar KOT lines (kot_sent) to BOT-only (bot_sent)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $before = $this->option('before');

        $query = DB::table('pos_order_items as poi')
            ->join('pos_orders as po', 'po.id', '=', 'poi.order_id')
            ->join('menu_items as mi', 'mi.id', '=', 'poi.menu_item_id')
            ->leftJoin('inventory_items as ii', 'ii.id', '=', 'mi.inventory_item_id')
            ->leftJoin('inventory_taxes as it', 'it.id', '=', 'mi.tax_id')
            ->leftJoin('inventory_taxes as iit', 'iit.id', '=', 'ii.tax_id')
            ->where('poi.status', 'active')
            ->where('poi.kot_sent', true)
            ->where(function ($q) {
                $q->where('poi.bot_sent', false)->orWhereNull('poi.bot_sent');
            })
            ->whereNull('poi.combo_id')
            ->where(function ($q) {
                $q->where('mi.is_direct_sale', true)
                    ->orWhere('poi.tax_regime', 'vat_liquor')
                    ->orWhere('ii.is_alcohol', true)
                    ->orWhereRaw('LOWER(COALESCE(it.type, \'\')) = ?', ['vat'])
                    ->orWhereRaw('LOWER(COALESCE(iit.type, \'\')) = ?', ['vat']);
            });

        if ($before) {
            $query->where('po.opened_at', '<', $before);
        }

        $lineCount = (clone $query)->count();
        $orderCount = (clone $query)->distinct('poi.order_id')->count('poi.order_id');

        $this->info("Bar lines to convert: {$lineCount} across {$orderCount} orders");

        if ($dryRun || $lineCount === 0) {
            return self::SUCCESS;
        }

        $affectedOrderIds = [];

        DB::transaction(function () use ($query, &$affectedOrderIds) {
            $ids = (clone $query)->pluck('poi.id')->all();
            if ($ids === []) {
                return;
            }

            $affectedOrderIds = DB::table('pos_order_items')
                ->whereIn('id', $ids)
                ->pluck('order_id')
                ->unique()
                ->values()
                ->all();

            DB::table('pos_order_items')
                ->whereIn('id', $ids)
                ->update([
                    'bot_sent' => true,
                    'bot_sent_at' => DB::raw('COALESCE(kot_sent_at, updated_at, created_at)'),
                    'kot_sent' => false,
                    'kot_sent_at' => null,
                    'kot_started_at' => null,
                    'kitchen_ready_at' => null,
                    'kitchen_served_at' => null,
                    'updated_at' => now(),
                ]);
        });

        $served = 0;
        foreach ($affectedOrderIds as $orderId) {
            $order = PosOrder::with(['items.menuItem'])->find($orderId);
            if (! $order) {
                continue;
            }

            $kitchenKot = $order->items
                ->where('status', 'active')
                ->where('kot_sent', true);

            if ($kitchenKot->isEmpty()) {
                $order->update(['kitchen_status' => 'served']);
                $served++;

                continue;
            }

            if ($kitchenKot->every(fn ($i) => $i->kitchen_served_at)) {
                $order->update(['kitchen_status' => 'served']);
                $served++;
            } elseif ($kitchenKot->every(fn ($i) => $i->kitchen_ready_at)) {
                $order->update(['kitchen_status' => 'ready']);
            }
        }

        $this->info("Converted {$lineCount} lines. Re-synced kitchen_status on {$served} liquor-only orders to served.");

        return self::SUCCESS;
    }
}
