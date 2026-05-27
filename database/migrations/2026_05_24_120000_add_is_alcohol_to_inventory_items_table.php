<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_items', 'is_alcohol')) {
                $table->boolean('is_alcohol')->default(false)->after('is_prepared_item');
            }
        });

        DB::table('inventory_items')
            ->where(function ($q) {
                $q->where('is_cess_applicable', true)
                    ->orWhereNotNull('liquor_category')
                    ->orWhereIn('sku', ['FB-BR-KF1', 'FB-BR-BR1', 'MB_ALCOHOL_MINIATURE']);
            })
            ->update(['is_alcohol' => true]);
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_items', 'is_alcohol')) {
                $table->dropColumn('is_alcohol');
            }
        });
    }
};
