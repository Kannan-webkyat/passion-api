<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cess_slabs', function (Blueprint $table) {
            $table->id();
            $table->string('item_category', 32); // e.g. imfl, beer, wine
            $table->decimal('min_mrp', 15, 2);
            $table->decimal('max_mrp', 15, 2);
            $table->decimal('flat_cess_amount', 15, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['item_category', 'is_active']);
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_items', 'is_cess_applicable')) {
                $table->boolean('is_cess_applicable')->default(false)->after('is_prepared_item');
            }
            if (! Schema::hasColumn('inventory_items', 'cess_amount')) {
                $table->decimal('cess_amount', 15, 2)->nullable()->after('is_cess_applicable');
            }
            if (! Schema::hasColumn('inventory_items', 'liquor_category')) {
                $table->string('liquor_category', 32)->nullable()->after('cess_amount');
            }
            if (! Schema::hasColumn('inventory_items', 'mrp')) {
                $table->decimal('mrp', 15, 2)->nullable()->after('liquor_category');
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_orders', 'total_cess_amount')) {
                $table->decimal('total_cess_amount', 15, 2)->default(0)->after('tax_amount');
            }
            if (! Schema::hasColumn('purchase_orders', 'transportation_charge')) {
                $table->decimal('transportation_charge', 15, 2)->default(0)->after('total_cess_amount');
            }
            if (! Schema::hasColumn('purchase_orders', 'loading_unloading_charge')) {
                $table->decimal('loading_unloading_charge', 15, 2)->default(0)->after('transportation_charge');
            }
            if (! Schema::hasColumn('purchase_orders', 'grand_total_payable')) {
                $table->decimal('grand_total_payable', 15, 2)->default(0)->after('total_amount');
            }
            if (! Schema::hasColumn('purchase_orders', 'tds_amount')) {
                $table->decimal('tds_amount', 15, 2)->nullable()->after('grand_total_payable');
            }
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_order_items', 'unit_cess')) {
                $table->decimal('unit_cess', 15, 2)->default(0)->after('tax_amount');
            }
            if (! Schema::hasColumn('purchase_order_items', 'total_cess')) {
                $table->decimal('total_cess', 15, 2)->default(0)->after('unit_cess');
            }
            if (! Schema::hasColumn('purchase_order_items', 'quantity_damaged_transit')) {
                $table->unsignedInteger('quantity_damaged_transit')->default(0)->after('quantity_received');
            }
            if (! Schema::hasColumn('purchase_order_items', 'quantity_broken_transit')) {
                $table->unsignedInteger('quantity_broken_transit')->default(0)->after('quantity_damaged_transit');
            }
        });

        if (Schema::hasTable('inventory_transactions')) {
            DB::statement("ALTER TABLE inventory_transactions MODIFY COLUMN type ENUM('in', 'out', 'adjustment', 'loss') NOT NULL");
        }

        if (Schema::hasColumn('purchase_orders', 'grand_total_payable')) {
            DB::table('purchase_orders')
                ->where(function ($q) {
                    $q->whereNull('grand_total_payable')->orWhere('grand_total_payable', 0);
                })
                ->update(['grand_total_payable' => DB::raw('total_amount')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_transactions')) {
            DB::statement("ALTER TABLE inventory_transactions MODIFY COLUMN type ENUM('in', 'out', 'adjustment') NOT NULL");
        }

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $cols = array_filter([
                Schema::hasColumn('purchase_order_items', 'unit_cess') ? 'unit_cess' : null,
                Schema::hasColumn('purchase_order_items', 'total_cess') ? 'total_cess' : null,
                Schema::hasColumn('purchase_order_items', 'quantity_damaged_transit') ? 'quantity_damaged_transit' : null,
                Schema::hasColumn('purchase_order_items', 'quantity_broken_transit') ? 'quantity_broken_transit' : null,
            ]);
            if ($cols) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $cols = array_filter([
                Schema::hasColumn('purchase_orders', 'total_cess_amount') ? 'total_cess_amount' : null,
                Schema::hasColumn('purchase_orders', 'transportation_charge') ? 'transportation_charge' : null,
                Schema::hasColumn('purchase_orders', 'loading_unloading_charge') ? 'loading_unloading_charge' : null,
                Schema::hasColumn('purchase_orders', 'grand_total_payable') ? 'grand_total_payable' : null,
                Schema::hasColumn('purchase_orders', 'tds_amount') ? 'tds_amount' : null,
            ]);
            if ($cols) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $cols = array_filter([
                Schema::hasColumn('inventory_items', 'is_cess_applicable') ? 'is_cess_applicable' : null,
                Schema::hasColumn('inventory_items', 'cess_amount') ? 'cess_amount' : null,
                Schema::hasColumn('inventory_items', 'liquor_category') ? 'liquor_category' : null,
                Schema::hasColumn('inventory_items', 'mrp') ? 'mrp' : null,
            ]);
            if ($cols) {
                $table->dropColumn($cols);
            }
        });

        Schema::dropIfExists('cess_slabs');
    }
};
