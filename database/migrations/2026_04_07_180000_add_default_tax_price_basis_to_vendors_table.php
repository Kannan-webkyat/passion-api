<?php

use App\Services\PurchaseOrderLineAmounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (! Schema::hasColumn('vendors', 'default_tax_price_basis')) {
                $table->string('default_tax_price_basis')
                    ->default(PurchaseOrderLineAmounts::BASIS_EXCLUSIVE)
                    ->after('is_registered_dealer');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (Schema::hasColumn('vendors', 'default_tax_price_basis')) {
                $table->dropColumn('default_tax_price_basis');
            }
        });
    }
};

