<?php

namespace App\Console\Commands;

use App\Models\InventoryItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Full picture of one inventory item: unit setup, stock on hand, every stock
 * movement and the ledger entries behind them. Used to work out the exact
 * remedy when units or opening counts were entered wrongly.
 */
class AuditInventoryItem extends Command
{
    protected $signature = 'inventory:item-audit {item : Item id, or part of the name}';

    protected $description = 'Show unit setup, stock and ledger history for an inventory item';

    public function handle(): int
    {
        $needle = (string) $this->argument('item');

        $query = InventoryItem::with(['purchaseUom', 'issueUom']);
        $items = ctype_digit($needle)
            ? $query->where('id', (int) $needle)->get()
            : $query->where('name', 'like', '%'.$needle.'%')->get();

        if ($items->isEmpty()) {
            $this->error('No inventory item matched "'.$needle.'".');

            return self::FAILURE;
        }

        if ($items->count() > 1) {
            $this->warn('Matched '.$items->count().' items:');
            foreach ($items as $i) {
                $this->line('   #'.$i->id.'  '.$i->name);
            }
            $this->newLine();
            $this->line('Re-run with the id of the one you want.');

            return self::SUCCESS;
        }

        $item = $items->first();
        $cf = (float) ($item->conversion_factor ?: 1);
        $cost = (float) ($item->cost_price ?? 0);

        $this->newLine();
        $this->info('#'.$item->id.'  '.$item->name);
        $this->line('   Purchase unit        '.($item->purchaseUom->name ?? '—'));
        $this->line('   Issue unit           '.($item->issueUom->name ?? '—'));
        $this->line('   Conversion factor    '.$cf.'   (1 purchase unit = '.$cf.' issue units)');
        $this->line('   Cost price           '.number_format($cost, 2).' per purchase unit');
        $this->line('   Unit cost in ledger  '.number_format($cf > 0 ? $cost / $cf : 0, 4).' per issue unit');
        $this->line('   Alcohol              '.($item->is_alcohol ? 'yes' : 'no'));

        $this->newLine();
        $this->line('Stock on hand (issue units):');
        $locations = DB::table('inventory_item_locations as iil')
            ->leftJoin('inventory_locations as l', 'l.id', '=', 'iil.inventory_location_id')
            ->where('iil.inventory_item_id', $item->id)
            ->selectRaw('l.name, iil.quantity')
            ->get();

        $onHand = 0.0;
        foreach ($locations as $l) {
            $onHand += (float) $l->quantity;
            $this->line('   '.str_pad((string) ($l->name ?? '—'), 28).number_format((float) $l->quantity, 2));
        }
        $this->line('   '.str_pad('TOTAL', 28).number_format($onHand, 2));
        $this->line('   '.str_pad('= purchase units', 28).($cf > 0 ? number_format($onHand / $cf, 4) : '—'));

        $transactions = DB::table('inventory_transactions')
            ->where('inventory_item_id', $item->id)
            ->orderBy('id')
            ->get();

        $this->newLine();
        $this->line('Stock movements ('.$transactions->count().'):');
        if ($transactions->isNotEmpty()) {
            $this->table(
                ['Tx', 'When', 'Type', 'Reason', 'Qty', 'Unit cost', 'Total cost'],
                $transactions->map(fn ($t) => [
                    $t->id,
                    substr((string) $t->created_at, 0, 16),
                    $t->type,
                    $t->reason,
                    number_format((float) $t->quantity, 2),
                    number_format((float) $t->unit_cost, 4),
                    number_format((float) $t->total_cost, 2),
                ])->all()
            );
        }

        $netIn = 0.0;
        $netValue = 0.0;
        foreach ($transactions as $t) {
            $sign = $t->type === 'out' ? -1 : 1;
            $netIn += $sign * (float) $t->quantity;
            $netValue += $sign * (float) $t->total_cost;
        }

        $this->line('Net movement quantity   '.number_format($netIn, 2).' issue units');
        $this->line('Net movement value      '.number_format($netValue, 2));

        $entries = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'jl.account_id')
            ->whereIn('je.source_id', $transactions->pluck('id')->all())
            ->whereIn('je.source_type', [
                'inventory_adjustment',
                'inventory_adjustment_in',
                'inventory_opening_stock',
                'inventory_opening_stock_reversal',
            ])
            ->orderBy('je.id')
            ->orderBy('jl.line_no')
            ->selectRaw('je.entry_number, je.source_type, je.source_id, a.code, a.name, jl.debit, jl.credit')
            ->get();

        $this->newLine();
        $this->line('Ledger entries ('.$entries->groupBy('entry_number')->count().'):');
        if ($entries->isNotEmpty()) {
            $this->table(
                ['Entry', 'Source', 'Tx', 'Code', 'Account', 'Debit', 'Credit'],
                $entries->map(fn ($l) => [
                    $l->entry_number,
                    $l->source_type,
                    $l->source_id,
                    $l->code,
                    $l->name,
                    number_format((float) $l->debit, 2),
                    number_format((float) $l->credit, 2),
                ])->all()
            );
        }

        $this->newLine();
        $this->line('Consistency check:');
        $bookedValue = round($netValue, 2);
        $impliedValue = round($onHand * ($cf > 0 ? $cost / $cf : 0), 2);
        $this->line('   Value booked to the ledger   '.number_format($bookedValue, 2));
        $this->line('   Value implied by todays cost '.number_format($impliedValue, 2));
        $this->line('   Difference                   '.number_format($bookedValue - $impliedValue, 2));

        if (abs($bookedValue - $impliedValue) > 0.5) {
            $this->warn('   Cost price or conversion factor changed after stock was entered.');
        }

        return self::SUCCESS;
    }
}
