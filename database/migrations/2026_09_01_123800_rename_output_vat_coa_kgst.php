<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('chart_of_accounts')) {
            return;
        }

        DB::table('chart_of_accounts')
            ->where('code', '2213')
            ->update([
                'name' => 'Legacy liquor output VAT (pre-KGST per-sale)',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('chart_of_accounts')) {
            return;
        }

        DB::table('chart_of_accounts')
            ->where('code', '2213')
            ->update([
                'name' => 'Output VAT Payable — Liquor',
                'updated_at' => now(),
            ]);
    }
};
