<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->foreignId('tax_id')->nullable()->after('price')->constrained('inventory_taxes')->nullOnDelete();
        });

        // Migrate existing tax_rate to tax_id: find matching tax by rate, default to GST 5%
        $defaultTaxId = DB::table('inventory_taxes')->where('rate', 5)->value('id');
        if ($defaultTaxId) {
            $items = DB::table('menu_items')->get();
            foreach ($items as $item) {
                $rate = $item->tax_rate ?? 5;
                $taxId = DB::table('inventory_taxes')->where('rate', $rate)->value('id') ?? $defaultTaxId;
                DB::table('menu_items')->where('id', $item->id)->update(['tax_id' => $taxId]);
            }
        }

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn('tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->default(5)->after('price');
        });

        $items = DB::table('menu_items')->join('inventory_taxes', 'menu_items.tax_id', '=', 'inventory_taxes.id')->select('menu_items.id', 'inventory_taxes.rate')->get();
        foreach ($items as $item) {
            DB::table('menu_items')->where('id', $item->id)->update(['tax_rate' => $item->rate]);
        }

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropForeign(['tax_id']);
            $table->dropColumn('tax_id');
        });
    }
};
