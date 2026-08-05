<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_order_items', 'tax_type')) {
                $table->string('tax_type', 10)->default('gst')->after('tax_rate');
            }
        });

        if (Schema::hasTable('inventory_items') && Schema::hasTable('inventory_taxes')) {
            DB::statement("
                UPDATE purchase_order_items poi
                INNER JOIN inventory_items ii ON ii.id = poi.inventory_item_id
                LEFT JOIN inventory_taxes it ON it.id = ii.tax_id
                SET poi.tax_type = CASE
                    WHEN LOWER(COALESCE(it.type, '')) = 'vat' THEN 'vat'
                    ELSE 'gst'
                END
            ");
        }
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_order_items', 'tax_type')) {
                $table->dropColumn('tax_type');
            }
        });
    }
};
