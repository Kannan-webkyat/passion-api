<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grn_items', function (Blueprint $table) {
            if (! Schema::hasColumn('grn_items', 'non_recoverable_tax_unit_cost')) {
                $table->decimal('non_recoverable_tax_unit_cost', 15, 4)
                    ->nullable()
                    ->after('freight_unit_cost');
            }
            if (! Schema::hasColumn('grn_items', 'line_recoverable_tax_accepted')) {
                $table->decimal('line_recoverable_tax_accepted', 15, 2)
                    ->nullable()
                    ->after('line_tax_accepted');
            }
            if (! Schema::hasColumn('grn_items', 'line_non_recoverable_tax_accepted')) {
                $table->decimal('line_non_recoverable_tax_accepted', 15, 2)
                    ->nullable()
                    ->after('line_recoverable_tax_accepted');
            }
            if (! Schema::hasColumn('grn_items', 'tax_input_credit_eligible')) {
                $table->boolean('tax_input_credit_eligible')
                    ->nullable()
                    ->after('line_non_recoverable_tax_accepted');
            }
        });
    }

    public function down(): void
    {
        Schema::table('grn_items', function (Blueprint $table) {
            $cols = array_filter([
                Schema::hasColumn('grn_items', 'non_recoverable_tax_unit_cost') ? 'non_recoverable_tax_unit_cost' : null,
                Schema::hasColumn('grn_items', 'line_recoverable_tax_accepted') ? 'line_recoverable_tax_accepted' : null,
                Schema::hasColumn('grn_items', 'line_non_recoverable_tax_accepted') ? 'line_non_recoverable_tax_accepted' : null,
                Schema::hasColumn('grn_items', 'tax_input_credit_eligible') ? 'tax_input_credit_eligible' : null,
            ]);
            if ($cols) {
                $table->dropColumn($cols);
            }
        });
    }
};
