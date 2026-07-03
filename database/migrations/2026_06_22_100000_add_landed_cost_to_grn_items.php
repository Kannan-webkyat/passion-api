<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grn_items', function (Blueprint $table) {
            if (! Schema::hasColumn('grn_items', 'line_cess_accepted')) {
                $table->decimal('line_cess_accepted', 15, 2)->default(0)->after('line_tax_accepted');
            }
            if (! Schema::hasColumn('grn_items', 'line_freight_allocated')) {
                $table->decimal('line_freight_allocated', 15, 2)->default(0)->after('line_cess_accepted');
            }
            if (! Schema::hasColumn('grn_items', 'landed_unit_cost')) {
                $table->decimal('landed_unit_cost', 15, 4)->default(0)->after('line_freight_allocated');
            }
        });
    }

    public function down(): void
    {
        Schema::table('grn_items', function (Blueprint $table) {
            $cols = array_filter([
                Schema::hasColumn('grn_items', 'line_cess_accepted') ? 'line_cess_accepted' : null,
                Schema::hasColumn('grn_items', 'line_freight_allocated') ? 'line_freight_allocated' : null,
                Schema::hasColumn('grn_items', 'landed_unit_cost') ? 'landed_unit_cost' : null,
            ]);
            if ($cols) {
                $table->dropColumn($cols);
            }
        });
    }
};
