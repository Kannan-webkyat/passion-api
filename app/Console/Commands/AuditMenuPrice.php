<?php

namespace App\Console\Commands;

use App\Models\MenuItem;
use App\Models\RestaurantMaster;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantMenuItemVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Shows the sell price POS resolves for an item at every outlet, mirroring the
 * resolution used by the menu endpoint and by cart sync, plus any open order
 * lines still holding an older price.
 */
class AuditMenuPrice extends Command
{
    protected $signature = 'pos:price-audit
                            {item : Menu item id, item code, or part of the name}
                            {--stale : Also scan every item for open order lines priced differently}';

    protected $description = 'Show the POS price per outlet for a menu item and flag fallbacks';

    public function handle(): int
    {
        if ($this->option('stale')) {
            return $this->scanStaleOrderLines();
        }

        $needle = (string) $this->argument('item');
        $query = MenuItem::query();
        $items = ctype_digit($needle)
            ? $query->where('id', (int) $needle)->get()
            : $query->where('item_code', $needle)
                ->orWhere('name', 'like', '%'.$needle.'%')
                ->get();

        if ($items->isEmpty()) {
            $this->error('No menu item matched "'.$needle.'".');

            return self::FAILURE;
        }

        if ($items->count() > 1) {
            $this->warn('Matched '.$items->count().' items:');
            foreach ($items as $i) {
                $this->line('   #'.$i->id.'  '.$i->name.'  ('.$i->item_code.')');
            }

            return self::SUCCESS;
        }

        $item = $items->first();
        $variants = DB::table('menu_item_variants')
            ->where('menu_item_id', $item->id)
            ->orderBy('sort_order')
            ->get();

        $this->newLine();
        $this->info('#'.$item->id.'  '.$item->name.'  ('.$item->item_code.')');
        $this->line('   Global menu_items.price   '.number_format((float) $item->price, 2).'   (reference only — POS never charges this when an outlet row exists)');
        $this->line('   Variants                  '.($variants->count() ?: 'none'));

        $outlets = RestaurantMaster::orderBy('id')->get();

        if ($variants->isEmpty()) {
            $this->newLine();
            $this->line('Price per outlet:');
            $rows = [];
            foreach ($outlets as $outlet) {
                $rmi = RestaurantMenuItem::where('menu_item_id', $item->id)
                    ->where('restaurant_master_id', $outlet->id)
                    ->first();
                $rows[] = [
                    $outlet->name,
                    $rmi ? number_format((float) $rmi->price, 2) : '—',
                    $rmi ? ($rmi->is_active ? 'yes' : 'no') : 'not linked',
                    $rmi ? (($rmi->price_tax_inclusive ?? true) ? 'inclusive' : 'exclusive') : '—',
                    $this->verdictForBase($rmi),
                ];
            }
            $this->table(['Outlet', 'POS price', 'Active', 'Tax', 'Note'], $rows);

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Variant price per outlet (this is what POS charges):');

        $rows = [];
        foreach ($outlets as $outlet) {
            $rmi = RestaurantMenuItem::where('menu_item_id', $item->id)
                ->where('restaurant_master_id', $outlet->id)
                ->first();

            foreach ($variants as $v) {
                if (! $rmi) {
                    $rows[] = [$outlet->name, $v->size_label, number_format((float) $v->price, 2), '—', 'NOT LINKED to outlet'];

                    continue;
                }

                $rvi = RestaurantMenuItemVariant::where('restaurant_menu_item_id', $rmi->id)
                    ->where('menu_item_variant_id', $v->id)
                    ->first();

                $resolved = $rvi ? (float) $rvi->price : (float) $v->price;
                $rows[] = [
                    $outlet->name.($rmi->is_active ? '' : ' (inactive)'),
                    $v->size_label,
                    number_format((float) $v->price, 2),
                    number_format($resolved, 2),
                    $rvi ? 'outlet price' : 'FALLBACK to global',
                ];
            }
        }

        $this->table(['Outlet', 'Size', 'Global', 'POS charges', 'Source'], $rows);

        $fallbacks = collect($rows)->filter(fn ($r) => str_contains((string) $r[4], 'FALLBACK'))->count();
        if ($fallbacks > 0) {
            $this->warn($fallbacks.' variant/outlet combination(s) have no outlet price and fall back to the global figure.');
            $this->line('Fix: open Menu Pricing, set the price for that outlet and size, and save.');
        }

        $this->showOpenLines($item->id);

        return self::SUCCESS;
    }

    private function verdictForBase(?RestaurantMenuItem $rmi): string
    {
        if (! $rmi) {
            return 'NOT LINKED — hidden in POS';
        }
        if (! $rmi->is_active) {
            return 'inactive — hidden in POS';
        }
        if ((float) $rmi->price <= 0) {
            return 'NO PRICE — hidden in POS';
        }

        return '';
    }

    private function showOpenLines(int $menuItemId): void
    {
        $lines = DB::table('pos_order_items as oi')
            ->join('pos_orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('menu_item_variants as v', 'v.id', '=', 'oi.menu_item_variant_id')
            ->where('oi.menu_item_id', $menuItemId)
            ->whereIn('o.status', ['open', 'billed'])
            ->where('oi.status', 'active')
            ->selectRaw('o.id order_id, o.restaurant_id, v.size_label, oi.quantity, oi.unit_price')
            ->get();

        if ($lines->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('Open order lines for this item:');
        $this->table(
            ['Order', 'Outlet', 'Size', 'Qty', 'Charged'],
            $lines->map(fn ($l) => [
                $l->order_id,
                RestaurantMaster::find($l->restaurant_id)?->name ?? $l->restaurant_id,
                $l->size_label ?? '—',
                $l->quantity,
                number_format((float) $l->unit_price, 2),
            ])->all()
        );
        $this->line('A line keeps the price it was added at. Changing Menu Pricing does not reprice open orders.');
    }

    /**
     * Open order lines whose price no longer matches what the outlet would charge today.
     */
    private function scanStaleOrderLines(): int
    {
        $lines = DB::table('pos_order_items as oi')
            ->join('pos_orders as o', 'o.id', '=', 'oi.order_id')
            ->join('menu_items as mi', 'mi.id', '=', 'oi.menu_item_id')
            ->whereIn('o.status', ['open', 'billed'])
            ->where('oi.status', 'active')
            ->selectRaw('oi.id, o.id order_id, o.restaurant_id, mi.id item_id, mi.name item, oi.menu_item_variant_id, oi.unit_price')
            ->get();

        $bad = [];
        foreach ($lines as $l) {
            $rmi = RestaurantMenuItem::where('menu_item_id', $l->item_id)
                ->where('restaurant_master_id', $l->restaurant_id)
                ->where('is_active', true)
                ->first();
            if (! $rmi) {
                continue;
            }

            if ($l->menu_item_variant_id) {
                $rvi = RestaurantMenuItemVariant::where('restaurant_menu_item_id', $rmi->id)
                    ->where('menu_item_variant_id', $l->menu_item_variant_id)
                    ->first();
                $current = $rvi
                    ? (float) $rvi->price
                    : (float) (DB::table('menu_item_variants')->where('id', $l->menu_item_variant_id)->value('price') ?? 0);
            } else {
                $current = (float) $rmi->price;
            }

            if (abs($current - (float) $l->unit_price) > 0.005) {
                $bad[] = [
                    $l->order_id,
                    RestaurantMaster::find($l->restaurant_id)?->name ?? $l->restaurant_id,
                    mb_substr($l->item, 0, 34),
                    number_format((float) $l->unit_price, 2),
                    number_format($current, 2),
                ];
            }
        }

        $this->newLine();
        if ($bad === []) {
            $this->info('No open order line is priced differently from its outlet price.');

            return self::SUCCESS;
        }

        $this->warn(count($bad).' open order line(s) priced differently from the current outlet price:');
        $this->table(['Order', 'Outlet', 'Item', 'Charged', 'Outlet price now'], $bad);
        $this->line('These were added before the price changed. Remove and re-add the line to reprice.');

        return self::SUCCESS;
    }
}
