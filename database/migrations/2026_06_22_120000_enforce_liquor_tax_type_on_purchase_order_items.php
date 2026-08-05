<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('purchase_order_items', 'tax_type')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        // Normalize existing rows before tightening to ENUM (no DB triggers — those require
        // SUPER / log_bin_trust_function_creators on replicated production MySQL).
        // Runtime enforcement: App\Services\LiquorTaxValidator via PurchaseOrderService.
        if (Schema::hasTable('inventory_items')) {
            DB::statement("
                UPDATE purchase_order_items poi
                INNER JOIN inventory_items ii ON ii.id = poi.inventory_item_id
                SET poi.tax_type = CASE
                    WHEN COALESCE(ii.is_alcohol, 0) = 1 THEN 'vat'
                    ELSE 'gst'
                END
            ");
        }

        DB::statement("
            ALTER TABLE purchase_order_items
            MODIFY tax_type ENUM('gst', 'vat') NOT NULL DEFAULT 'gst'
        ");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasColumn('purchase_order_items', 'tax_type')) {
            DB::statement("
                ALTER TABLE purchase_order_items
                MODIFY tax_type VARCHAR(10) NOT NULL DEFAULT 'gst'
            ");
        }
    }
};
