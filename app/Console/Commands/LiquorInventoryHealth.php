<?php

namespace App\Console\Commands;

use App\Services\Accounting\TrialBalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot liquor health check: UOM, stock vs book, openings, POS deducts, GRNs.
 * Dry report only — never mutates data.
 */
class LiquorInventoryHealth extends Command
{
    protected $signature = 'inventory:liquor-health
                            {--suspects-only : Only print rows with flags}
                            {--limit=0 : Max rows to print (0 = all)}';

    protected $description = 'Audit all alcohol items: UOM, book vs stock, openings, POS, GRN';

    public function handle(TrialBalanceService $trialBalance): int
    {
        $suspectsOnly = (bool) $this->option('suspects-only');
        $limit = (int) $this->option('limit');

        $this->info('=== LIQUOR HEALTH CHECK ===');
        $this->line('Date: '.now()->toDateTimeString());

        // 1) Trial balance
        $tb = $trialBalance->forPeriod('2026-01-01', now()->toDateString());
        $this->newLine();
        $this->info('1) Trial balance');
        $this->line('   balanced='.($tb['totals']['balanced'] ? 'YES' : 'NO')
            .' debit='.$tb['totals']['debit']
            .' credit='.$tb['totals']['credit']);
        foreach ($tb['accounts'] as $a) {
            if (in_array($a['code'], ['1311', '5110', '3900', '6100'], true)) {
                $this->line(sprintf(
                    '   %s %s bal=%s',
                    $a['code'],
                    $a['name'],
                    number_format($a['balance'], 2)
                ));
            }
        }

        $items = DB::table('inventory_items as i')
            ->join('inventory_uoms as iu', 'iu.id', '=', 'i.issue_uom_id')
            ->leftJoin('inventory_uoms as pu', 'pu.id', '=', 'i.purchase_uom_id')
            ->where('i.is_alcohol', 1)
            ->orderBy('i.name')
            ->get([
                'i.id',
                'i.name',
                'i.sku',
                'i.conversion_factor',
                'i.cost_price',
                'i.current_stock',
                'iu.short_name as issue_uom',
                'pu.short_name as purchase_uom',
            ]);

        $this->newLine();
        $this->info('2) Alcohol items: '.$items->count());

        $ok = 0;
        $suspect = 0;
        $rows = [];

        foreach ($items as $i) {
            $flags = [];
            $stock = (float) $i->current_stock;
            $cf = (float) $i->conversion_factor;
            $cost = (float) $i->cost_price;
            $size = $this->bottleSizeMl($i);

            // --- UOM expectations ---
            if ($size >= 700) {
                // Peg spirits: prefer ML + cf ≈ bottle ml
                if ($i->issue_uom === 'BTL' && $this->hasPegVariants((int) $i->id, $size)) {
                    $flags[] = 'PEGS_ON_BTL';
                }
                if ($i->issue_uom === 'ML' && $size > 0 && abs($cf - $size) > 1) {
                    $flags[] = 'CF_WEIRD';
                }
                if ($i->issue_uom === 'BTL' && abs($cf - 1) > 0.01) {
                    $flags[] = 'CF_WEIRD';
                }
            } elseif ($size >= 300 && $size <= 550) {
                // Full-bottle SKUs often BTL/cf=1; ML+cf=size also OK
                if ($i->issue_uom === 'BTL' && abs($cf - 1) > 0.01) {
                    $flags[] = 'CF_WEIRD';
                }
                if ($i->issue_uom === 'BTL' && $this->hasPegVariants((int) $i->id, $size)) {
                    $flags[] = 'PEGS_ON_BTL';
                }
            } else {
                // Beer / other: BTL cf=1 preferred
                if ($i->issue_uom === 'ML') {
                    $flags[] = 'ISSUE_ML';
                }
                if ($i->issue_uom === 'BTL' && abs($cf - 1) > 0.01 && $stock > 0) {
                    $flags[] = 'CF_NOT_1';
                }
            }

            // --- Opening typed as ml on BTL ---
            $opens = DB::table('inventory_transactions')
                ->where('inventory_item_id', $i->id)
                ->where('reason', 'like', '%Opening%')
                ->get();
            $openQty = (float) $opens->sum('quantity');
            $openUc = $opens->count() ? (float) $opens->avg('unit_cost') : 0.0;
            $openLooksMl = $i->issue_uom === 'BTL'
                && $size >= 300
                && $openQty >= $size
                && $cost > 0
                && abs($openUc * $size - $cost) < max(1.0, $cost * 0.05);

            // --- Stock still ml-scale on BTL ---
            $stockHi = $i->issue_uom === 'BTL' && $size >= 300 && $stock + 0.0001 >= $size;
            if ($stockHi) {
                $flags[] = 'STOCK_HI';
            }

            // --- Book vs physical (movement value) ---
            $in = (float) DB::table('inventory_transactions')
                ->where('inventory_item_id', $i->id)
                ->where('type', 'in')
                ->sum('total_cost');
            $out = (float) DB::table('inventory_transactions')
                ->where('inventory_item_id', $i->id)
                ->where('type', 'out')
                ->sum('total_cost');
            $book = round($in - $out, 2);
            $phys = $this->physicalValue($i, $stock, $cf, $cost);
            $physDiv = ($i->issue_uom === 'BTL' && $size >= 300 && $stock >= $size)
                ? round(($stock / $size) * $cost, 2)
                : null;

            $bookOk = abs($book - $phys) <= 2
                || ($physDiv !== null && abs($book - $physDiv) <= 2);
            if (! $bookOk && abs($book - $phys) > 2) {
                $flags[] = 'BOOK_GAP';
            }

            // Historical ml opening only matters if stock/book still broken
            if ($openLooksMl && ($stockHi || ! $bookOk)) {
                $flags[] = 'OPEN_ML';
            }

            // --- Bottle-rate unit cost on ML outs ---
            if ($i->issue_uom === 'ML' && $cf > 1) {
                $badUc = DB::table('inventory_transactions')
                    ->where('inventory_item_id', $i->id)
                    ->where('type', 'out')
                    ->where('reason', 'POS Order')
                    ->where('unit_cost', '>', max(10, $cost * 0.2))
                    ->count();
                if ($badUc > 0) {
                    $flags[] = 'BOTTLE_RATE_ON_ML';
                }
            }

            // --- POS deduct vs billed lines (coarse) ---
            $posQty = (float) DB::table('inventory_transactions')
                ->where('inventory_item_id', $i->id)
                ->where('type', 'out')
                ->where('reason', 'POS Order')
                ->sum('quantity');

            $pour = $this->fairPourIssueUnits($i, $size, $cf);
            if ($i->issue_uom === 'BTL' && $posQty - $pour > 0.5) {
                $flags[] = 'OVER_COGS';
            }
            if ($i->issue_uom === 'ML' && $cf > 1 && ($posQty - $pour) > $cf * 0.5) {
                $flags[] = 'OVER_COGS';
            }

            // GRN qty weird: tiny qty with huge unit cost on ML item
            if ($i->issue_uom === 'ML' && $cf > 1) {
                $weirdGrn = DB::table('inventory_transactions')
                    ->where('inventory_item_id', $i->id)
                    ->where('reason', 'like', '%GRN%')
                    ->where('quantity', '<', $cf * 0.5)
                    ->where('unit_cost', '>', $cost * 0.5)
                    ->count();
                if ($weirdGrn > 0) {
                    $flags[] = 'GRN_QTY_WEIRD';
                }
            }

            if ($flags === []) {
                $ok++;
            } else {
                $suspect++;
            }

            if ($suspectsOnly && $flags === []) {
                continue;
            }

            $rows[] = [
                $flags === [] ? 'OK' : 'SUSPECT',
                $i->id,
                $i->issue_uom,
                $cf,
                round($stock, 2),
                number_format($book, 2),
                number_format($phys, 2),
                $flags === [] ? '-' : implode(',', $flags),
                mb_substr($i->name, 0, 48),
            ];
        }

        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        $this->table(
            ['Status', 'ID', 'UOM', 'CF', 'Stock', 'Book₹', 'Phys₹', 'Flags', 'Name'],
            $rows
        );

        $this->newLine();
        $this->info("SUMMARY: total={$items->count()} OK={$ok} SUSPECT={$suspect}");
        $this->newLine();
        $this->comment('Flag guide:');
        $this->line('  PEGS_ON_BTL / BOTTLE_RATE_ON_ML / OVER_COGS — sales deduct / COGS wrong');
        $this->line('  OPEN_ML / STOCK_HI — opening or on-hand still in ml on a BTL item');
        $this->line('  BOOK_GAP — movement value ≠ stock×cost (check cleanup JEs / GRN)');
        $this->line('  GRN_QTY_WEIRD — GRN posted bottle count into ML stock');
        $this->line('  CF_WEIRD / CF_NOT_1 / ISSUE_ML — master data unit setup');
        $this->newLine();
        $this->comment('Also useful:');
        $this->line('  php artisan inventory:item-audit {id}');
        $this->line('  Re-run with --suspects-only to focus.');

        return $tb['totals']['balanced'] && $suspect === 0
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function bottleSizeMl(object $item): float
    {
        $hay = (string) $item->name.' '.(string) ($item->sku ?? '');
        if (preg_match('/(1000|750|650|500|375|330)/', $hay, $m)) {
            return (float) $m[1];
        }
        $cf = (float) ($item->conversion_factor ?? 0);

        return $cf >= 100 ? $cf : 0.0;
    }

    private function hasPegVariants(int $itemId, float $bottleMl): bool
    {
        if ($bottleMl < 100) {
            return false;
        }

        return DB::table('menu_items as mi')
            ->join('menu_item_variants as v', 'v.menu_item_id', '=', 'mi.id')
            ->where('mi.inventory_item_id', $itemId)
            ->where('v.ml_quantity', '>', 1)
            ->where('v.ml_quantity', '<', $bottleMl - 1)
            ->exists();
    }

    private function physicalValue(object $item, float $stock, float $cf, float $cost): float
    {
        if ($item->issue_uom === 'ML' && $cf > 1) {
            return round(($stock / $cf) * $cost, 2);
        }

        return round($stock * $cost, 2);
    }

    private function fairPourIssueUnits(object $item, float $size, float $cf): float
    {
        $lines = DB::table('pos_order_items as poi')
            ->join('pos_orders as po', 'po.id', '=', 'poi.order_id')
            ->leftJoin('menu_item_variants as v', 'v.id', '=', 'poi.menu_item_variant_id')
            ->join('menu_items as mi', 'mi.id', '=', 'poi.menu_item_id')
            ->where('mi.inventory_item_id', $item->id)
            ->whereNotIn('po.status', ['cancelled', 'void'])
            ->get(['poi.quantity', 'v.ml_quantity']);

        $pourMl = 0.0;
        foreach ($lines as $l) {
            $ml = (float) ($l->ml_quantity ?? 0);
            if ($ml <= 0) {
                $ml = $item->issue_uom === 'ML' ? max($cf, 1) : max($size, 1);
            }
            $pourMl += $ml * (float) $l->quantity;
        }

        if ($item->issue_uom === 'ML') {
            return $pourMl;
        }

        return $size > 0 ? $pourMl / $size : (float) $lines->sum('quantity');
    }
}
