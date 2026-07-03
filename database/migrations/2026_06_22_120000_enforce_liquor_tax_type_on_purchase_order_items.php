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

        DB::statement("
            ALTER TABLE purchase_order_items
            MODIFY tax_type ENUM('gst', 'vat') NOT NULL DEFAULT 'gst'
        ");

        DB::unprepared('DROP TRIGGER IF EXISTS purchase_order_items_liquor_tax_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_order_items_liquor_tax_update');

        DB::unprepared("
            CREATE TRIGGER purchase_order_items_liquor_tax_insert
            BEFORE INSERT ON purchase_order_items
            FOR EACH ROW
            BEGIN
                DECLARE item_is_alcohol TINYINT(1) DEFAULT 0;
                SELECT COALESCE(is_alcohol, 0) INTO item_is_alcohol
                FROM inventory_items
                WHERE id = NEW.inventory_item_id
                LIMIT 1;
                IF item_is_alcohol = 1 AND NEW.tax_type <> 'vat' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Liquor items must use VAT. Please select a VAT tax rate.';
                END IF;
                IF item_is_alcohol = 0 AND NEW.tax_type = 'vat' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Non-liquor items cannot use VAT. Please select a GST tax rate.';
                END IF;
            END
        ");

        DB::unprepared("
            CREATE TRIGGER purchase_order_items_liquor_tax_update
            BEFORE UPDATE ON purchase_order_items
            FOR EACH ROW
            BEGIN
                DECLARE item_is_alcohol TINYINT(1) DEFAULT 0;
                SELECT COALESCE(is_alcohol, 0) INTO item_is_alcohol
                FROM inventory_items
                WHERE id = NEW.inventory_item_id
                LIMIT 1;
                IF item_is_alcohol = 1 AND NEW.tax_type <> 'vat' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Liquor items must use VAT. Please select a VAT tax rate.';
                END IF;
                IF item_is_alcohol = 0 AND NEW.tax_type = 'vat' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Non-liquor items cannot use VAT. Please select a GST tax rate.';
                END IF;
            END
        ");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS purchase_order_items_liquor_tax_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_order_items_liquor_tax_update');

        if (Schema::hasColumn('purchase_order_items', 'tax_type')) {
            DB::statement("
                ALTER TABLE purchase_order_items
                MODIFY tax_type VARCHAR(10) NOT NULL DEFAULT 'gst'
            ");
        }
    }
};
