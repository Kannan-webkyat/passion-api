<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_items')) {
            return;
        }

        DB::table('inventory_items')
            ->where('is_alcohol', true)
            ->whereNotNull('tax_id')
            ->update([
                'tax_id' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // No automatic restore — tax_id was optional metadata only.
    }
};
