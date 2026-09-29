<?php

namespace App\Console\Commands;

use App\Models\InventoryItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert 375ml / 500ml full-bottle liquor items from issue ML to BTL (cf=1).
 * Stock and cost layers are scaled ML → bottles. Menu full-bottle variants
 * with ml_quantity matching the old bottle size become 1 (one bottle).
 *
 * Leave 750 / 1000 peg items on ML — do not run this for those SKUs.
 *
 * --repair-inflated: fix items already marked BTL whose location qty is still ML-scale
 * (e.g. 6750 BTL instead of 18). Safe to re-run; skips rows already in bottle counts.
 */
class ConvertFullBottleItemsToBtl extends Command
{
    protected $signature = 'inventory:convert-full-bottle-to-btl
                            {--force : Apply changes (otherwise preview only)}
                            {--from-corrections : Only items touched by inventory_cogs_correction journals}
                            {--repair-inflated : Fix BTL items whose stock qty is still in ML scale}';

    protected $description = 'Convert 375/500 full-bottle items from ML stock to BTL (cf=1)';

    public function handle(): int
    {
        $apply = (bool) $this->option('force');
        $fromCorrections = (bool) $this->option('from-corrections');
        $repairInflated = (bool) $this->option('repair-inflated');

        $btlId = (int) DB::table('inventory_uoms')->where('short_name', 'BTL')->value('id');
        if (! $btlId) {
            $this->error('BTL UOM not found.');

            return self::FAILURE;
        }

        $itemIds = null;
        if ($fromCorrections || $repairInflated) {
            $txnIds = DB::table('journal_entries')
                ->where('source_type', 'inventory_cogs_correction')
                ->where('status', 'posted')
                ->pluck('source_id');

            $itemIds = DB::table('inventory_transactions')
                ->whereIn('id', $txnIds)
                ->pluck('inventory_item_id')
                ->unique()
                ->values()
                ->all();

            if ($itemIds === []) {
                $this->info('No items found from correction journals.');

                return self::SUCCESS;
            }
        }

        if ($repairInflated) {
            return $this->repairInflated($apply, $itemIds, $btlId);
        }

        $query = DB::table('inventory_items as i')
            ->join('inventory_uoms as iu', 'iu.id', '=', 'i.issue_uom_id')
            ->where('iu.short_name', 'ML')
            ->where('i.conversion_factor', '>', 1)
            ->where(function ($q) {
                $q->where('i.name', 'like', '%375%')
                    ->orWhere('i.name', 'like', '%500%')
                    ->orWhere('i.sku', 'like', '%375%')
                    ->orWhere('i.sku', 'like', '%500%');
            })
            ->where('i.name', 'not like', '%750%')
            ->where('i.name', 'not like', '%1000%')
            ->where('i.name', 'not like', '%1L%')
            ->where('i.sku', 'not like', '%750%')
            ->where('i.sku', 'not like', '%1000%');

        if ($itemIds !== null) {
            $query->whereIn('i.id', $itemIds);
        }

        $items = $query->orderBy('i.name')->get([
            'i.id',
            'i.name',
            'i.sku',
            'i.conversion_factor',
            'i.cost_price',
            'i.purchase_uom_id',
            'i.issue_uom_id',
        ]);

        if ($items->isEmpty()) {
            $this->info('No 375/500 ML items left to convert (already BTL or none match).');
            $this->comment('If bottle counts look like ML (e.g. 6750 BTL), run:');
            $this->comment('  php artisan inventory:convert-full-bottle-to-btl --repair-inflated');
            $this->comment('  php artisan inventory:convert-full-bottle-to-btl --repair-inflated --force');

            return self::SUCCESS;
        }

        return $this->convertMlItems($items, $apply, $btlId);
    }

    /**
     * @param  list<int>|null  $itemIds
     */
    private function repairInflated(bool $apply, ?array $itemIds, int $btlId): int
    {
        $query = DB::table('inventory_items as i')
            ->join('inventory_uoms as iu', 'iu.id', '=', 'i.issue_uom_id')
            ->where('iu.short_name', 'BTL')
            ->where(function ($q) {
                $q->where('i.name', 'like', '%375%')
                    ->orWhere('i.name', 'like', '%500%')
                    ->orWhere('i.sku', 'like', '%375%')
                    ->orWhere('i.sku', 'like', '%500%');
            })
            ->where('i.name', 'not like', '%750%')
            ->where('i.name', 'not like', '%1000%');

        if ($itemIds !== null) {
            $query->whereIn('i.id', $itemIds);
        }

        $items = $query->orderBy('i.name')->get([
            'i.id',
            'i.name',
            'i.sku',
            'i.conversion_factor',
            'i.issue_uom_id',
            'i.purchase_uom_id',
        ]);

        $table = [];
        $fixed = 0;

        foreach ($items as $item) {
            $size = $this->bottleSizeMl($item);
            if ($size < 100) {
                $table[] = [$item->id, $item->name, 'skip', 'no bottle size', ''];
                continue;
            }

            $locs = DB::table('inventory_item_locations')
                ->where('inventory_item_id', $item->id)
                ->get(['inventory_location_id', 'quantity']);

            $needs = false;
            $parts = [];
            foreach ($locs as $loc) {
                $qty = (float) $loc->quantity;
                // Already bottle-scale (e.g. 18) — leave alone. ML-scale leftover is >= bottle size.
                if ($qty + 0.0001 >= $size) {
                    $needs = true;
                    $parts[] = 'loc'.$loc->inventory_location_id.': '.$qty.' -> '.round($qty / $size, 4);
                } else {
                    $parts[] = 'loc'.$loc->inventory_location_id.': '.$qty.' (ok)';
                }
            }

            if (! $needs) {
                $table[] = [$item->id, $item->name, 'ok', 'already bottle counts', implode('; ', $parts)];
                continue;
            }

            $table[] = [
                $item->id,
                $item->name,
                $apply ? 'repair' : 'preview',
                'divide by '.$size,
                implode('; ', $parts),
            ];

            if (! $apply) {
                continue;
            }

            DB::transaction(function () use ($item, $size, $locs, $btlId) {
                foreach ($locs as $loc) {
                    $qty = (float) $loc->quantity;
                    if ($qty + 0.0001 < $size) {
                        continue;
                    }
                    DB::table('inventory_item_locations')
                        ->where('inventory_item_id', $item->id)
                        ->where('inventory_location_id', $loc->inventory_location_id)
                        ->update([
                            'quantity' => round($qty / $size, 4),
                            'updated_at' => now(),
                        ]);
                }

                if (Schema::hasTable('inventory_cost_layers')) {
                    $layers = DB::table('inventory_cost_layers')
                        ->where('inventory_item_id', $item->id)
                        ->lockForUpdate()
                        ->get();

                    foreach ($layers as $layer) {
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

                DB::table('inventory_items')->where('id', $item->id)->update([
                    'issue_uom_id' => $btlId,
                    'purchase_uom_id' => $btlId,
                    'conversion_factor' => 1,
                    'updated_at' => now(),
                ]);

                InventoryItem::syncStoredCurrentStockFromLocations((int) $item->id);
            });

            $fixed++;
        }

        $this->table(['ID', 'Item', 'Status', 'Action', 'Stock'], $table);
        $this->line(($apply ? 'APPLIED' : 'DRY-RUN')." repair fixed={$fixed}");

        if (! $apply) {
            $this->comment('Re-run with --repair-inflated --force to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $items
     */
    private function convertMlItems($items, bool $apply, int $btlId): int
    {
        $table = [];
        $converted = 0;

        foreach ($items as $item) {
            $size = $this->bottleSizeMl($item);
            if ($size < 100) {
                $table[] = [$item->id, $item->name, 'skip', 'bad size '.$size, '', ''];
                continue;
            }

            $locs = DB::table('inventory_item_locations')
                ->where('inventory_item_id', $item->id)
                ->get(['inventory_location_id', 'quantity']);

            $stockParts = [];
            foreach ($locs as $loc) {
                $ml = (float) $loc->quantity;
                $btl = round($ml / $size, 4);
                $stockParts[] = 'loc'.$loc->inventory_location_id.': '.$ml.'ML -> '.$btl.' BTL';
            }

            $table[] = [
                $item->id,
                $item->name,
                $apply ? 'convert' : 'preview',
                'cf '.$item->conversion_factor.' -> 1, issue BTL',
                implode('; ', $stockParts) ?: 'no stock rows',
                'variants ml='.$size.' -> 1',
            ];

            if (! $apply) {
                continue;
            }

            DB::transaction(function () use ($item, $size, $btlId, $locs) {
                foreach ($locs as $loc) {
                    $ml = (float) $loc->quantity;
                    $btl = round($ml / $size, 4);
                    DB::table('inventory_item_locations')
                        ->where('inventory_item_id', $item->id)
                        ->where('inventory_location_id', $loc->inventory_location_id)
                        ->update(['quantity' => $btl, 'updated_at' => now()]);
                }

                if (Schema::hasTable('inventory_cost_layers')) {
                    $layers = DB::table('inventory_cost_layers')
                        ->where('inventory_item_id', $item->id)
                        ->lockForUpdate()
                        ->get();

                    foreach ($layers as $layer) {
                        DB::table('inventory_cost_layers')->where('id', $layer->id)->update([
                            'quantity_received' => round(((float) $layer->quantity_received) / $size, 4),
                            'quantity_remaining' => round(((float) $layer->quantity_remaining) / $size, 4),
                            'landed_unit_cost' => round(((float) $layer->landed_unit_cost) * $size, 4),
                            'merchandise_unit_cost' => round(((float) $layer->merchandise_unit_cost) * $size, 4),
                            'cess_unit_cost' => round(((float) $layer->cess_unit_cost) * $size, 4),
                            'freight_unit_cost' => round(((float) $layer->freight_unit_cost) * $size, 4),
                            'non_recoverable_tax_unit_cost' => round(((float) $layer->non_recoverable_tax_unit_cost) * $size, 4),
                            'updated_at' => now(),
                        ]);
                    }
                }

                DB::table('inventory_items')->where('id', $item->id)->update([
                    'issue_uom_id' => $btlId,
                    'purchase_uom_id' => $btlId,
                    'conversion_factor' => 1,
                    'updated_at' => now(),
                ]);

                $menuIds = DB::table('menu_items')
                    ->where('inventory_item_id', $item->id)
                    ->pluck('id');

                if ($menuIds->isNotEmpty()) {
                    DB::table('menu_item_variants')
                        ->whereIn('menu_item_id', $menuIds)
                        ->where(function ($q) use ($size) {
                            $q->where('ml_quantity', $size)
                                ->orWhereBetween('ml_quantity', [$size - 0.01, $size + 0.01]);
                        })
                        ->update([
                            'ml_quantity' => 1,
                            'updated_at' => now(),
                        ]);
                }

                InventoryItem::syncStoredCurrentStockFromLocations((int) $item->id);
            });

            $converted++;
        }

        $this->table(['ID', 'Item', 'Status', 'UOM', 'Stock', 'Menu'], $table);
        $this->line(($apply ? 'APPLIED' : 'DRY-RUN').' items='.$items->count().' converted='.$converted);

        if (! $apply) {
            $this->comment('Re-run with --force to apply.');
        }

        return self::SUCCESS;
    }

    private function bottleSizeMl(object $item): float
    {
        $size = (float) ($item->conversion_factor ?? 0);
        // Names are often "375ml" — no word boundary between digits and "ml".
        if (preg_match('/(375|500)/', (string) $item->name.' '.(string) ($item->sku ?? ''), $m)) {
            return (float) $m[1];
        }

        return $size >= 100 ? $size : 0.0;
    }
}
