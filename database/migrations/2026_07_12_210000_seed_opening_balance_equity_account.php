<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('chart_of_accounts')->updateOrInsert(
            ['code' => '3900'],
            [
                'name' => 'Opening Balance Equity',
                'type' => 'equity',
                'parent_code' => null,
                'is_posting' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        DB::table('chart_of_accounts')->where('code', '3900')->delete();
    }
};
