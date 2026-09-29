<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('vendors')) {
            return;
        }

        DB::table('vendors')
            ->where('is_liquor_supplier', true)
            ->update([
                'default_tax_price_basis' => 'non_taxable',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('vendors')) {
            return;
        }

        DB::table('vendors')
            ->where('is_liquor_supplier', true)
            ->update([
                'default_tax_price_basis' => 'tax_inclusive',
                'updated_at' => now(),
            ]);
    }
};
