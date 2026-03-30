<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_menu_items', function (Blueprint $table) {
            if (! Schema::hasColumn('restaurant_menu_items', 'price_tax_inclusive')) {
                $table->boolean('price_tax_inclusive')->default(false)->after('is_active');
            }
        });

        Schema::table('restaurant_combos', function (Blueprint $table) {
            if (! Schema::hasColumn('restaurant_combos', 'price_tax_inclusive')) {
                $table->boolean('price_tax_inclusive')->default(false)->after('is_active');
            }
        });

        Schema::table('pos_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_order_items', 'price_tax_inclusive')) {
                $table->boolean('price_tax_inclusive')->nullable()->after('tax_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table) {
            if (Schema::hasColumn('pos_order_items', 'price_tax_inclusive')) {
                $table->dropColumn('price_tax_inclusive');
            }
        });

        Schema::table('restaurant_combos', function (Blueprint $table) {
            if (Schema::hasColumn('restaurant_combos', 'price_tax_inclusive')) {
                $table->dropColumn('price_tax_inclusive');
            }
        });

        Schema::table('restaurant_menu_items', function (Blueprint $table) {
            if (Schema::hasColumn('restaurant_menu_items', 'price_tax_inclusive')) {
                $table->dropColumn('price_tax_inclusive');
            }
        });
    }
};
