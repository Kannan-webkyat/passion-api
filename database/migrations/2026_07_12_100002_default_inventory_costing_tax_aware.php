<?php

use App\Services\InventoryCostingConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')->updateOrInsert(
            ['key' => 'inventory_costing_mode'],
            [
                'value' => InventoryCostingConfig::MODE_TAX_AWARE,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->where('key', 'inventory_costing_mode')
            ->update(['value' => InventoryCostingConfig::MODE_EXCLUSIVE_ONLY]);
    }
};
