<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_items') || ! Schema::hasTable('inventory_taxes')) {
            return;
        }

        $vatTaxId = DB::table('inventory_taxes')
            ->where('type', 'vat')
            ->orderBy('id')
            ->value('id');

        if (! $vatTaxId) {
            return;
        }

        $itemIds = DB::table('inventory_items as ii')
            ->leftJoin('inventory_taxes as it', 'it.id', '=', 'ii.tax_id')
            ->where('ii.is_alcohol', true)
            ->where(function ($q) {
                $q->whereNull('ii.tax_id')
                    ->orWhereNull('it.type')
                    ->orWhere('it.type', '<>', 'vat');
            })
            ->pluck('ii.id');

        if ($itemIds->isEmpty()) {
            return;
        }

        DB::table('inventory_items')
            ->whereIn('id', $itemIds)
            ->update(['tax_id' => $vatTaxId]);
    }

    public function down(): void
    {
        // Data fix — not reversible.
    }
};
