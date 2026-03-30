<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (Schema::hasTable('restaurant_menu_items') && Schema::hasColumn('restaurant_menu_items', 'price_tax_inclusive')) {
            DB::table('restaurant_menu_items')->update(['price_tax_inclusive' => true]);
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE restaurant_menu_items MODIFY price_tax_inclusive TINYINT(1) NOT NULL DEFAULT 1');
            }
        }

        if (Schema::hasTable('restaurant_combos') && Schema::hasColumn('restaurant_combos', 'price_tax_inclusive')) {
            DB::table('restaurant_combos')->update(['price_tax_inclusive' => true]);
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE restaurant_combos MODIFY price_tax_inclusive TINYINT(1) NOT NULL DEFAULT 1');
            }
        }

        if (Schema::hasTable('pos_orders') && Schema::hasColumn('pos_orders', 'prices_tax_inclusive')) {
            DB::table('pos_orders')->update(['prices_tax_inclusive' => true]);
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE pos_orders MODIFY prices_tax_inclusive TINYINT(1) NOT NULL DEFAULT 1');
            }
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (Schema::hasTable('restaurant_menu_items') && Schema::hasColumn('restaurant_menu_items', 'price_tax_inclusive') && $driver === 'mysql') {
            DB::statement('ALTER TABLE restaurant_menu_items MODIFY price_tax_inclusive TINYINT(1) NOT NULL DEFAULT 0');
        }

        if (Schema::hasTable('restaurant_combos') && Schema::hasColumn('restaurant_combos', 'price_tax_inclusive') && $driver === 'mysql') {
            DB::statement('ALTER TABLE restaurant_combos MODIFY price_tax_inclusive TINYINT(1) NOT NULL DEFAULT 0');
        }

        if (Schema::hasTable('pos_orders') && Schema::hasColumn('pos_orders', 'prices_tax_inclusive') && $driver === 'mysql') {
            DB::statement('ALTER TABLE pos_orders MODIFY prices_tax_inclusive TINYINT(1) NOT NULL DEFAULT 0');
        }
    }
};
