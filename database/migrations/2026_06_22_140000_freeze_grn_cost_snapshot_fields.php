<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grns', function (Blueprint $table) {
            if (! Schema::hasColumn('grns', 'inventory_costing_mode')) {
                $table->string('inventory_costing_mode', 20)->nullable()->after('approved_at');
            }
        });

        Schema::table('grn_items', function (Blueprint $table) {
            if (! Schema::hasColumn('grn_items', 'merchandise_unit_cost')) {
                $table->decimal('merchandise_unit_cost', 15, 4)->nullable()->after('line_freight_allocated');
            }
            if (! Schema::hasColumn('grn_items', 'cess_unit_cost')) {
                $table->decimal('cess_unit_cost', 15, 4)->nullable()->after('merchandise_unit_cost');
            }
            if (! Schema::hasColumn('grn_items', 'freight_unit_cost')) {
                $table->decimal('freight_unit_cost', 15, 4)->nullable()->after('cess_unit_cost');
            }
        });
    }

    public function down(): void
    {
        Schema::table('grn_items', function (Blueprint $table) {
            $cols = array_filter([
                Schema::hasColumn('grn_items', 'merchandise_unit_cost') ? 'merchandise_unit_cost' : null,
                Schema::hasColumn('grn_items', 'cess_unit_cost') ? 'cess_unit_cost' : null,
                Schema::hasColumn('grn_items', 'freight_unit_cost') ? 'freight_unit_cost' : null,
            ]);
            if ($cols) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('grns', function (Blueprint $table) {
            if (Schema::hasColumn('grns', 'inventory_costing_mode')) {
                $table->dropColumn('inventory_costing_mode');
            }
        });
    }
};
