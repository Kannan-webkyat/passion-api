<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('settings')->where('key', 'bom_stock_enforcement')->exists();
        if ($exists) {
            return;
        }

        DB::table('settings')->insert([
            'key' => 'bom_stock_enforcement',
            'value' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'bom_stock_enforcement')->delete();
    }
};
