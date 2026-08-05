<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_day_closings', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_day_closings', 'total_refunded')) {
                $table->decimal('total_refunded', 12, 2)->default(0)->after('total_tip');
            }
            if (! Schema::hasColumn('pos_day_closings', 'gst_net_taxable')) {
                $table->decimal('gst_net_taxable', 12, 2)->default(0)->after('total_refunded');
            }
            if (! Schema::hasColumn('pos_day_closings', 'vat_net_taxable')) {
                $table->decimal('vat_net_taxable', 12, 2)->default(0)->after('gst_net_taxable');
            }
            if (! Schema::hasColumn('pos_day_closings', 'cgst_amount')) {
                $table->decimal('cgst_amount', 12, 2)->default(0)->after('vat_net_taxable');
            }
            if (! Schema::hasColumn('pos_day_closings', 'sgst_amount')) {
                $table->decimal('sgst_amount', 12, 2)->default(0)->after('cgst_amount');
            }
            if (! Schema::hasColumn('pos_day_closings', 'igst_amount')) {
                $table->decimal('igst_amount', 12, 2)->default(0)->after('sgst_amount');
            }
            if (! Schema::hasColumn('pos_day_closings', 'vat_tax_amount')) {
                $table->decimal('vat_tax_amount', 12, 2)->default(0)->after('igst_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_day_closings', function (Blueprint $table) {
            foreach ([
                'total_refunded',
                'gst_net_taxable',
                'vat_net_taxable',
                'cgst_amount',
                'sgst_amount',
                'igst_amount',
                'vat_tax_amount',
            ] as $col) {
                if (Schema::hasColumn('pos_day_closings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
