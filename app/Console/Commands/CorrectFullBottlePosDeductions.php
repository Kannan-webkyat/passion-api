<?php

namespace App\Console\Commands;

use App\Models\InventoryItem;
use App\Services\Accounting\AccountCodes;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\PosCogsBusinessDateResolver;
use App\Services\InventoryCostLayerService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Past full-bottle (375/500) POS sales sometimes deducted 1 ML instead of the bottle size.
 * This corrects stock, the original inventory_transactions row, FIFO layers, and posts a
 * delta COGS journal. POS bills / pos_settle journals are left unchanged.
 */
class CorrectFullBottlePosDeductions extends Command
{
    protected $signature = 'inventory:correct-full-bottle-pos-deductions
                            {--force : Apply stock, transaction, layer, and journal corrections (otherwise preview only)}';

    protected $description = 'Correct under-deducted 375/500ml full-bottle POS outs (qty=1) and post missing COGS';

    public function handle(
        JournalPostingService $journal,
        InventoryCostLayerService $layers,
        PosCogsBusinessDateResolver $dateResolver,
    ): int {
        $apply = (bool) $this->option('force');

        $rows = DB::table('inventory_transactions as t')
            ->join('inventory_items as i', 'i.id', '=', 't.inventory_item_id')
            ->join('inventory_uoms as iu', 'iu.id', '=', 'i.issue_uom_id')
            ->where('t.type', 'out')
            ->where('t.reference_type', 'pos_order')
            ->where('t.quantity', 1)
            ->where('t.reason', 'POS Order')
            ->where('iu.short_name', 'ML')
            ->where(function ($q) {
                $q->where('i.name', 'like', '%375%')
                    ->orWhere('i.name', 'like', '%500%')
                    ->orWhere('i.sku', 'like', '%375%')
                    ->orWhere('i.sku', 'like', '%500%');
            })
            ->orderBy('t.id')
            ->get([
                't.id',
                't.inventory_item_id',
                't.inventory_location_id',
                't.quantity',
                't.unit_cost',
                't.total_cost',
                't.reference_id',
                't.created_at',
                't.user_id',
                't.notes',
                'i.name',
                'i.conversion_factor',
                'i.is_alcohol',
                'i.cost_price',
            ]);

        if ($rows->isEmpty()) {
            $this->info('No matching qty=1 full-bottle POS outs found.');

            return self::SUCCESS;
        }

        $table = [];
        $totalExtraCost = 0.0;
        $fixed = 0;
        $skipped = 0;

        foreach ($rows as $r) {
            $need = (float) $r->conversion_factor;
            if ($need <= 1) {
                $skipped++;
                $table[] = [$r->id, $r->reference_id, $r->name, 'skip', 'cf<=1'];
                continue;
            }

            $extraQty = $need - 1.0;
            $unit = (float) $r->unit_cost;
            if ($unit <= 0) {
                $unit = round(((float) $r->cost_price) / max($need, 1), 4);
            }
            $extraCost = round($extraQty * $unit, 2);
            $already = DB::table('journal_entries')
                ->where('source_type', 'inventory_cogs_correction')
                ->where('source_id', $r->id)
                ->where('status', 'posted')
                ->exists();

            $status = $already ? 'already' : ($apply ? 'apply' : 'preview');
            $table[] = [
                $r->id,
                $r->reference_id,
                $r->name,
                sprintf('1 -> %s', $need),
                number_format($extraCost, 2),
                $status,
            ];
            $totalExtraCost += $already ? 0.0 : $extraCost;

            if (! $apply || $already || $extraCost <= 0) {
                if ($already) {
                    $skipped++;
                }
                continue;
            }

            try {
                DB::transaction(function () use ($r, $need, $extraQty, $unit, $extraCost, $journal, $layers, $dateResolver) {
                    $loc = DB::table('inventory_item_locations')
                        ->where('inventory_item_id', $r->inventory_item_id)
                        ->where('inventory_location_id', $r->inventory_location_id)
                        ->lockForUpdate()
                        ->first();

                    $avail = (float) ($loc->quantity ?? 0);
                    if ($avail + 0.0001 < $extraQty) {
                        throw new RuntimeException(
                            "Insufficient stock txn#{$r->id} loc={$r->inventory_location_id} have={$avail} need={$extraQty}"
                        );
                    }

                    DB::table('inventory_item_locations')
                        ->where('inventory_item_id', $r->inventory_item_id)
                        ->where('inventory_location_id', $r->inventory_location_id)
                        ->decrement('quantity', $extraQty);

                    $noteSuffix = ' [corrected full-bottle deduct 1 to '.$need.' ML]';
                    DB::table('inventory_transactions')->where('id', $r->id)->update([
                        'quantity' => $need,
                        'unit_cost' => $unit,
                        'total_cost' => round($need * $unit, 2),
                        'notes' => trim((string) ($r->notes ?? '').$noteSuffix),
                        'updated_at' => now(),
                    ]);

                    if (Schema::hasTable('inventory_cost_layers')) {
                        $layers->consume((int) $r->inventory_item_id, $extraQty);
                    }

                    $biz = $dateResolver->fromReference('pos_order', $r->reference_id);
                    $cogs = $r->is_alcohol ? AccountCodes::COGS_BAR : AccountCodes::COGS_RESTAURANT;
                    $inv = $r->is_alcohol ? AccountCodes::INVENTORY_LIQUOR : AccountCodes::INVENTORY_FOOD;
                    $entryDate = Carbon::parse($r->created_at)->toDateString();

                    $journal->post(
                        sourceType: 'inventory_cogs_correction',
                        sourceId: (int) $r->id,
                        entryDate: $entryDate,
                        businessDate: $biz,
                        sourceRef: 'INV-TX-CORR #'.$r->id,
                        memo: 'POS COGS correction — under-deducted full bottle (txn #'.$r->id.', order '.$r->reference_id.')',
                        lines: [
                            [
                                'account_code' => $cogs,
                                'debit' => $extraCost,
                                'meta' => [
                                    'inventory_transaction_id' => $r->id,
                                    'pos_order_id' => $r->reference_id,
                                ],
                            ],
                            [
                                'account_code' => $inv,
                                'credit' => $extraCost,
                                'meta' => [
                                    'inventory_transaction_id' => $r->id,
                                ],
                            ],
                        ],
                        postedBy: $r->user_id ? (int) $r->user_id : null,
                    );

                    InventoryItem::syncStoredCurrentStockFromLocations((int) $r->inventory_item_id);
                });
                $fixed++;
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
                $skipped++;
            }
        }

        $this->table(
            ['Txn', 'Order', 'Item', 'Qty', 'Extra COGS', 'Status'],
            $table
        );

        $this->line(($apply ? 'APPLIED' : 'DRY-RUN')." rows={$rows->count()} fixed={$fixed} skipped={$skipped} extra_cogs=".number_format($totalExtraCost, 2));

        if (! $apply) {
            $this->comment('Re-run with --force to apply.');
        }

        return self::SUCCESS;
    }
}
