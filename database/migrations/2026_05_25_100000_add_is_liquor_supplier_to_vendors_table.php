<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (! Schema::hasColumn('vendors', 'is_liquor_supplier')) {
                $table->boolean('is_liquor_supplier')->default(false)->after('default_tax_price_basis');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (Schema::hasColumn('vendors', 'is_liquor_supplier')) {
                $table->dropColumn('is_liquor_supplier');
            }
        });
    }
};
