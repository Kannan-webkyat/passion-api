<?php

use App\Http\Controllers\PosController;
use App\Models\PosOrder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('pos:recalculate-tax-splits {--chunk=200}', function () {
    $chunk = max(1, (int) $this->option('chunk'));
    $ctrl = app(PosController::class);
    $n = 0;
    PosOrder::query()->orderBy('id')->chunkById($chunk, function ($orders) use ($ctrl, &$n) {
        foreach ($orders as $order) {
            $o = PosOrder::query()
                ->with([
                    'items' => fn ($q) => $q->where('status', 'active'),
                    'items.menuItem.tax',
                    'items.combo.menuItems.tax',
                ])
                ->find($order->id);
            if ($o) {
                $ctrl->maintenanceRecalculateOrderTotals($o);
                $n++;
            }
        }
    });
    $this->info("Recalculated {$n} orders.");
})->purpose('Backfill CGST/SGST/IGST/VAT columns on pos_orders from line items + tax master');
