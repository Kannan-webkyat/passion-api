<?php

/**
 * One-shot repair: BTL items from full-bottle COGS corrections whose
 * location qty is still ML-scale (e.g. 6750 instead of 18).
 *
 * Usage on server:
 *   php artisan tinker --execute="require 'scripts/repair_inflated_btl_stock.php';"
 *   php artisan tinker --execute="\$APPLY=true; require 'scripts/repair_inflated_btl_stock.php';"
 */

use App\Models\InventoryItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$APPLY = (bool) ($APPLY ?? false);

$ids = DB::table('journal_entries')
    ->where('source_type', 'inventory_cogs_correction')
    ->where('status', 'posted')
    ->pluck('source_id');

$itemIds = DB::table('inventory_transactions')
    ->whereIn('id', $ids)
    ->pluck('inventory_item_id')
    ->unique()
    ->values();

$btlId = DB::table('inventory_uoms')->where('short_name', 'BTL')->value('id');

echo ($APPLY ? "APPLY" : "DRY-RUN").' items='.$itemIds->count().PHP_EOL;

foreach ($itemIds as $id) {
    $item = DB::table('inventory_items')->where('id', $id)->first();
    if (! $item) {
        continue;
    }

    // "375ml" has no word-boundary between 375 and ml — do not use \b
    preg_match('/(375|500)/', (string) $item->name.' '.(string) ($item->sku ?? ''), $m);
    $size = (float) ($m[1] ?? 0);
    $iu = DB::table('inventory_uoms')->where('id', $item->issue_uom_id)->value('short_name');

    echo "#{$id} iu={$iu} size={$size} {$item->name}".PHP_EOL;

    if ($size < 100 || $iu !== 'BTL') {
        echo "  skip".PHP_EOL;
        continue;
    }

    $run = function () use ($id, $item, $size, $btlId, $APPLY) {
        $needs = false;
        $locs = DB::table('inventory_item_locations')
            ->where('inventory_item_id', $id)
            ->when($APPLY, fn ($q) => $q->lockForUpdate())
            ->get();

        foreach ($locs as $loc) {
            $q = (float) $loc->quantity;
            if ($q + 0.0001 < $size) {
                echo "  OK  loc{$loc->inventory_location_id}: {$q}".PHP_EOL;
                continue;
            }
            $needs = true;
            $new = round($q / $size, 4);
            echo "  FIX loc{$loc->inventory_location_id}: {$q} -> {$new}".PHP_EOL;
            if ($APPLY) {
                DB::table('inventory_item_locations')
                    ->where('inventory_item_id', $id)
                    ->where('inventory_location_id', $loc->inventory_location_id)
                    ->update(['quantity' => $new, 'updated_at' => now()]);
            }
        }

        if (! $needs) {
            return;
        }

        if (! $APPLY) {
            return;
        }

        if (Schema::hasTable('inventory_cost_layers')) {
            foreach (DB::table('inventory_cost_layers')->where('inventory_item_id', $id)->lockForUpdate()->get() as $layer) {
                $rem = (float) $layer->quantity_remaining;
                $recv = (float) $layer->quantity_received;
                if ($rem + 0.0001 < $size && $recv + 0.0001 < $size) {
                    continue;
                }
                DB::table('inventory_cost_layers')->where('id', $layer->id)->update([
                    'quantity_received' => round($recv / $size, 4),
                    'quantity_remaining' => round($rem / $size, 4),
                    'landed_unit_cost' => round(((float) $layer->landed_unit_cost) * $size, 4),
                    'merchandise_unit_cost' => round(((float) $layer->merchandise_unit_cost) * $size, 4),
                    'cess_unit_cost' => round(((float) $layer->cess_unit_cost) * $size, 4),
                    'freight_unit_cost' => round(((float) $layer->freight_unit_cost) * $size, 4),
                    'non_recoverable_tax_unit_cost' => round(((float) $layer->non_recoverable_tax_unit_cost) * $size, 4),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('inventory_items')->where('id', $id)->update([
            'issue_uom_id' => $btlId,
            'purchase_uom_id' => $btlId,
            'conversion_factor' => 1,
            'updated_at' => now(),
        ]);

        InventoryItem::syncStoredCurrentStockFromLocations((int) $id);
        echo "  FIXED {$item->name}".PHP_EOL;
    };

    if ($APPLY) {
        DB::transaction($run);
    } else {
        $run();
    }
}

echo 'DONE'.PHP_EOL;
