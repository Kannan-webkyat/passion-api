<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_masters', function (Blueprint $table) {
            if (! Schema::hasColumn('restaurant_masters', 'prices_tax_inclusive')) {
                $table->boolean('prices_tax_inclusive')->default(false)->after('bill_round_to_nearest_rupee');
            }
            if (! Schema::hasColumn('restaurant_masters', 'receipt_show_tax_breakdown')) {
                $table->boolean('receipt_show_tax_breakdown')->default(true)->after('prices_tax_inclusive');
            }
        });

        Schema::table('pos_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_orders', 'prices_tax_inclusive')) {
                $table->boolean('prices_tax_inclusive')->default(false)->after('tax_exempt');
            }
            if (! Schema::hasColumn('pos_orders', 'receipt_show_tax_breakdown')) {
                $table->boolean('receipt_show_tax_breakdown')->default(true)->after('prices_tax_inclusive');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            if (Schema::hasColumn('pos_orders', 'receipt_show_tax_breakdown')) {
                $table->dropColumn('receipt_show_tax_breakdown');
            }
            if (Schema::hasColumn('pos_orders', 'prices_tax_inclusive')) {
                $table->dropColumn('prices_tax_inclusive');
            }
        });

        Schema::table('restaurant_masters', function (Blueprint $table) {
            if (Schema::hasColumn('restaurant_masters', 'receipt_show_tax_breakdown')) {
                $table->dropColumn('receipt_show_tax_breakdown');
            }
            if (Schema::hasColumn('restaurant_masters', 'prices_tax_inclusive')) {
                $table->dropColumn('prices_tax_inclusive');
            }
        });
    }
};
