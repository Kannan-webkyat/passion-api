<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE inventory_items MODIFY sku VARCHAR(100) NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (DB::table('inventory_items')->whereNull('sku')->cursor() as $row) {
            DB::table('inventory_items')->where('id', $row->id)->update(['sku' => 'NO-SKU-'.$row->id]);
        }

        DB::statement('ALTER TABLE inventory_items MODIFY sku VARCHAR(100) NOT NULL');
    }
};
