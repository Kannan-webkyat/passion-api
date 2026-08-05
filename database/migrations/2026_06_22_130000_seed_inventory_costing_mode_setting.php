<?php

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

        $exists = DB::table('settings')->where('key', 'inventory_costing_mode')->exists();
        if ($exists) {
            return;
        }

        DB::table('settings')->insert([
            'key' => 'inventory_costing_mode',
            'value' => 'exclusive_only',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')->where('key', 'inventory_costing_mode')->delete();
    }
};
