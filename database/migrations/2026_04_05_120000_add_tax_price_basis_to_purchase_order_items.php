<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_order_items', 'tax_price_basis')) {
                $table->string('tax_price_basis', 32)
                    ->default('tax_exclusive')
                    ->after('unit_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_order_items', 'tax_price_basis')) {
                $table->dropColumn('tax_price_basis');
            }
        });
    }
};
