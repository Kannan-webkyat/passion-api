<?php

use App\Services\KgstBarTotPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $cutover = KgstBarTotPolicy::cutoverDate() ?? '2026-04-01';

        if (Schema::hasTable('pos_order_items') && Schema::hasTable('pos_orders')) {
            $lineIds = DB::table('pos_order_items as poi')
                ->join('pos_orders as po', 'po.id', '=', 'poi.order_id')
                ->where('poi.tax_regime', 'vat_liquor')
                ->where('po.business_date', '>=', $cutover)
                ->where('poi.tax_rate', '>', 0)
                ->pluck('poi.id');

            foreach ($lineIds->chunk(500) as $chunk) {
                DB::table('pos_order_items')
                    ->whereIn('id', $chunk->all())
                    ->update([
                        'tax_rate' => 0,
                        'updated_at' => now(),
                    ]);
            }
        }

        if (Schema::hasTable('menu_items') && Schema::hasTable('inventory_items')) {
            DB::statement(
                'UPDATE menu_items m
                INNER JOIN inventory_items i ON i.id = m.inventory_item_id
                SET m.is_direct_sale = 1, m.updated_at = ?
                WHERE i.is_alcohol = 1 AND m.is_direct_sale = 0',
                [now()]
            );
        }
    }

    public function down(): void
    {
        // Display-only / consistency fields — not reversed.
    }
};
