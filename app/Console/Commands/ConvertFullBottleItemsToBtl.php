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
 */
class ConvertFullBottleItemsToBtl extends Command
{
    protected $signature = 'inventory:convert-full-bottle-to-btl
                            {--force : Apply UOM + stock conversion (otherwise preview only)}
                            {--from-corrections : Only items touched by inventory_cogs_correction journals}';

    protected $description = 'Convert 375/500 full-bottle items from ML stock to BTL (cf=1)';

    public function handle(): int
    {
        $apply = (bool) $this->option('force');
        $fromCorrections = (bool) $this->option('from-corrections');

        $btlId = DB::table('inventory_uoms')->where('short_name', 'BTL')->value('id');
        if (! $btlId) {
            $this->error('BTL UOM not found.');

            return self::FAILURE;
        }

        $itemIds = null;
        if ($fromCorrections) {
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
            // Exclude pour sizes / larger bottles that stay on ML
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

            return self::SUCCESS;
        }

        $table = [];
        $converted = 0;

        foreach ($items as $item) {
            $size = (float) $item->conversion_factor;
            // Prefer size from name when cf is wrong
            if (preg_match('/\b(375|500)\b/', (string) $item->name, $m)) {
                $size = (float) $m[1];
            } elseif (preg_match('/\b(375|500)\b/', (string) ($item->sku ?? ''), $m)) {
                $size = (float) $m[1];
            }

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
                        ->get(['id', 'quantity_received', 'quantity_remaining', 'landed_unit_cost', 'merchandise_unit_cost', 'cess_unit_cost', 'freight_unit_cost', 'non_recoverable_tax_unit_cost']);

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
                    // cost_price stays per bottle (purchase unit)
                    'updated_at' => now(),
                ]);

                // Full-bottle menu variants: ml_quantity was bottle size in ML stock; now 1 bottle.
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

        $this->table(
            ['ID', 'Item', 'Status', 'UOM', 'Stock', 'Menu'],
            $table
        );

        $this->line(($apply ? 'APPLIED' : 'DRY-RUN').' items='.$items->count().' converted='.$converted);

        if (! $apply) {
            $this->comment('Re-run with --from-corrections --force to convert only the corrected full-bottle SKUs.');
        }

        return self::SUCCESS;
    }
}
