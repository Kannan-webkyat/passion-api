<?php

use App\Models\Combo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table) {
            $table->string('tax_regime', 32)->nullable()->after('tax_rate');
        });

        Schema::table('pos_orders', function (Blueprint $table) {
            $table->decimal('cgst_amount', 14, 2)->default(0)->after('tax_amount');
            $table->decimal('sgst_amount', 14, 2)->default(0)->after('cgst_amount');
            $table->decimal('igst_amount', 14, 2)->default(0)->after('sgst_amount');
            $table->decimal('vat_tax_amount', 14, 2)->default(0)->after('igst_amount');
            $table->decimal('gst_net_taxable', 14, 2)->default(0)->after('vat_tax_amount');
            $table->decimal('vat_net_taxable', 14, 2)->default(0)->after('gst_net_taxable');
        });

        if (Schema::hasTable('pos_order_items') && Schema::hasTable('menu_items')) {
            DB::statement("
                UPDATE pos_order_items poi
                INNER JOIN menu_items mi ON poi.menu_item_id = mi.id
                LEFT JOIN inventory_taxes it ON mi.tax_id = it.id
                SET poi.tax_regime = IF(LOWER(COALESCE(it.type, 'local')) = 'vat', 'vat_liquor', 'gst')
                WHERE poi.menu_item_id IS NOT NULL AND poi.combo_id IS NULL
            ");
        }

        if (Schema::hasTable('pos_order_items')) {
            $comboIds = DB::table('pos_order_items')
                ->whereNotNull('combo_id')
                ->distinct()
                ->pluck('combo_id');
            foreach ($comboIds as $cid) {
                if (! $cid) {
                    continue;
                }
                $combo = Combo::with('menuItems.tax')->find($cid);
                if (! $combo) {
                    continue;
                }
                $reg = 'vat_liquor';
                foreach ($combo->menuItems as $mi) {
                    $t = strtolower((string) ($mi->tax?->type ?? 'local'));
                    if ($t !== 'vat') {
                        $reg = 'gst';
                        break;
                    }
                }
                if ($combo->menuItems->isEmpty()) {
                    $reg = 'gst';
                }
                DB::table('pos_order_items')->where('combo_id', $cid)->update(['tax_regime' => $reg]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table) {
            $table->dropColumn('tax_regime');
        });

        Schema::table('pos_orders', function (Blueprint $table) {
            $table->dropColumn([
                'cgst_amount', 'sgst_amount', 'igst_amount', 'vat_tax_amount',
                'gst_net_taxable', 'vat_net_taxable',
            ]);
        });
    }
};
