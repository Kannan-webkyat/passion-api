<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * All menu/combo prices and open-order defaults are tax-inclusive (toggle removed from admin).
     */
    public function up(): void
    {
        if (Schema::hasTable('restaurant_menu_items') && Schema::hasColumn('restaurant_menu_items', 'price_tax_inclusive')) {
            DB::table('restaurant_menu_items')->update(['price_tax_inclusive' => true]);
        }
        if (Schema::hasTable('restaurant_combos') && Schema::hasColumn('restaurant_combos', 'price_tax_inclusive')) {
            DB::table('restaurant_combos')->update(['price_tax_inclusive' => true]);
        }
        if (Schema::hasTable('pos_orders') && Schema::hasColumn('pos_orders', 'prices_tax_inclusive')) {
            DB::table('pos_orders')->update(['prices_tax_inclusive' => true]);
        }
        if (Schema::hasTable('pos_order_items') && Schema::hasColumn('pos_order_items', 'price_tax_inclusive')) {
            DB::table('pos_order_items')->update(['price_tax_inclusive' => true]);
        }
    }

    public function down(): void
    {
        // Irreversible data change; no-op.
    }
};
